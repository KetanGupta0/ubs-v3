<?php

namespace App\Services\Auth;

use App\Enums\AuthEvent;
use App\Enums\Role;
use App\Models\User;
use App\Services\Admin\Auditor;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * Seeing the platform as a student or a client does, to help them.
 *
 * This is not a sign in, and deliberately does not go through LoginPipeline:
 * the person did not prove who they are, a member of staff did. So it does
 * not touch their last sign in, it is written to their security log and to
 * the audit log with the reason given, it cannot be started on another
 * administrator, it ends by itself, and while it lasts nothing can be
 * changed (see GuardImpersonation). Looking is the whole feature.
 */
class Impersonation
{
    public const ADMIN_KEY = 'viewing_as.admin_id';

    public const STARTED_KEY = 'viewing_as.started_at';

    /** Long enough to follow a problem through, short enough to forget safely. */
    public const MINUTES = 30;

    public function __construct(
        protected Request $request,
        protected AuthAuditor $authAuditor,
        protected Auditor $auditor,
    ) {}

    public function start(User $staff, User $target, string $reason): void
    {
        if ($this->active()) {
            throw new InvalidArgumentException('Stop viewing as the current person first.');
        }

        if (! in_array($target->role, [Role::Student, Role::Client], true)) {
            throw new InvalidArgumentException('Only a student or a client account can be viewed this way.');
        }

        // In the audit log while the member of staff is still the one signed in.
        $this->auditor->action('account.viewed_as', $target, ['reason' => $reason], $target->name);

        // And in the person's own security log, so it is not done behind their back.
        $this->authAuditor->record(AuthEvent::ViewedAsStarted, $target, $target->email, true, $reason, 'staff', [
            'by' => $staff->id,
        ]);

        Auth::login($target);

        $session = $this->request->session();
        $session->regenerate();
        $session->put(self::ADMIN_KEY, $staff->id);
        $session->put(self::STARTED_KEY, now()->timestamp);
    }

    /** Go back to being the member of staff. Returns them, or null if nobody was being viewed. */
    public function stop(): ?User
    {
        if (! $this->active()) {
            return null;
        }

        $session = $this->request->session();
        $target = $this->request->user();
        $staff = User::query()->find($session->get(self::ADMIN_KEY));

        $session->forget([self::ADMIN_KEY, self::STARTED_KEY]);

        if ($target) {
            $this->authAuditor->record(AuthEvent::ViewedAsStopped, $target, $target->email, true, null, 'staff', [
                'by' => $staff?->id,
            ]);
        }

        if (! $staff || ! $staff->isAdmin() || ! $staff->canSignIn()) {
            // The member of staff has since been removed or suspended.
            Auth::logout();
            $session->invalidate();

            return null;
        }

        Auth::login($staff);
        $session->regenerate();

        return $staff;
    }

    public function active(): bool
    {
        return $this->request->hasSession() && $this->request->session()->has(self::ADMIN_KEY);
    }

    public function expired(): bool
    {
        return $this->active() && $this->expiresAt()->isPast();
    }

    public function expiresAt(): Carbon
    {
        return Carbon::createFromTimestamp((int) $this->request->session()->get(self::STARTED_KEY, 0))
            ->addMinutes(self::MINUTES);
    }

    public function staff(): ?User
    {
        return $this->active() ? User::query()->find($this->request->session()->get(self::ADMIN_KEY)) : null;
    }
}
