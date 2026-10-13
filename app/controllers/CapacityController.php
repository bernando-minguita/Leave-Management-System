<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\View;
use App\Models\CapacityPlanningService;
use App\Models\Lookup;

/**
 * Driver Capacity Planning: the day-by-day forecast, its monthly summary and
 * the two exports.
 *
 * The controller gathers filters, asks the service for the numbers and picks
 * an export format - no business rule lives here (spec section 62).
 */
final class CapacityController
{
    public function index(array $params = []): void
    {
        require_login();

        $filters = CapacityPlanningService::normalise($_GET);
        $payload = CapacityPlanningService::calculate($filters);

        // Exporting is a separate right from viewing, and it arrives as a
        // query string the route map cannot see - same as the reports.
        if (($_GET['export'] ?? '') !== '') {
            $this->exportRows($payload, (string)($_GET['table'] ?? 'daily'));
        }

        // Chart payload: labels plus one series per selectable metric (the
        // dropdown on the card header). The browser only draws it - every
        // figure is computed here, in PHP.
        $chart = [
            'labels'          => [],
            'onLeavePct'      => [],
            // The headcount BEHIND the percentage. The tooltip prints both, so
            // a reader never has to reverse the fleet size out of "6.19%" to
            // find out how many people that actually is.
            'onLeave'         => [],
            'expectedReturns' => [],
            // The third series: pending applications expected to leave - the
            // same figures as the Expected Leave column, so the movement and
            // the trend can never disagree.
            'expectedLeave'   => [],
        ];
        foreach ($payload['days'] as $day) {
            $chart['labels'][]          = $day['date'];
            $chart['onLeavePct'][]      = $day['on_leave_pct'];
            $chart['onLeave'][]         = (int) $day['on_leave'];
            $chart['expectedReturns'][] = (int) $day['returns'];
            $chart['expectedLeave'][]   = (int) ($day['expected_leave'] ?? 0);
        }

        // The day-by-day table shows ONE month of the window (section 17);
        // the window itself still drives the chart, KPIs and monthly summary.
        $dailyMonth = (string)$filters['daily_month'];
        $dailyRows  = array_values(array_filter(
            $payload['days'],
            static fn (array $day): bool => substr((string)$day['date'], 0, 7) === $dailyMonth
        ));

        // Two independent pagers, because the two tables answer different
        // questions. The movement list can run to thousands of rows, so it
        // carries the application's standard page sizes; the day-by-day
        // table defaults to 10 rows like the movement list and also offers
        // 25 and 31, where 31 puts a whole month on one page.
        $mvSizes = [10, 25, 50, 100, 250];
        $dySizes = [10, 25, 31];

        $mvSize  = (int)($_GET['mv_size'] ?? 10);
        $mvSize  = in_array($mvSize, $mvSizes, true) ? $mvSize : 10;
        $mvTotal = count($payload['movement']);
        $mvPages = max(1, (int)ceil($mvTotal / $mvSize));
        $mvPage  = min(max(1, (int)($_GET['mv_page'] ?? 1)), $mvPages);

        $dySize  = (int)($_GET['dy_size'] ?? 10);
        $dySize  = in_array($dySize, $dySizes, true) ? $dySize : 10;
        $dyTotal = count($dailyRows);
        $dyPages = max(1, (int)ceil($dyTotal / $dySize));
        $dyPage  = min(max(1, (int)($_GET['dy_page'] ?? 1)), $dyPages);

        // Both grids page themselves now (assets/js/table.js): ship every row
        // for the selection and let the browser slice it, so page links and the
        // Rows select stop reloading the page. The totals and the initial page
        // above still drive what the server renders first.
        $movementRows = array_values($payload['movement']);
        // Already filtered to the selected month above - shipped whole, like
        // the movement list.
        $dailyRows = array_values($dailyRows);

        $movementMeta = [
            'total' => $mvTotal, 'page' => $mvPage, 'pages' => $mvPages, 'size' => $mvSize,
            'pageParam' => 'mv_page', 'sizeParam' => 'mv_size', 'sizes' => $mvSizes,
        ];
        $dailyMeta = [
            'total' => $dyTotal, 'page' => $dyPage, 'pages' => $dyPages, 'size' => $dySize,
            'pageParam' => 'dy_page', 'sizeParam' => 'dy_size', 'sizes' => $dySizes,
        ];

        // Why this page and the dashboard can differ for the same day, stated
        // in numbers instead of left for the reader to work out (see
        // CapacityPlanningService::bridge()). The comparison is about LEAVE
        // RECORDS: strip today's projected expectations so both sides are
        // judged by the same rule - the projection gets its own quality row.
        $bridge         = $payload['bridge'];
        $projectedToday = (int)($payload['projected_today'] ?? 0);
        $capacityOut    = (int)$payload['today_row']['on_leave'] - $projectedToday;
        $statusOut      = (int)$bridge['status_away'];
        $unconfirmed    = (int)$bridge['due_back_unconfirmed'];
        $delta          = $statusOut - $capacityOut;

        if ($delta === $unconfirmed && $unconfirmed > 0) {
            $compare = sprintf(
                'Dashboard "Currently On Leave" shows %d today; this page shows %d. The difference is exactly '
                . '%d driver(s) due back today whose return has not been recorded yet: the dashboard keeps '
                . 'counting a driver until the return is confirmed, while this forecast counts the expected '
                . 'arrival day as the return day (spec section 64, test 1).',
                $statusOut,
                $capacityOut,
                $unconfirmed
            );
        } elseif ($delta === 0) {
            $compare = sprintf(
                'Today agrees with the dashboard: %d away and %d due back today, %d already confirmed.',
                $capacityOut,
                $unconfirmed,
                $unconfirmed
            );
        } else {
            $compare = sprintf(
                'Dashboard counts %d away today, this page counts %d (%d unconfirmed return(s)). The two use '
                . 'the same active population but different rules: the dashboard follows the recorded status, '
                . 'this page follows the dates.',
                $statusOut,
                $capacityOut,
                $unconfirmed
            );
        }

        if ($projectedToday > 0) {
            $compare .= sprintf(
                ' On top of that, %d expected leave driver(s) are projected as away on this page today.',
                $projectedToday
            );
        }

        $quality   = $payload['quality'];
        $quality[] = [
            'label'  => 'Dashboard comparison',
            'count'  => $unconfirmed,
            'detail' => $compare,
            'state'  => 'info',
        ];

        // What moves the forecast besides records: projected expectations,
        // stated in trip-days so a reader can see how much of On Leave /
        // Available this week is expectation rather than leave taken.
        $projectedTotal = (int)($payload['projected_days'] ?? 0);
        $quality[] = [
            'label'  => 'Expected leave projected as away',
            'count'  => $projectedTotal,
            'detail' => $projectedTotal > 0
                ? 'trip-day(s): pending applications counted as away from the day after their last working '
                    . 'date through the day before their expected return - folded into On Leave, Available, '
                    . 'the trend chart, the monthly summary and the forecast cards. Active never moves for '
                    . 'an expectation, and applications without an expected return cannot be placed (see '
                    . 'the line above).'
                : 'No pending application could be placed as an absence in this range: On Leave and '
                    . 'Available here show leave records only (expectations never move Active).',
            'state'  => 'info',
        ];

        // The dashboard's "Expected Returns" card and this page's "Expected
        // Returns" card answer different questions for the same range - state
        // both numbers and the rule instead of leaving the reader to spot it
        // (see CapacityPlanningService::returnBridge()).
        $arrivals = $payload['returnBridge'];
        $plan     = (int)$payload['forecast']['expected_returns'];
        $dash     = (int)$arrivals['dashboard_arrivals'];

        if ($dash === $plan) {
            $arrivalsNote = sprintf(
                'Dashboard "Expected returns" and this card agree at %d for this range: the returns still '
                . 'ahead. %d driver(s) already returned are counted by neither - an expected return that '
                . 'happened is no longer expected.',
                $plan,
                (int)$arrivals['confirmed']
            );
        } else {
            $arrivalsNote = sprintf(
                'Dashboard "Expected returns" shows %d for this range, this card shows %d. This card counts '
                . 'the %d return(s) still ahead and nothing else - %d driver(s) already returned are excluded '
                . '(an expected return that happened is no longer expected), while the dashboard also keeps '
                . 'the %d past-due return(s) that never happened (shown here as Overdue): the dashboard is a '
                . 'worklist of returns not recorded yet, this card is what is still to come.',
                $dash,
                $plan,
                (int)$arrivals['shared_ahead'],
                (int)$arrivals['confirmed'],
                (int)$arrivals['overdue_unreturned']
            );
        }

        $quality[] = [
            'label'  => 'Dashboard arrivals comparison',
            'count'  => $dash - $plan,
            'detail' => $arrivalsNote,
            'state'  => 'info',
        ];

        // Same treatment for resignations: the dashboard card counts filings,
        // this card counts decided exits (see resignationBridge()).
        $resign   = $payload['resignBridge'];
        $planRes  = (int)$payload['forecast']['expected_resignations'];
        $dashRes  = (int)$resign['dashboard_applications'];

        // Both sides now count the same filings (resignation family, not
        // cancelled, dated on the last working date), so they agree in
        // every normal case - the note states the composition either way.
        if ($dashRes === $planRes) {
            $resignNote = sprintf(
                'Dashboard "Resignation Applications" and this card agree at %d for this range: every '
                . 'filing that is not cancelled, dated on its last working date (%d approved, %d pending'
                . '%s).',
                $planRes,
                (int)$resign['approved_filed'],
                (int)$resign['pending'],
                (int)$resign['rejected'] > 0 ? ', ' . (int)$resign['rejected'] . ' rejected' : ''
            );
        } else {
            $resignNote = sprintf(
                'Dashboard "Resignation Applications" shows %d for this range, this card shows %d '
                . '(%d approved, %d pending). Both count filings that are not cancelled, so only a '
                . 'difference in scope or leave-type filters can make them differ.',
                $dashRes,
                $planRes,
                (int)$resign['approved_filed'],
                (int)$resign['pending']
            );
        }

        $quality[] = [
            'label'  => 'Dashboard resignations comparison',
            'count'  => $dashRes - $planRes,
            'detail' => $resignNote,
            'state'  => 'info',
        ];

        View::render('capacity/index', [
            'filters'       => $filters,
            'kpis'          => $payload['kpis'],
            'forecast'      => $payload['forecast'],
            'returnBridge'  => $payload['returnBridge'],
            'resignBridge'  => $payload['resignBridge'],
            'bridge'        => $bridge,
            'dailyRows'     => $dailyRows,
            'dailyMeta'     => $dailyMeta,
            'dailyMonth'    => $dailyMonth,
            'months'        => $payload['months'],
            'monthly'       => $payload['monthly'],
            // One read of leave_percentage, shared by the header label and the
            // cells below it so the column can never name a ceiling it does
            // not apply.
            'leaveTargetPct'=> (float) ($payload['leave_target_pct'] ?? 0),
            'movement'      => $movementRows,
            'movementMeta'  => $movementMeta,
            'quality'       => $quality,
            'today'         => $payload['today'],
            'todayRow'      => $payload['today_row'],
            'modeLabel'     => $payload['mode_label'],
            'typeFilter'    => $payload['type_filter'],
            'clamped'       => $payload['clamped'],
            'base'          => $payload['base'],
            'chart'         => $chart,
            'query'         => $_GET,
            'branches'      => Lookup::branchPairs(false),
            'agencies'      => Lookup::agencyPairs(false),
            'leaveTypes'    => Lookup::leaveTypePairs(false),
        ], 'Driver Capacity Planning', 'capacity-planning', [
            ['label' => 'Planning & Capacity'],
            ['label' => 'Driver Capacity Planning'],
        ]);
    }

    /**
     * CSV / Excel for either table, always with the filters that produced the
     * numbers on screen (sections 44-45).
     *
     * @param array<string, mixed> $payload
     */
    private function exportRows(array $payload, string $table): void
    {
        require_permission('reports.export');

        $format = export_format((string)($_GET['export'] ?? ''), ['csv', 'xls']);

        // Three tables, one export path: the day-by-day grid, the monthly
        // summary and (round 6) the movement list. Anything unrecognised falls
        // back to the daily table rather than failing a bookmarked link.
        [$headers, $rows] = match ($table) {
            'monthly'  => CapacityPlanningService::exportMonthly($payload),
            'movement' => CapacityPlanningService::exportMovement($payload),
            default    => CapacityPlanningService::exportDaily($payload),
        };

        $name = 'capacity-' . $table . '-' . date('Ymd-His');

        if ($format === 'xls') {
            // The report's real name, not the machine filename: it is the
            // 16pt heading on row 1 of the sheet.
            $title = match ($table) {
                'monthly'  => 'Monthly Capacity Summary',
                'movement' => 'Capacity Movement',
                default    => 'Daily Capacity Summary',
            };

            export_xls($headers, $rows, $name . '.xls', $title);
        }

        export_csv($headers, $rows, $name . '.csv');
    }
}
