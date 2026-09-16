<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Landing screens for each role.
 *
 * The admin dashboard moved to its own controller in Phase 3. These two are
 * still placeholders: Phase 4 fills the client one and Phase 5 the student one.
 */
class DashboardController extends Controller
{
    public function client(Request $request): Response
    {
        return Inertia::render('client/Dashboard', [
            'user' => $this->summary($request),
        ]);
    }

    public function student(Request $request): Response
    {
        return Inertia::render('student/Dashboard', [
            'user' => $this->summary($request),
        ]);
    }

    protected function summary(Request $request): array
    {
        $user = $request->user();

        return [
            'name' => $user->name,
            'role' => $user->role->value,
            'emailVerified' => $user->hasVerifiedEmail(),
            'mobileVerified' => $user->mobile_verified_at !== null,
            'twoFactorEnabled' => $user->hasTwoFactorEnabled(),
            'lastLoginAt' => $user->last_login_at?->diffForHumans(),
        ];
    }
}
