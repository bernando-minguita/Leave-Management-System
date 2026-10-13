<?php

declare(strict_types=1);

namespace App\Api;

use App\Models\Lookup;

/**
 * Settings JSON: one dispatch endpoint shared by the settings screens.
 * Mirrors SettingsController::save() so a screen can choose form-post or AJAX.
 */
final class SettingsApi
{
    public function save(): void
    {
        // Delegates to SettingsController::save(), which asks for the
        // permission that the submitted `kind` needs.
        $controller = new \App\Controllers\SettingsController();
        $controller->save();
    }

    /**
     * GET /api/settings/lookup?table=branches|agencies|leave_types
     * Small lists for dynamically populated selects.
     */
    public function lookup(): void
    {
        $table = (string)($_GET['table'] ?? '');

        $pairs = match ($table) {
            'branches'    => Lookup::branchPairs(false),
            'agencies'    => Lookup::agencyPairs(false),
            'leave_types' => Lookup::leaveTypePairs(false),
            default       => null,
        };

        if ($pairs === null) {
            json_error('Unknown lookup table.', 400);
        }

        $rows = [];
        foreach ($pairs as $id => $label) {
            $rows[] = ['id' => $id, 'label' => $label];
        }

        json_out(['ok' => true, 'rows' => $rows]);
    }
}
