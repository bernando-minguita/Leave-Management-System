<?php

declare(strict_types=1);

namespace App\Api;

use App\Models\Driver;
use App\Models\Lookup;

/**
 * Driver type-ahead and quick actions for the /api/drivers/* endpoints.
 */
final class DriversApi
{
    /**
     * GET /api/drivers/search?q=ahmed&limit=15
     * Minimum viable payload for the autocomplete list.
     */
    public function search(): void
    {
        $term  = trim((string)($_GET['q'] ?? ''));
        $limit = max(1, min(50, (int)($_GET['limit'] ?? 15)));

        $rows = Driver::searchLite($term, $limit);

        json_out([
            'ok'    => true,
            'count' => count($rows),
            'rows'  => array_map(static function (array $r): array {
                return [
                    'id'            => (int)$r['id'],
                    'badge_number'  => (string)$r['badge_number'],
                    'driver_name'   => (string)$r['driver_name'],
                    'iqama_number'  => (string)$r['iqama_number'],
                    'mobile_number' => (string)($r['mobile_number'] ?? ''),
                    'nationality'   => (string)($r['nationality'] ?? ''),
                    'agency'        => (string)($r['agency_name'] ?? ''),
                    'branch'        => (string)($r['branch_name'] ?? ''),
                ];
            }, $rows),
        ]);
    }

    /** GET /api/drivers/detail?id=12 - the card shown after picking a driver. */
    public function detail(): void
    {
        $id     = (int)($_GET['id'] ?? 0);
        $driver = $id > 0 ? Driver::find($id) : null;

        if ($driver === null) {
            json_error('That driver could not be found.', 404);
        }

        $current = Driver::currentLeave($id);

        json_out([
            'ok'      => true,
            'driver'  => [
                'id'            => (int)$driver['id'],
                'badge_number'  => (string)$driver['badge_number'],
                'driver_name'   => (string)$driver['driver_name'],
                'iqama_number'  => (string)$driver['iqama_number'],
                'passport_number' => (string)($driver['passport_number'] ?? ''),
                'mobile_number' => (string)($driver['mobile_number'] ?? ''),
                'nationality'   => (string)($driver['nationality'] ?? ''),
                'status'        => (string)$driver['status'],
                'agency'        => (string)($driver['agency_name'] ?? ''),
                'branch'        => (string)($driver['branch_name'] ?? ''),
                'branch_id'     => (int)($driver['branch_id'] ?? 0),
                'position'      => (string)($driver['position'] ?? ''),
            ],
            'current_leave' => $current === null ? null : [
                'status'       => (string)$current['status'],
                'leave_type'   => (string)($current['leave_type'] ?? ''),
                'leave_start'  => (string)$current['leave_start_date'],
                'expected'     => (string)$current['expected_arrival_date'],
                'destination'  => (string)($current['destination'] ?? ''),
            ],
            'leave_types' => Lookup::leaveTypePairs(true),
        ]);
    }

    /** POST /api/drivers/deactivate - soft deactivation from a list row. */
    public function deactivate(): void
    {
        $input  = json_input();
        $id     = (int)($input['id'] ?? 0);
        $driver = $id > 0 ? Driver::find($id) : null;

        if ($driver === null) {
            json_error('That driver could not be found.', 404);
        }

        $open = Driver::activeLeaveCount($id);

        if ($open > 0) {
            json_error('This driver has ' . $open . ' open leave record(s). Mark them returned first.', 422);
        }

        $status = ($input['status'] ?? 'inactive') === 'active' ? 'active' : 'inactive';

        \App\Core\Database::update('drivers', [
            'status'     => $status,
            'updated_at' => now(),
        ], 'id = ?', [$id]);

        audit_log($status === 'active' ? 'driver-activated' : 'driver-deactivated',
            'drivers', $id, ['status' => $driver['status']], ['status' => $status]);

        json_out(['ok' => true, 'message' => $driver['driver_name'] . ' is now ' . $status . '.']);
    }
    /**
     * POST /api/drivers/sync-simledger - pull the driver's number from
     * SIMLedger (matched by badge number) and store it on the LMS record.
     * Prefers the assigned company SIM, falling back to the personal contact.
     *
     * Deliberately one driver at a time: the profile page's "Sync from
     * SIMLedger" button. Read-only against SIMLedger; the only write is
     * drivers.mobile_number here, audit-logged as driver-mobile-synced.
     */
    public function syncSimLedger(): void
    {
        $input  = json_input();
        $id     = (int)($input['id'] ?? 0);
        $driver = $id > 0 ? Driver::find($id) : null;

        if ($driver === null) {
            json_error('That driver could not be found.', 404);
        }

        try {
            $remote = simledger_fetch_by_badge((string)$driver['badge_number']);
        } catch (\RuntimeException $ex) {
            json_error($ex->getMessage(), 422);
        }

        // Company SIM (company asset) wins; the personal contact is the
        // fallback - mirrors the driver profile's own card ordering.
        $newMobile = trim((string)($remote['company_sim'] ?? ''));
        $via       = 'company_sim';
        if ($newMobile === '') {
            $newMobile = trim((string)($remote['mobile'] ?? ''));
            $via       = 'personal';
        }
        if ($newMobile === '') {
            json_error('SIMLedger has no company SIM or personal number on file for badge '
                . $driver['badge_number'] . '.', 422);
        }

        $old = (string)($driver['mobile_number'] ?? '');
        if ($old === $newMobile) {
            json_out([
                'ok'            => true,
                'changed'       => false,
                'message'       => 'Mobile already matches SIMLedger (' . $newMobile . ').',
                'mobile_number' => $old,
            ]);
            return;
        }

        \App\Core\Database::update('drivers', [
            'mobile_number' => $newMobile,
            'updated_at'    => now(),
        ], 'id = ?', [$id]);

        audit_log('driver-mobile-synced', 'drivers', $id,
            ['mobile_number' => $old],
            ['mobile_number' => $newMobile, 'source' => 'SIMLedger',
             'simledger_field' => $via,
             'simledger_name' => (string)($remote['name'] ?? '')]);

        json_out([
            'ok'            => true,
            'changed'       => true,
            'message'       => 'Mobile synced from SIMLedger (' . $via . '): ' . $newMobile . '.',
            'mobile_number' => $newMobile,
        ]);
    }

    /**
     * POST /api/drivers/sync-simledger-all - bulk mobile sync (roster diff).
     *
     * Fetches SIMLedger's full active roster in ONE request, then diffs every
     * LMS driver holding a badge against it. Same contract as the single
     * sync: only drivers.mobile_number is written, each change audit-logged
     * as driver-mobile-synced (source SIMLedger, bulk=true). A badge absent
     * from the active roster is SKIPPED, never cleared.
     */
    public function syncSimLedgerAll(): void
    {
        try {
            $roster = simledger_fetch_roster();
        } catch (\RuntimeException $ex) {
            json_error($ex->getMessage(), 422);
        }

        $rows = \App\Core\Database::all(
            'SELECT id, badge_number, driver_name, mobile_number FROM drivers'
        );

        $changed       = 0;
        $unchanged     = 0;
        $noMobile      = 0;
        $missing       = 0;
        $noBadge       = 0;
        $changedBadges = [];

        foreach ($rows as $driver) {
            $badge = trim((string)$driver['badge_number']);
            if ($badge === '') {
                $noBadge++;
                continue;
            }
            if (!isset($roster[$badge])) {
                $missing++;
                continue;
            }
            // Company SIM first, personal contact as fallback (same rule as
            // the single-driver sync).
            $newMobile = trim((string)($roster[$badge]['company_sim'] ?? ''));
            $via       = 'company_sim';
            if ($newMobile === '') {
                $newMobile = trim((string)($roster[$badge]['mobile'] ?? ''));
                $via       = 'personal';
            }
            if ($newMobile === '') {
                $noMobile++;
                continue;
            }

            $old = (string)($driver['mobile_number'] ?? '');
            if ($old === $newMobile) {
                $unchanged++;
                continue;
            }

            \App\Core\Database::update('drivers', [
                'mobile_number' => $newMobile,
                'updated_at'    => now(),
            ], 'id = ?', [(int)$driver['id']]);

            audit_log('driver-mobile-synced', 'drivers', (int)$driver['id'],
                ['mobile_number' => $old],
                ['mobile_number' => $newMobile, 'source' => 'SIMLedger',
                 'simledger_field' => $via,
                 'simledger_name' => (string)($roster[$badge]['name'] ?? ''),
                 'bulk' => true]);

            $changed++;
            $changedBadges[] = $badge;
        }

        json_out([
            'ok'               => true,
            'roster'           => count($roster),
            'checked'          => count($rows),
            'changed'          => $changed,
            'unchanged'        => $unchanged,
            'not_in_simledger' => $missing,
            'no_mobile'        => $noMobile,
            'no_badge'         => $noBadge,
            'changed_badges'   => array_slice($changedBadges, 0, 50),
            'message'          => 'SIMLedger sync: ' . $changed . ' updated, '
                . $unchanged . ' already matched, ' . $missing . ' not in SIMLedger'
                . ($noMobile > 0 ? ', ' . $noMobile . ' without mobile' : '') . '.',
        ]);
    }
}
