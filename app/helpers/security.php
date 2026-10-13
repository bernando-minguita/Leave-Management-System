<?php

declare(strict_types=1);

/**
 * Account security primitives: TOTP (RFC 6238), secret encryption, recovery
 * codes, Google reCAPTCHA and login rate limiting.
 *
 * Plain functions included from bootstrap.php - helpers here live in the
 * global namespace. Nothing in this file ever writes a password, a TOTP
 * secret or a recovery code to a log.
 */

/* ===========================================================================
 * Base32 (RFC 4648, unpadded - the encoding authenticator apps expect)
 * ======================================================================== */

function base32_encode(string $data): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = 0;
    $value = 0;
    $out = '';

    foreach (str_split($data) as $byte) {
        $value = ($value << 8) | ord($byte);
        $bits += 8;

        while ($bits >= 5) {
            $out .= $alphabet[($value >> ($bits - 5)) & 31];
            $bits -= 5;
        }
    }

    if ($bits > 0) {
        $out .= $alphabet[($value << (5 - $bits)) & 31];
    }

    return $out;
}

function base32_decode(string $data): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $clean = strtoupper((string) preg_replace('/[^A-Z2-7]/', '', $data));
    $bits = 0;
    $value = 0;
    $out = '';

    foreach (str_split($clean) as $char) {
        $pos = strpos($alphabet, $char);

        if ($pos === false) {
            continue;
        }

        $value = ($value << 5) | $pos;
        $bits += 5;

        if ($bits >= 8) {
            $out .= chr(($value >> ($bits - 8)) & 255);
            $bits -= 8;
        }
    }

    return $out;
}

/* ===========================================================================
 * TOTP - RFC 6238, SHA-1, 30 second step, 6 digits
 *
 * The base32 secret is the canonical form; binary conversion happens inside,
 * so callers can never mix the two representations.
 * ======================================================================== */

/** 160-bit random secret, base32 encoded (32 characters). */
function totp_secret_new(): string
{
    return base32_encode(random_bytes(20));
}

function totp_code(string $secret, ?int $time = null, int $digits = 6, int $period = 30): string
{
    $key = base32_decode($secret);

    if ($key === '') {
        return '';
    }

    $counter = intdiv($time ?? time(), $period);
    $bin = '';

    for ($i = 7; $i >= 0; $i--) {
        $bin .= chr(($counter >> ($i * 8)) & 255);
    }

    $hmac   = hash_hmac('sha1', $bin, $key, true);
    $offset = ord($hmac[19]) & 0x0f;
    $slice  = substr($hmac, $offset, 4);

    $value = (ord($slice[0]) & 0x7f) << 24
        | (ord($slice[1]) & 0xff) << 16
        | (ord($slice[2]) & 0xff) << 8
        | (ord($slice[3]) & 0xff);

    return str_pad((string) ($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}

/**
 * Accept a code from the previous, current or next time step so a slightly
 * out-of-sync phone still works. The comparison is timing-safe.
 */
function totp_verify(string $secret, string $code, int $window = 1): bool
{
    $digits = preg_replace('/\D+/', '', $code) ?? '';

    if (strlen($digits) !== 6 || trim($secret) === '') {
        return false;
    }

    $now = time();

    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totp_code($secret, $now + ($i * 30)), $digits)) {
            return true;
        }
    }

    return false;
}

/** otpauth:// URI that a camera-based authenticator app scans. */
function totp_uri(string $secret, string $account, string $issuer): string
{
    return 'otpauth://totp/'
        . rawurlencode($issuer) . ':' . rawurlencode($account)
        . '?secret=' . $secret
        . '&issuer=' . rawurlencode($issuer)
        . '&algorithm=SHA1'
        . '&digits=6'
        . '&period=30';
}

/* ===========================================================================
 * Secret encryption at rest (AES-256-GCM)
 *
 * A stolen database dump must not yield working authenticator seeds.
 * ======================================================================== */

function secret_encrypt(string $plain): string
{
    if ($plain === '') {
        return '';
    }

    $iv   = random_bytes(12);
    $tag  = '';
    $key  = hash('sha256', APP_KEY, true);
    $data = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

    return $data === false ? '' : base64_encode($iv . $tag . $data);
}

function secret_decrypt(string $packed): string
{
    if ($packed === '') {
        return '';
    }

    $raw = base64_decode($packed, true);

    if ($raw === false || strlen($raw) < 29) {
        return '';
    }

    $plain = openssl_decrypt(
        substr($raw, 28), 'aes-256-gcm', hash('sha256', APP_KEY, true),
        OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16)
    );

    return $plain === false ? '' : $plain;
}

/* ===========================================================================
 * Recovery codes - shown once, stored only as hashes, single use
 * ======================================================================== */

/** Ten codes like "4F9K-2M7Q-XB3D-9WRE". Returned in plaintext exactly once. */
function recovery_codes_new(int $count = 10): array
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O/1/I confusion
    $codes = [];

    while (count($codes) < $count) {
        $raw = '';

        for ($i = 0; $i < 16; $i++) {
            $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        $codes[] = substr($raw, 0, 4) . '-' . substr($raw, 4, 4)
            . '-' . substr($raw, 8, 4) . '-' . substr($raw, 12, 4);
    }

    return $codes;
}

/** Stored only as a hash - matches PHP's password_hash format, never reverses. */
function recovery_hash(string $code): string
{
    return password_hash(strtoupper((string) preg_replace('/\s+/', '', $code)), PASSWORD_DEFAULT);
}

/**
 * Consume one unused recovery code. Returns the row id used, or null.
 * Each code works exactly once.
 */
function recovery_consume(int $userId, string $code): ?int
{
    $needle = strtoupper((string) preg_replace('/\s+/', '', trim($code)));

    if ($needle === '') {
        return null;
    }

    $rows = \App\Core\Database::all(
        'SELECT id, code_hash FROM user_recovery_codes
          WHERE user_id = ? AND used_at IS NULL ORDER BY id',
        [$userId]
    );

    foreach ($rows as $row) {
        if (password_verify($needle, (string) $row['code_hash'])) {
            \App\Core\Database::run(
                'UPDATE user_recovery_codes SET used_at = ? WHERE id = ? AND used_at IS NULL',
                [date('Y-m-d H:i:s'), (int) $row['id']]
            );

            return (int) $row['id'];
        }
    }

    return null;
}

/** Replace a user's codes with a fresh set (the old ones stop working). */
function recovery_regenerate(int $userId): array
{
    \App\Core\Database::run('DELETE FROM user_recovery_codes WHERE user_id = ?', [$userId]);

    $codes = recovery_codes_new();
    $now   = date('Y-m-d H:i:s');

    foreach ($codes as $code) {
        \App\Core\Database::run(
            'INSERT INTO user_recovery_codes (user_id, code_hash, created_at) VALUES (?, ?, ?)',
            [$userId, recovery_hash($code), $now]
        );
    }

    return $codes;
}

/** How many unused codes a user still holds (never the codes themselves). */
function recovery_remaining(int $userId): int
{
    return (int) \App\Core\Database::scalar(
        'SELECT COUNT(1) FROM user_recovery_codes WHERE user_id = ? AND used_at IS NULL',
        [$userId]
    );
}

/* ===========================================================================
 * Google reCAPTCHA
 * ======================================================================== */

/**
 * True on localhost / private development hosts, so reCAPTCHA is skipped while
 * developing and switches itself on automatically once deployed to a real
 * domain - no code change required.
 */
/**
 * Are we on the local development machine?
 *
 * Deliberately narrow. Only the loopback names are treated as local:
 *
 *     localhost, 127.0.0.1, ::1, [::1], and *.localhost (RFC 6761 reserves
 *     that TLD for loopback).
 *
 * It used to also return true for ANY private or reserved address
 * (10.x, 172.16-31.x, 192.168.x, 169.254.x, ...). That looked harmless but
 * meant a production deployment sitting on an internal network would quietly
 * switch reCAPTCHA off, exactly where an attacker on that network still
 * matters. A private address is a network topology fact, not a development
 * environment, so it no longer qualifies.
 *
 * Development needs no keys at all: recaptcha_enabled() returns false when
 * they are unset, which is the normal localhost setup. Nothing here depends on
 * the machine's address range.
 */
function is_local_host(): bool
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $host = (string) preg_replace('/:\d+$/', '', $host);

    if (in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)) {
        return true;
    }

    // RFC 6761: *.localhost resolves to loopback. An empty host means CLI or a
    // test harness with no Host header, never a public request.
    return $host === '' || str_ends_with($host, '.localhost');
}

function recaptcha_enabled(): bool
{
    if (RECAPTCHA_SITE_KEY === '' || RECAPTCHA_SECRET_KEY === '') {
        return false;
    }

    return !is_local_host();
}

/**
 * Server-side verification. The secret key never leaves the server, and the
 * browser only ever sees RECAPTCHA_SITE_KEY.
 *
 * @return array{ok: bool, error: string}
 */
function recaptcha_verify(string $response): array
{
    if ($response === '') {
        return ['ok' => false, 'error' => 'Please confirm you are not a robot.'];
    }

    $post = [
        'secret'   => RECAPTCHA_SECRET_KEY,
        'response' => $response,
    ];

    if (!empty($_SERVER['REMOTE_ADDR'])) {
        $post['remoteip'] = (string) $_SERVER['REMOTE_ADDR'];
    }

    $ch = curl_init(RECAPTCHA_VERIFY_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($post),
        CURLOPT_TIMEOUT        => 10,
    ]);
    $body = (string) curl_exec($ch);
    curl_close($ch);

    $json = json_decode($body, true);

    if (!is_array($json)) {
        // An unreadable response must never be treated as a pass.
        return ['ok' => false, 'error' => 'reCAPTCHA verification could not be completed.'];
    }

    if (!empty($json['success'])) {
        return ['ok' => true, 'error' => ''];
    }

    return ['ok' => false, 'error' => 'reCAPTCHA verification failed. Please try again.'];
}

/* ===========================================================================
 * Rate limiting (login and 2FA attempts)
 * ======================================================================== */

/**
 * Has this bucket burned through its allowance for the current window?
 * The window starts at the first failure, so a burst is what gets throttled.
 */
function rate_limit_exceeded(string $scope, string $bucket, int $max, int $windowSeconds): bool
{
    $row = \App\Core\Database::one(
        'SELECT attempts, window_started_at FROM rate_limits WHERE scope = ? AND bucket = ? LIMIT 1',
        [$scope, $bucket]
    );

    if ($row === null) {
        return false;
    }

    $started = strtotime((string) $row['window_started_at']);

    if ($started === false || (time() - $started) > $windowSeconds) {
        return false; // window has rolled over
    }

    return (int) $row['attempts'] >= $max;
}

/** Record one failure, opening a fresh window when the old one has expired. */
function rate_limit_fail(string $scope, string $bucket, int $windowSeconds): void
{
    $now = date('Y-m-d H:i:s');

    try {
        $row = \App\Core\Database::one(
            'SELECT id, attempts, window_started_at FROM rate_limits
              WHERE scope = ? AND bucket = ? LIMIT 1',
            [$scope, $bucket]
        );

        if ($row === null) {
            // ON DUPLICATE KEY, not a bare INSERT. Two requests can both read
            // "no row yet"; the loser of that race would otherwise be thrown
            // away by uq_rate_scope_bucket and never counted, quietly widening
            // the allowance. The duplicate branch adds to the row the winner
            // just created, so the pair still lands as 2.
            \App\Core\Database::run(
                'INSERT INTO rate_limits (scope, bucket, attempts, window_started_at, last_attempt_at)
                 VALUES (?, ?, 1, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    rate_limits.attempts = rate_limits.attempts + 1,
                    rate_limits.last_attempt_at = ?',
                [$scope, $bucket, $now, $now, $now]
            );
            return;
        }

        $started = strtotime((string) $row['window_started_at']);

        if ($started === false || (time() - $started) > $windowSeconds) {
            \App\Core\Database::run(
                'UPDATE rate_limits SET attempts = 1, window_started_at = ?, last_attempt_at = ? WHERE id = ?',
                [$now, $now, (int) $row['id']]
            );
            return;
        }

        \App\Core\Database::run(
            'UPDATE rate_limits SET attempts = attempts + 1, last_attempt_at = ? WHERE id = ?',
            [$now, (int) $row['id']]
        );
    } catch (\Throwable $ex) {
        // Throttling must never take the login page down.
        log_fatal('rate_limit_fail failed: ' . $ex->getMessage());
    }
}

/** Clear the counter after a success so the next typo starts from zero. */
function rate_limit_clear(string $scope, string $bucket): void
{
    try {
        \App\Core\Database::run('DELETE FROM rate_limits WHERE scope = ? AND bucket = ?', [$scope, $bucket]);
    } catch (\Throwable $ex) {
        log_fatal('rate_limit_clear failed: ' . $ex->getMessage());
    }
}

/** Seconds a caller is locked out for, or 0 when still allowed. */
function rate_limit_retry_after(string $scope, string $bucket, int $windowSeconds): int
{
    if (!rate_limit_exceeded($scope, $bucket, PHP_INT_MAX, $windowSeconds)) {
        return 0;
    }

    $row = \App\Core\Database::one(
        'SELECT window_started_at FROM rate_limits WHERE scope = ? AND bucket = ? LIMIT 1',
        [$scope, $bucket]
    );

    if ($row === null) {
        return 0;
    }

    $started = strtotime((string) $row['window_started_at']);

    return $started === false ? 0 : max(0, ($started + $windowSeconds) - time());
}

/*__APPEND__*/
