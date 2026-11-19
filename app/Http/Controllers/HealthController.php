<?php

namespace App\Http\Controllers;

use App\Support\Monitoring\Health;
use Illuminate\Http\JsonResponse;

/**
 * For UptimeRobot (Step I): 200 when everything works, 503 naming what does
 * not. Names and states only, nothing an outsider could use.
 */
class HealthController
{
    public function __invoke(): JsonResponse
    {
        $checks = Health::checks();
        $healthy = Health::healthy($checks);

        return response()
            ->json(['status' => $healthy ? 'ok' : 'failing', 'checks' => $checks], $healthy ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }
}
