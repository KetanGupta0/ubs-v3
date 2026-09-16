<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AuthEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Auth\AuthAuditor;
use App\Services\Auth\LoginPipeline;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The single sign in screen, shared by administrators, clients and students.
 */
class AuthenticatedSessionController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('auth/Login', [
            'canRegister' => true,
            'googleEnabled' => filled(config('services.google.client_id')),
            'status' => $request->session()->get('status'),
        ]);
    }

    public function store(LoginRequest $request, LoginPipeline $pipeline, AuthAuditor $auditor): RedirectResponse
    {
        $user = $request->resolveUser($auditor);

        if ($reason = $pipeline->refusalReason($user)) {
            $auditor->failure(AuthEvent::LoginFailed, $user->email, 'suspended', $user, 'password');

            return back()->withErrors(['identifier' => $reason]);
        }

        if ($pipeline->requiresTwoFactor($user)) {
            $pipeline->beginTwoFactorChallenge($user, 'password');

            return redirect()->route('two-factor.challenge');
        }

        $pipeline->complete($user, 'password', $request->boolean('remember'));

        return redirect()->intended($pipeline->destinationFor($user));
    }

    public function destroy(Request $request, AuthAuditor $auditor): RedirectResponse
    {
        $auditor->success(AuthEvent::LoggedOut, $request->user());

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
