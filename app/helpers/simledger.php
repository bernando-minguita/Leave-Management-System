<?php
/**
 * SIMLedger integration helper: fetch one driver's record by badge number
 * from the SIMLedger read-only API (Bearer token auth).
 *
 * Returns the decoded `driver` object. Two optional numbers may come back:
 * `company_sim` (the assigned company SIM) and `mobile` (personal contact) -
 * the caller picks the priority (company SIM first, personal as fallback).
 * Throws RuntimeException with a user-facing message on configuration /
 * network / auth / not-found failures - callers surface the message to the
 * user as-is.
 *
 * @return array<string, mixed>
 */
function simledger_fetch_by_badge(string $badge): array
{
    $badge = trim($badge);
    if ($badge === '' || strlen($badge) > 30) {
        throw new RuntimeException('This driver has no badge number to sync from.');
    }
    if (!defined('SIMLEDGER_API_URL') || SIMLEDGER_API_URL === '') {
        throw new RuntimeException('SIMLedger API URL is not configured.');
    }
    if (!defined('SIMLEDGER_API_TOKEN') || SIMLEDGER_API_TOKEN === '') {
        throw new RuntimeException('SIMLedger API token is not configured (SIMLEDGER_API_TOKEN).');
    }

    $url = rtrim(SIMLEDGER_API_URL, '/') . '?badge=' . rawurlencode($badge);

    if (!function_exists('curl_init')) {
        throw new RuntimeException('The cURL extension is required to reach SIMLedger.');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . SIMLEDGER_API_TOKEN,
            'Accept: application/json',
        ],
    ]);
    $body   = curl_exec($ch);
    $errno  = curl_errno($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0 || $body === false) {
        throw new RuntimeException('Could not reach SIMLedger (network error). Is it running?');
    }

    $data = json_decode((string)$body, true);
    if (!is_array($data)) {
        throw new RuntimeException('SIMLedger returned an unreadable response.');
    }

    if ($status === 401) {
        throw new RuntimeException('SIMLedger rejected the API token (unauthorised).');
    }
    if ($status === 404) {
        throw new RuntimeException('SIMLedger has no active driver with badge ' . $badge . '.');
    }
    if ($status !== 200 || empty($data['ok']) || !isset($data['driver'])) {
        $msg = trim((string)($data['message'] ?? ''));
        throw new RuntimeException($msg !== '' ? $msg
            : 'SIMLedger sync failed (HTTP ' . $status . ').');
    }

    return $data['driver'];
}

/**
 * Fetch SIMLedger's FULL active-driver roster in one call and index it by
 * badge number. Same endpoint as the single sync, without ?badge=.
 *
 * @return array<string, array<string, mixed>> badge => driver row
 */
function simledger_fetch_roster(): array
{
    if (!defined('SIMLEDGER_API_URL') || SIMLEDGER_API_URL === '') {
        throw new RuntimeException('SIMLedger API URL is not configured.');
    }
    if (!defined('SIMLEDGER_API_TOKEN') || SIMLEDGER_API_TOKEN === '') {
        throw new RuntimeException('SIMLedger API token is not configured (SIMLEDGER_API_TOKEN).');
    }

    $url = rtrim(SIMLEDGER_API_URL, '/');   // roster = same endpoint, no ?badge=

    if (!function_exists('curl_init')) {
        throw new RuntimeException('The cURL extension is required to reach SIMLedger.');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . SIMLEDGER_API_TOKEN,
            'Accept: application/json',
        ],
    ]);
    $body   = curl_exec($ch);
    $errno  = curl_errno($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0 || $body === false) {
        throw new RuntimeException('Could not reach SIMLedger (network error). Is it running?');
    }

    $data = json_decode((string)$body, true);
    if (!is_array($data)) {
        throw new RuntimeException('SIMLedger returned an unreadable response.');
    }

    if ($status === 401) {
        throw new RuntimeException('SIMLedger rejected the API token (unauthorised).');
    }
    if ($status !== 200 || empty($data['ok']) || !isset($data['drivers'])
        || !is_array($data['drivers'])) {
        $msg = trim((string)($data['message'] ?? ''));
        throw new RuntimeException($msg !== '' ? $msg
            : 'SIMLedger roster fetch failed (HTTP ' . $status . ').');
    }

    $byBadge = [];
    foreach ($data['drivers'] as $row) {
        if (is_array($row) && isset($row['badge_number'])) {
            $byBadge[(string)$row['badge_number']] = $row;
        }
    }

    return $byBadge;
}
/**
 * Is the SIMLedger integration switched on?
 *
 * True only when an API token is configured (app/config/config.php or the
 * SIMLEDGER_API_TOKEN environment variable). The drivers list's "Sync all"
 * button and the profile's "Sync from SIMLedger" button render only when
 * this is true; the fetchers above refuse to run when it is false.
 */
function simledger_enabled(): bool
{
    return defined('SIMLEDGER_API_TOKEN') && SIMLEDGER_API_TOKEN !== '';
}
