<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AuthEvent;
use App\Http\Controllers\Controller;
use App\Services\Auth\AuthAuditor;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmailVerificationController extends Controller
{
    public function notice(Request $request): Response|RedirectResponse
    {
        return $request->user()->hasVerifiedEmail()
            ? redirect($request->user()->homeRoute())
            : Inertia::render('auth/VerifyEmail', [
                'status' => $request->session()->get('status'),
                'email' => $request->user()->email,
            ]);
    }

    public function verify(EmailVerificationRequest $request, AuthAuditor $auditor): RedirectResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return redirect($user->homeRoute());
        }

        $user->markEmailAsVerified();
        event(new Verified($user));

        $auditor->success(AuthEvent::EmailVerified, $user);

        return redirect($user->homeRoute())->with('success', 'Email address confirmed.');
    }

    public function resend(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect($request->user()->homeRoute());
        }

        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', 'A fresh verification link is on its way.');
    }
}
