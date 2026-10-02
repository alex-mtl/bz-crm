<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\JournalContext;
use App\Domain\Geo\Actions\ManageVehicles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where a vehicle's tracker reports (Д-22). No session: the tracker's own key in the Authorization header is its
 * only credential. An unknown key answers 401 and says nothing else.
 */
final class TrackerController
{
    public function __invoke(Request $request, ManageVehicles $vehicles, JournalContext $context): JsonResponse
    {
        $context->asSystem('tracker');
        $key = (string) $request->bearerToken();
        $points = $request->input('points');
        if (! is_array($points)) {
            $points = [$request->only(['latitude', 'longitude', 'recorded_at', 'accuracy'])];
        }

        $stored = $key !== '' ? $vehicles->report($key, array_values($points)) : null;
        if ($stored === null) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return response()->json(['stored' => $stored]);
    }
}
