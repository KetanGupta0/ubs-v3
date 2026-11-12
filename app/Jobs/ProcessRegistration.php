<?php

namespace App\Jobs;

use App\Models\Registration;
use App\Services\Lms\Registrar;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Match one registration to an account, after the visitor has had their answer.
 *
 * Done here rather than in the request so that a new address (an account made,
 * a password hashed, a welcome sent) and a known one take the same time to
 * answer. `Registrar::process()` does nothing to a registration it has already
 * matched, so a retry is harmless.
 */
class ProcessRegistration implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $registrationId) {}

    public function handle(Registrar $registrar): void
    {
        $registration = Registration::query()->find($this->registrationId);

        if ($registration) {
            $registrar->process($registration);
        }
    }
}
