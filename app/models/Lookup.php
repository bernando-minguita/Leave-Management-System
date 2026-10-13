<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Small lookup models: branches, agencies, leave types, settings, users.
 * Each one follows the same pattern - list, find, active pairs, save.
 */
final class Lookup
{
    /**
     * Shipped Destination Country list, exactly as it is stored in settings.
     *
     * settings.setting_value is VARCHAR(500) and saveSettings() truncates at
     * 500 characters, so this has to fit: 37 countries in ~320. Widen the
     * column before adding more - and note the value is the country NAME,
     * never a slug, which is what keeps the destination filter, the exports
     * and every row written before this list existed working untouched.
     */
    public const DESTINATION_COUNTRIES =
        'Saudi Arabia,United Arab Emirates,Qatar,Kuwait,Bahrain,Oman,Jordan,Iraq,'
        . 'Lebanon,Egypt,Sudan,Yemen,India,Pakistan,Nepal,Bangladesh,Sri Lanka,'
        . 'Philippines,Indonesia,Malaysia,Thailand,China,Japan,South Korea,'
        . 'Turkey,Kenya,Ethiopia,Nigeria,South Africa,United Kingdom,'
        . 'United States,Canada,Australia,Germany,France,Italy,Spain';

    /* ------------------------------------------------------- branches --- */

    /** @return array<int, array<string, mixed>> */
    public static function branches(bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM branches'
            . ($activeOnly ? ' WHERE status = "active"' : '')
            . ' ORDER BY branch_name';

        return Database::all($sql);
    }

    /** @return array<int, string> id => name (for <select> controls) */
    public static function branchPairs(bool $activeOnly = true): array
    {
        $pairs = [];
        foreach (self::branches($activeOnly) as $row) {
            $pairs[(int)$row['id']] = (string)$row['branch_name'];
        }

        return $pairs;
    }

    public static function branch(int $id): ?array
    {
        return Database::one('SELECT * FROM branches WHERE id = ? LIMIT 1', [$id]);
    }

    /* -------------------------------------------------------- agencies --- */

    /** @return array<int, array<string, mixed>> */
    public static function agencies(bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM agencies'
            . ($activeOnly ? ' WHERE status = "active"' : '')
            . ' ORDER BY agency_name';

        return Database::all($sql);
    }

    /** @return array<int, string> */
    public static function agencyPairs(bool $activeOnly = true): array
    {
        $pairs = [];
        foreach (self::agencies($activeOnly) as $row) {
            $pairs[(int)$row['id']] = (string)$row['agency_name'];
        }

        return $pairs;
    }

    public static function agency(int $id): ?array
    {
        return Database::one('SELECT * FROM agencies WHERE id = ? LIMIT 1', [$id]);
    }

    /* ----------------------------------------------------- leave types --- */

    /** @return array<int, array<string, mixed>> */
    public static function leaveTypes(bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM leave_types'
            . ($activeOnly ? ' WHERE status = "active"' : '')
            . ' ORDER BY name';

        return Database::all($sql);
    }

    /** @return array<int, string> */
    public static function leaveTypePairs(bool $activeOnly = true): array
    {
        $pairs = [];
        foreach (self::leaveTypes($activeOnly) as $row) {
            $pairs[(int)$row['id']] = (string)$row['name'];
        }

        return $pairs;
    }

    public static function leaveType(int $id): ?array
    {
        return Database::one('SELECT * FROM leave_types WHERE id = ? LIMIT 1', [$id]);
    }

    /* -------------------------------------------------------- settings --- */

    /**
     * Active drivers for a select box: id => "Name (Badge)".
     *
     * @return array<int, string>
     */
    public static function driverPairs(): array
    {
        $pairs = [];

        foreach (Database::all(
            'SELECT id, driver_name, badge_number
               FROM drivers d
              WHERE ' . Driver::activeOnSql('d') . '
           ORDER BY driver_name',
            [today()]
        ) as $row) {
            $pairs[(int) $row['id']] = $row['driver_name'] . ' (' . $row['badge_number'] . ')';
        }

        return $pairs;
    }

    /** @var array<string, string>|null Memoised for the request; see forgetSettings(). */
    private static ?array $settingsCache = null;

    /**
     * All settings as key => value (cached for the request).
     *
     * @return array<string, string>
     */
    public static function settings(): array
    {
        if (self::$settingsCache !== null) {
            return self::$settingsCache;
        }

        self::$settingsCache = [];

        try {
            foreach (Database::all('SELECT setting_key, setting_value FROM settings') as $row) {
                self::$settingsCache[(string)$row['setting_key']] = (string)$row['setting_value'];
            }
        } catch (\Throwable $ex) {
            log_fatal('settings load failed: ' . $ex->getMessage());
        }

        return self::$settingsCache;
    }

    /**
     * Drop the memoised settings so the next read hits the database.
     *
     * WHY. The option-list guard in SettingsController reads the CURRENT lists
     * through this class, and a save writes them in the same request. Without
     * this, a save followed by a read would still answer from the cache - which
     * is exactly the comparison that decides whether a removal is allowed.
     * Mirrors forget_permissions().
     */
    public static function forgetSettings(): void
    {
        self::$settingsCache = null;
    }

    public static function setting(string $key, string $default = ''): string
    {
        $all = self::settings();

        return $all[$key] ?? $default;
    }

    /* --------------------------------------------- configurable lists --- */

    /**
     * Parse a Settings option list: "value|Label" pairs, comma separated.
     *
     * The VALUE (left of |) is what the column stores - drivers.status and
     * drivers.operational_status keep slugs like "active" - while the Label
     * (right of |) is what every screen shows, so a label can be reworded
     * without rewriting stored rows. A bare "value" without | uses itself as
     * the label. A missing/empty setting falls back to $default so a fresh
     * install and a database whose seed has not run yet both behave.
     *
     * @return array<string, string> value => label (insertion ordered)
     */
    public static function optionList(string $settingKey, string $default = ''): array
    {
        $raw = self::setting($settingKey, '');
        if ($raw === '') {
            $raw = $default;
        }

        $errors = [];
        $pairs  = self::parseOptionListString($raw, $errors);

        // An edited-away-to-nothing list would break every screen that shows
        // one, so fall back to the documented default rather than nothing.
        // (Only when the empty parse came from the stored value - never retry
        // a default that itself parses to nothing.)
        if ($pairs === [] && $default !== '' && $raw !== $default) {
            return self::optionList($settingKey . '.fallback', $default);
        }

        return $pairs;
    }

    /**
     * Parse one raw "value|Label, value|Label" string with validation.
     *
     * Used both for reading (errors ignored) and for Settings -> Save, where
     * a rejected list must not reach the database - every status dropdown in
     * the app renders from these rows.
     *
     * @param array<int, string> $errors filled with human-readable problems
     * @return array<string, string> value => label
     */
    public static function parseOptionListString(string $raw, ?array &$errors = null): array
    {
        $errors = [];
        $pairs  = [];
        $seen   = [];
        $lineNo = 0;

        // Commas AND line breaks (or tabs) separate: pasting a column out of a
        // spreadsheet is one option per line, and treating the whole paste as a
        // single chunk would turn it into one malformed entry.
        foreach (preg_split('/[\r\n\t,]+/', $raw) ?: [] as $chunk) {
            $lineNo++;
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue; // trailing comma / spacing noise
            }

            // A control character that survived the split was never intended:
            // refuse it rather than store it inside a slug or a label.
            if (preg_match('/[\x00-\x1F\x7F]/', $chunk)) {
                $errors[] = 'Option ' . $lineNo . ' contains a control character - check what was pasted.';
                continue;
            }

            if (str_contains($chunk, '|')) {
                [$value, $label] = array_map('trim', explode('|', $chunk, 2));
            } else {
                $value = $label = $chunk;
            }

            // Values become drivers.status / drivers.operational_status values
            // (VARCHAR(30)), so they must be short lowercase slugs.
            if (!preg_match('/^[a-z0-9_]{1,30}$/', $value)) {
                $errors[] = 'Option ' . $lineNo . ' ("' . $chunk . '") must start with a'
                    . ' lowercase letter or digit and may use a-z, 0-9 and _ (max 30 chars).';
                continue;
            }

            if (mb_strlen($label) > 60) {
                $errors[] = 'The label for "' . $value . '" is too long (max 60 characters).';
                continue;
            }

            if (isset($seen[$value])) {
                $errors[] = 'The value "' . $value . '" appears more than once.';
                continue;
            }

            $seen[$value] = true;
            $pairs[$value] = $label !== '' ? $label : $value;
        }

        if ($pairs === [] && $errors === []) {
            $errors[] = 'Add at least one option, as value|Label pairs separated by commas.';
        }

        return $pairs;
    }

    /**
     * Plain comma-separated list: the sibling of parseOptionListString() for
     * the lists whose value IS its label.
     *
     * The pair syntax does not fit a country - there is no slug to translate
     * back, and a stored "saudi arabia" would be wrong in every table. So a
     * segment is taken verbatim, and a stray `|` is refused rather than
     * silently swallowed, because it means someone pasted the wrong list in.
     *
     * Empty segments are skipped rather than rejected: a trailing comma is a
     * typing habit, not a mistake worth failing a save over.
     *
     * @return array<int, string>
     */
    public static function parsePlainList(string $raw, ?array &$errors = null): array
    {
        $errors = [];
        $out    = [];
        $seen   = [];

        // The same separators as the pairs parser: commas, line breaks and
        // tabs. A column pasted out of a spreadsheet is one country per line,
        // and it used to be swallowed as a single name with newlines inside it.
        foreach (preg_split('/[\r\n\t,]+/', $raw) ?: [] as $part) {
            $name = trim($part);

            if ($name === '') {
                continue;
            }

            if (preg_match('/[\x00-\x1F\x7F]/', $name)) {
                $errors[] = '"' . mb_substr($name, 0, 30) . '" contains a control'
                    . ' character - check what was pasted.';
                continue;
            }

            if (str_contains($name, '|')) {
                $errors[] = '"' . $name . '" is a value|Label pair. List each '
                    . 'name on its own, separated by commas.';
                continue;
            }

            // destination is VARCHAR(120); refuse here rather than let the
            // column truncate a country to a prefix on save.
            if (mb_strlen($name) > 120) {
                $errors[] = '"' . mb_substr($name, 0, 30) . '..." is longer than '
                    . 'the 120 characters destination can store.';
                continue;
            }

            $key = mb_strtolower($name);

            if (isset($seen[$key])) {
                $errors[] = '"' . $name . '" is listed twice.';
                continue;
            }

            $seen[$key] = true;
            $out[]      = $name;
        }

        if ($out === [] && $errors === []) {
            $errors[] = 'Add at least one name, separated by commas.';
        }

        return $out;
    }

    /**
     * Employment options for the Address Book (drivers.status).
     *
     * "active" MUST stay in the list: a dozen queries count active drivers
     * with status = "active" (KPIs, capacity denominator, leave pickers).
     * saveSettings() refuses a list that drops it.
     *
     * @return array<string, string> value => label
     */
    public static function employmentStatuses(): array
    {
        return self::optionList(
            'employment_statuses',
            'active|Active,resigned|Resigned,terminated|Terminated,inactive|Inactive'
        );
    }

    /** Display label for a stored employment status (unknown slug shows as-is). */
    public static function employmentLabel(string $value): string
    {
        if ($value === '') {
            return '—';
        }

        return self::employmentStatuses()[$value] ?? ucfirst($value);
    }

    /**
     * Operational options for the Address Book (drivers.operational_status).
     *
     * @return array<string, string> value => label
     */
    public static function operationalStatuses(): array
    {
        return self::optionList(
            'operational_statuses',
            'assigned|Assigned,idle|Idle,vacation|Vacation,training|Training,'
            . 'sick_gosi|Sick / GOSI,suspended|Suspended,absconded|Absconded'
        );
    }

    /** Display label for a stored operational status (NULL shows a dash). */
    public static function operationalLabel(string $value): string
    {
        if ($value === '') {
            return '—';
        }

        return self::operationalStatuses()[$value] ?? ucfirst(str_replace('_', ' ', $value));
    }

    /* ---------------------------------------------- destination country - */

    /**
     * Destination Country options for both leave forms - New Leave
     * Application and New Leave / Vacation.
     *
     * Unlike the two Address Book lists this one is PLAIN: no `value|Label`
     * pairs. A country is stored under exactly the name the reader picked,
     * which is what the free-text field it replaces used to write, so the
     * Leave Records destination filter, the three CSV exports, the
     * open-application search and every row already on file keep working
     * with no backfill at all. value === label is the whole point - there is
     * no slug to translate back, and nothing stored that would render as
     * "saudi arabia" in a table.
     *
     * "Not set" is deliberately NOT a member: it is the empty option the form
     * always renders first, and a destination left there stays NULL, which
     * every list already draws as an em dash.
     *
     * The list lives in Settings (key `destination_countries`) so a country
     * can be added without a deploy; the constant above is both the seed and
     * the fallback when that row is missing or edited away to nothing.
     *
     * @return array<int, string> countries in list order
     */
    public static function destinationCountries(): array
    {
        $raw  = (string) self::setting('destination_countries', self::DESTINATION_COUNTRIES);
        $list = self::parsePlainList($raw);

        return $list !== [] ? $list : self::parsePlainList(self::DESTINATION_COUNTRIES);
    }

    /* ----------------------------------------------------- status badge - */

    /** @return array<int, string> */
    public static function nationalities(): array
    {
        $rows = Database::all(
            'SELECT DISTINCT nationality FROM drivers
              WHERE nationality IS NOT NULL AND nationality <> ""
              ORDER BY nationality'
        );

        return array_map(static fn (array $r): string => (string)$r['nationality'], $rows);
    }

    /* ----------------------------------------------------------- users -- */

    /** @return array<int, array<string, mixed>> */
    public static function users(): array
    {
        // NOTE: totp_secret is deliberately NOT selected - administrators see
        // whether 2FA is on, never the secret behind it.
        return Database::all(
            'SELECT id, username, full_name, role, status, last_login_at, created_at,
                    totp_enabled_at, two_factor_required
               FROM users ORDER BY username'
        );
    }

    public static function user(int $id): ?array
    {
        return Database::one(
            'SELECT id, username, full_name, role, status, last_login_at, created_at,
                    totp_enabled_at, two_factor_required
               FROM users WHERE id = ? LIMIT 1',
            [$id]
        );
    }

    /* ------------------------------------------------- lookup helpers --- */

    /**
     * Generic "does this unique column already belong to another row" check
     * used by branches / agencies / leave types.
     */
    public static function codeTaken(string $table, string $column, string $value, ?int $exceptId = null): bool
    {
        if (!in_array($table, ['branches', 'agencies', 'leave_types', 'users'], true)
            || !in_array($column, ['code', 'name', 'branch_name', 'agency_name', 'username'], true)
        ) {
            return false;
        }

        $sql    = 'SELECT COUNT(*) FROM `' . $table . '` WHERE `' . $column . '` = ?';
        $params = [$value];

        if ($exceptId !== null) {
            $sql    .= ' AND id <> ?';
            $params[] = $exceptId;
        }

        return (int)Database::scalar($sql, $params) > 0;
    }
}
