<?php

namespace App\Http\Controllers\Settings;

use App\Enums\AuthEvent;
use App\Http\Controllers\Controller;
use App\Models\AuthAuditLog;
use App\Services\Auth\AuthAuditor;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The security screen: sessions, devices, two factor status and recent events.
 *
 * Showing people where their account has been signed in from is the cheapest
 * way for them to notice a compromise themselves, long before we would.
 */
class SecurityController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/Security', [
            'twoFactor' => [
                'enabled' => $user->hasTwoFactorEnabled(),
                'recoveryCodesRemaining' => $user->twoFactorSecret?->remainingRecoveryCodes() ?? 0,
                'confirmedAt' => $user->twoFactorSecret?->confirmed_at?->toIso8601String(),
            ],
            'account' => [
                'hasPassword' => $user->hasPassword(),
                'emailVerified' => $user->hasVerifiedEmail(),
                'mobile' => $user->mobile,
                'mobileVerified' => $user->mobile_verified_at !== null,
                'googleLinked' => $user->socialAccounts()->where('provider', 'google')->exists(),
            ],
            'sessions' => $this->sessions($request),
            'devices' => $user->tokens()
                ->latest('last_used_at')
                ->get()
                ->map(fn ($token) => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'platform' => $token->platform,
                    'appVersion' => $token->app_version,
                    'lastUsedAt' => $token->last_used_at?->diffForHumans(),
                ]),
            'recentActivity' => AuthAuditLog::query()
                ->where('user_id', $user->id)
                ->latest('id')
                ->limit(15)
                ->get()
                ->map(fn (AuthAuditLog $log) => [
                    'id' => $log->id,
                    'event' => $log->event->value,
                    'succeeded' => $log->succeeded,
                    'method' => $log->method,
                    'ip' => $log->ip_address,
                    'at' => $log->created_at->diffForHumans(),
                ]),
        ]);
    }

    /** Browser sessions, readable only because the session driver is the database. */
    protected function sessions(Request $request): array
    {
        if (config('session.driver') !== 'database') {
            return [];
        }

        return DB::table(config('session.table', 'sessions'))
            ->where('user_id', $request->user()->id)
            ->orderByDesc('last_activity')
            ->get()
            ->map(fn ($session) => [
                'id' => $session->id,
                'current' => $session->id === $request->session()->getId(),
                'ip' => $session->ip_address,
                'agent' => $this->describeAgent($session->user_agent ?? ''),
                'lastActive' => Carbon::createFromTimestamp($session->last_activity)->diffForHumans(),
            ])
            ->all();
    }

    public function revokeSession(Request $request, string $id, AuthAuditor $auditor): RedirectResponse
    {
        abort_if(config('session.driver') !== 'database', 404);

        // Scoped to the signed in user, so an id from another account does
        // nothing rather than ending a stranger's session.
        DB::table(config('session.table', 'sessions'))
            ->where('user_id', $request->user()->id)
            ->where('id', $id)
            ->delete();

        $auditor->success(AuthEvent::SessionRevoked, $request->user());

        return back()->with('success', 'That session has been signed out.');
    }

    public function revokeDevice(Request $request, int $id, AuthAuditor $auditor): RedirectResponse
    {
        $request->user()->tokens()->whereKey($id)->delete();

        $auditor->success(AuthEvent::TokenRevoked, $request->user());

        return back()->with('success', 'That device has been signed out.');
    }

    /** A readable label from a user agent string, without a parsing library. */
    protected function describeAgent(string $agent): string
    {
        $platform = match (true) {
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone'), str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Mac OS X') => 'macOS',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Linux') => 'Linux',
            default => 'Unknown device',
        };

        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Safari/') => 'Safari',
            default => 'browser',
        };

        return "{$browser} on {$platform}";
    }
}
