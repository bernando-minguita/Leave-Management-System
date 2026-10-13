<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * CSV / JSON import for the driver address book.
 *
 * The flow is deliberately explicit so nothing is ever silently overwritten:
 *   1. upload + header validation
 *   2. parse & validate every row
 *   3. preview (new / update / error per row)
 *   4. confirm  -> insert or update, with a summary
 */
final class DriverImport
{
    /** Canonical header names we accept (case/spacing insensitive). */
    public const HEADERS = [
        'badge_number', 'driver_name', 'iqama_number', 'passport_number',
        'mobile_number', 'alternate_mobile', 'nationality', 'agency_code',
        'branch_code', 'department', 'position', 'joining_date', 'last_working_date',
        'emergency_contact_name',
        'emergency_contact_number', 'address', 'city', 'remarks', 'status',
        'operational_status',
    ];

    private ?array $branches = null;
    private ?array $agencies = null;

    /** How many agencies this commit had to create (reported in the flash). */
    private static int $agenciesCreated = 0;

    /**
     * Map a raw header row to our field names.
     *
     * A UTF-8 BOM is stripped from the first cell: both our own template
     * download and Excel-produced CSVs carry one, and leaving it in place
     * makes "badge_number" fail validation on an otherwise perfect file.
     *
     * @param array<int, string> $header
     * @return array<int, string|null> column index => field name (null = ignored)
     */
    public static function mapHeader(array $header): array
    {
        $map = [];

        foreach (array_values($header) as $index => $label) {
            $label = (string)$label;

            if ($index === 0) {
                $label = preg_replace('/^\xEF\xBB\xBF/', '', $label) ?? $label;
            }

            $key = strtolower(trim($label));
            $key = str_replace([' ', '-'], '_', $key);

            $map[$index] = in_array($key, self::HEADERS, true) ? $key : null;
        }

        return $map;
    }

    /** Are the required headers present? */
    public static function headersValid(array $header): array
    {
        $mapped  = self::mapHeader($header);
        $present = array_values(array_filter($mapped));

        $missing = array_diff(['badge_number', 'driver_name', 'iqama_number'], $present);

        return [
            'valid'   => $missing === [],
            'missing' => array_values($missing),
            'mapped'  => $present,
        ];
    }

    /**
     * Parse CSV lines into validated row plans.
     *
     * @return array{
     *   rows: array<int, array<string, mixed>>,
     *   errors: array<int, string>,
     *   summary: array<string, int>
     * }
     */
    public static function plan(array $headerRow, array $dataRows): array
    {
        $map    = self::mapHeader($headerRow);
        $rows   = [];
        $errors = [];

        foreach ($dataRows as $lineNo => $cells) {
            $line   = $lineNo + 2; // header is line 1
            $record = [];

            foreach ($map as $index => $field) {
                if ($field === null) {
                    continue;
                }
                $record[$field] = trim((string)($cells[$index] ?? ''));
            }

            if ($record['badge_number'] === '' && $record['driver_name'] === '' && $record['iqama_number'] === '') {
                continue; // fully blank line
            }

            $problem = self::validateRow($record);

            if ($problem !== null) {
                $errors[$line] = $problem;
                continue;
            }

            $existing = self::findExisting($record);

            $record['_line']   = $line;
            $record['_action'] = $existing === null ? 'create' : 'update';
            $record['_id']     = $existing === null ? null : (int)$existing['id'];
            $record['_existing'] = $existing;

            $rows[] = $record;
        }

        $summary = [
            'create' => count(array_filter($rows, static fn (array $r): bool => $r['_action'] === 'create')),
            'update' => count(array_filter($rows, static fn (array $r): bool => $r['_action'] === 'update')),
            'errors' => count($errors),
        ];

        return ['rows' => $rows, 'errors' => $errors, 'summary' => $summary];
    }

    /** @return string|null first problem with the row, or null when valid */
    private static function validateRow(array $row): ?string
    {
        if ($row['badge_number'] === '') {
            return 'Badge number is required.';
        }
        if ($row['driver_name'] === '') {
            return 'Driver name is required.';
        }
        if ($row['iqama_number'] === '') {
            return 'Iqama number is required.';
        }

        $status = $row['status'] ?? '';
        if ($status !== ''
            && !in_array(strtolower($status), array_map('strtolower', array_keys(Lookup::employmentStatuses())), true)
        ) {
            return 'Employment status must be one of: '
                . implode(', ', array_keys(Lookup::employmentStatuses())) . '.';
        }

        // Operational status is optional, but when supplied it must be one of
        // the Settings options (matched case-insensitively on the slug).
        $opStatus = $row['operational_status'] ?? '';
        if ($opStatus !== ''
            && !in_array(strtolower($opStatus), array_map('strtolower', array_keys(Lookup::operationalStatuses())), true)
        ) {
            return 'Operational status must be one of: '
                . implode(', ', array_keys(Lookup::operationalStatuses())) . '.';
        }

        // Last Working Date (the deactivation end date) is optional. When the
        // spreadsheet supplies one it has to be a real date, and a date before
        // the joining date can never be true - same two rules the form uses.
        $lwd = trim((string)($row['last_working_date'] ?? ''));
        if ($lwd !== '') {
            if (strtotime($lwd) === false || preg_match('/^\d{4}-\d{2}-\d{2}$/', date('Y-m-d', (int)strtotime($lwd))) !== 1) {
                return 'Last working date must be a real date (e.g. 2026-10-15).';
            }

            $joining = trim((string)($row['joining_date'] ?? ''));

            if ($joining !== ''
                && strtotime($joining) !== false
                && date('Y-m-d', (int)strtotime($lwd)) < date('Y-m-d', (int)strtotime($joining))
            ) {
                return 'Last working date cannot be before joining date.';
            }
        }

        return null;
    }

    /** Match on badge number first, then iqama number (never both rows). */
    private static function findExisting(array $row): ?array
    {
        if ($row['badge_number'] !== '') {
            $found = Database::one(
                'SELECT id, badge_number, iqama_number, driver_name FROM drivers WHERE badge_number = ? LIMIT 1',
                [$row['badge_number']]
            );
            if ($found !== null) {
                return $found;
            }
        }

        if ($row['iqama_number'] !== '') {
            $found = Database::one(
                'SELECT id, badge_number, iqama_number, driver_name FROM drivers WHERE iqama_number = ? LIMIT 1',
                [$row['iqama_number']]
            );
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Apply a planned import. $mode controls what happens to existing rows:
     *   'skip'    - leave existing drivers untouched (default, never silent)
     *   'update'  - refresh the matching columns of existing drivers
     *
     * @param array<int, array<string, mixed>> $rows prepared by plan()
     * @return array{inserted:int, updated:int, skipped:int}
     */
    public static function commit(array $rows, string $mode = 'skip'): array
    {
        $inserted = 0;
        $updated  = 0;
        $skipped  = 0;

        self::$agenciesCreated = 0;

        foreach ($rows as $row) {
            $payload = self::toRow($row);

            if ($row['_action'] === 'create') {
                unset($payload['status']);
                $payload['status']    = self::normaliseStatus($row['status'] ?? '');
                $payload['created_at'] = now();
                $payload['updated_at'] = now();

                // An active driver has no end date - dropping it here keeps a
                // stray spreadsheet column from holding somebody out of the
                // forecast while their status says they are at work.
                if ($payload['status'] === 'active') {
                    $payload['last_working_date'] = null;
                }

                Database::insert('drivers', $payload);
                $inserted++;
                continue;
            }

            if ($mode !== 'update') {
                $skipped++;
                continue;
            }

            unset($payload['status']);
            Database::update('drivers', $payload, 'id = ?', [(int)$row['_id']]);
            $updated++;
        }

        return [
            'inserted'         => $inserted,
            'updated'          => $updated,
            'skipped'          => $skipped,
            'agencies_created' => self::$agenciesCreated,
        ];
    }

    /**
     * Resolve the agency for an imported row, creating it when unknown.
     *
     * The CSV carries an agency identifier (e.g. "SMASCO") rather than our
     * internal id, and Settings -> Agencies is often still empty on a fresh
     * install. Matching on code *or* name means an agency that already exists
     * under a short code for the same body is reused instead of duplicated;
     * only a genuinely new one is inserted, and it gets the next free numeric
     * code so it shows up in Settings -> Agencies ready to be edited.
     */
    private static function resolveAgency(string $raw): ?int
    {
        $name = mb_substr(trim($raw), 0, 120);

        if ($name === '') {
            return null;
        }

        // The table collation is case-insensitive, so "smdsco" matches too.
        $found = Database::scalar(
            'SELECT id FROM agencies WHERE code = ? OR agency_name = ? LIMIT 1',
            [$name, $name]
        );

        if ($found !== null && $found !== false && (int)$found > 0) {
            return (int)$found;
        }

        $id = Database::insert('agencies', [
            'agency_name' => $name,
            'code'        => self::nextAgencyCode(),
            'status'      => 'active',
            'remarks'     => 'AUTO-CREATED BY DRIVER IMPORT',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        self::$agenciesCreated++;

        return $id;
    }

    /**
     * Next free purely-numeric agency code ("1", "2", ...). Existing alpha
     * codes (RT, SMASCO) can never equal a digits-only value, and every
     * existing numeric code is below max + 1, so the unique key always holds.
     */
    private static function nextAgencyCode(): string
    {
        $max = 0;

        foreach (Database::all("SELECT code FROM agencies WHERE code REGEXP '^[0-9]+$'") as $row) {
            $max = max($max, (int)($row['code'] ?? 0));
        }

        return (string)($max + 1);
    }

    /** Resolve agency/branch codes and normalise dates/status. */
    private static function toRow(array $row): array
    {
        $agencyId = self::resolveAgency((string)($row['agency_code'] ?? ''));
        $branchId = null;

        if (($row['branch_code'] ?? '') !== '') {
            $branchId = (int)Database::scalar(
                'SELECT id FROM branches WHERE code = ? LIMIT 1',
                [(string)$row['branch_code']]
            ) ?: null;
        }

        $joining = ($row['joining_date'] ?? '') !== '' && strtotime((string)$row['joining_date']) !== false
            ? date('Y-m-d', (int)strtotime((string)$row['joining_date']))
            : null;

        // The deactivation end date: optional, and only meaningful for a
        // driver who is leaving (commit() drops it again for status active).
        $lwd = ($row['last_working_date'] ?? '') !== '' && strtotime((string)$row['last_working_date']) !== false
            ? date('Y-m-d', (int)strtotime((string)$row['last_working_date']))
            : null;

        return [
            'badge_number'             => (string)$row['badge_number'],
            'driver_name'              => (string)$row['driver_name'],
            'iqama_number'             => (string)$row['iqama_number'],
            'passport_number'          => ($row['passport_number'] ?? '') ?: null,
            'mobile_number'            => ($row['mobile_number'] ?? '') ?: null,
            'alternate_mobile'         => ($row['alternate_mobile'] ?? '') ?: null,
            'nationality'              => ($row['nationality'] ?? '') ?: null,
            'agency_id'                => $agencyId,
            'branch_id'                => $branchId,
            'department'               => ($row['department'] ?? '') ?: null,
            'position'                 => ($row['position'] ?? '') ?: null,
            'joining_date'             => $joining,
            'last_working_date'        => $lwd,
            'emergency_contact_name'   => ($row['emergency_contact_name'] ?? '') ?: null,
            'emergency_contact_number' => ($row['emergency_contact_number'] ?? '') ?: null,
            'address'                  => ($row['address'] ?? '') ?: null,
            'city'                     => ($row['city'] ?? '') ?: null,
            'remarks'                  => ($row['remarks'] ?? '') ?: null,
            // Slugs from the Settings lists; blank stays NULL = "not set".
            'operational_status'       => self::normaliseOperationalStatus(
                (string)($row['operational_status'] ?? '')
            ),
        ];
    }

    private static function normaliseStatus(string $status): string
    {
        $want = strtolower(trim($status));
        if ($want === '') {
            return 'active';
        }

        // Map to the configured slug so "Active"/"ACTIVE" in a spreadsheet
        // still stores the canonical lowercase value the KPIs compare against.
        foreach (array_keys(Lookup::employmentStatuses()) as $slug) {
            if (strtolower($slug) === $want) {
                return $slug;
            }
        }

        return 'active';
    }

    private static function normaliseOperationalStatus(string $status): ?string
    {
        $want = strtolower(trim($status));
        if ($want === '') {
            return null;
        }

        foreach (array_keys(Lookup::operationalStatuses()) as $slug) {
            if (strtolower($slug) === $want) {
                return $slug;
            }
        }

        return null;
    }

    /** Default CSV template rows (headers + one example). */
    public static function template(): array
    {
        // Example values use slugs from the two Settings lists - the same
        // words validateRow() accepts.
        $employment = array_keys(Lookup::employmentStatuses());
        $operational = array_keys(Lookup::operationalStatuses());

        return [
            self::HEADERS,
            [
                'B-900', 'Example Driver', '2459999999', 'AB1234567', '0559999999', '',
                'India', 'AWM', 'DMM', 'Transport', 'Heavy Truck Driver',
                '2024-01-15', '', 'Example Contact', '0559999888', 'Street 1',
                'Dammam', 'Replace or delete this row',
                $employment[0] ?? 'active', $operational[0] ?? 'assigned',
            ],
        ];
    }
}

