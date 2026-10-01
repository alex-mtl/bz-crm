<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Infrastructure\Health\HealthChecker;
use Illuminate\Http\JsonResponse;

final class HealthController
{
    public function __invoke(HealthChecker $checker): JsonResponse
    {
        $checks = $checker->run();
        $healthy = HealthChecker::allHealthy($checks);

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'checks' => array_map(fn (bool $ok) => $ok ? 'ok' : 'fail', $checks),
        ], $healthy ? 200 : 503);
    }
}
