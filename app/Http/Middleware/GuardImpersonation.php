<?php

namespace App\Http\Middleware;

use App\Services\Auth\Impersonation;
use App\Services\Lms\Activity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What a member of staff may do while viewing the platform as somebody else:
 * look, and nothing more.
 *
 * - Anything that changes something is refused. A payment, a quiz attempt, a
 *   submission or a password made "as" a student would be the member of
 *   staff's act recorded as the student's.
 * - Chat is closed, because opening a conversation marks its messages as
 *   read and shows the person as present.
 * - Pages that note what a student did (joining a class, downloading a
 *   handout) do not note it, so the visit leaves their record alone.
 * - It ends on its own after Impersonation::MINUTES.
 */
class GuardImpersonation
{
    public function __construct(protected Impersonation $impersonation) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->impersonation->active()) {
            return $next($request);
        }

        // Stopping is the one way out. Signing out is refused like any other
        // change, or it would be written into the person's log as theirs.
        if ($request->routeIs('viewing-as.stop')) {
            return $next($request);
        }

        if ($this->impersonation->expired()) {
            $this->impersonation->stop();

            return redirect('/admin')->with('info', 'Viewing as that person ended after '.Impersonation::MINUTES.' minutes.');
        }

        if (! $request->isMethodSafe()) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Viewing as somebody is read only.'], 403)
                : back()->with('error', 'Viewing as somebody is read only. Nothing was changed.');
        }

        if ($request->is('chat', 'chat/*')) {
            return redirect($request->user()->homeRoute())
                ->with('info', 'Chat is closed while viewing as somebody: opening it would mark their messages as read.');
        }

        Activity::mute();

        return $next($request);
    }
}
