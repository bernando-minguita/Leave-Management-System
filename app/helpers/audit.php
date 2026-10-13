<?php

declare(strict_types=1);

/**
 * Write an entry to the audit trail.
 *
 * Audited events: driver created / edited / deactivated, leave created /
 * edited / cancelled, driver marked returned, settings changed.
 *
 * @param array<string, mixed>|null $old previous values
 * @param array<string, mixed>|null $new new values
 * @param int|null $userId who did it - passed explicitly for events that
 *        happen *before* a session exists (a failed 2FA code, a bad password),
 *        so the trail still names the account rather than leaving it blank
 */
function audit_log(string $action, string $table, ?int $recordId, ?array $old = null, ?array $new = null, ?int $userId = null): void
{
    $userId = $userId ?? $_SESSION['user_id'] ?? null;

    $payload = static function (?array $values): ?string {
        if ($values === null || $values === []) {
            return null;
        }

        $json = json_encode($values, JSON_UNESCAPED_UNICODE);

        return $json === false ? null : $json;
    };

    try {
        \App\Core\Database::run(
            'INSERT INTO audit_logs (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $userId !== null ? (int)$userId : null,
                mb_substr($action, 0, 60),
                mb_substr($table, 0, 60),
                $recordId,
                $payload($old),
                $payload($new),
                $_SERVER['REMOTE_ADDR'] ?? null,
                mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null,
                date('Y-m-d H:i:s'),
            ]
        );
    } catch (\Throwable $ex) {
        // The audit trail must never break the operation it describes.
        log_fatal('audit_log failed: ' . $ex->getMessage());
    }
}

/**
 * Keep only the keys that actually changed.
 *
 * @param array<string, mixed> $old
 * @param array<string, mixed> $new
 * @return array<string, array{0:mixed,1:mixed}> field => [old, new]
 */
function audit_diff(array $old, array $new): array
{
    $diff = [];

    foreach ($new as $key => $value) {
        $before = $old[$key] ?? null;
        $a      = $before === null ? '' : (string)$before;
        $b      = $value === null ? '' : (string)$value;

        if ($a !== $b) {
            $diff[$key] = [$before, $value];
        }
    }

    return $diff;
}
