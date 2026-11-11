<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\Impersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Starting and stopping "view as". The rules are in Impersonation and
 * GuardImpersonation; this only asks for the reason and moves people about.
 */
class ImpersonationController extends Controller
{
    public function start(Request $request, string $role, User $user, Impersonation $impersonation): RedirectResponse
    {
        abort_unless($user->role->value === $role, 404);

        $validated = $this->validatedInput($request, [
            'reason' => ['required', 'string', 'min:5', 'max:300'],
        ], ['reason.required' => 'Say why. It is recorded, and the person can see that their account was viewed.']);

        try {
            $impersonation->start($request->user(), $user, $validated['reason']);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect($user->homeRoute())
            ->with('info', "You are seeing what {$user->name} sees. Nothing can be changed, and it ends in ".Impersonation::MINUTES.' minutes.');
    }

    public function stop(Request $request, Impersonation $impersonation): RedirectResponse
    {
        $viewed = $request->user();
        $staff = $impersonation->stop();

        if (! $staff) {
            return redirect()->route('login');
        }

        return redirect("/admin/{$viewed->role->value}s/{$viewed->id}")
            ->with('success', "Back to your own account. You were viewing {$viewed->name}.");
    }
}
