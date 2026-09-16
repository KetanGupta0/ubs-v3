<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\User;
use App\Services\Admin\AccountCreator;
use App\Services\Admin\Auditor;
use App\Support\Identifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff accounts and what each one may do.
 *
 * The owner holds everything implicitly; every other administrator holds only
 * what is ticked. A staff member with nothing ticked can still sign in and see
 * the dashboard, which is deliberate: an account that cannot do anything is
 * easier to reason about than one that cannot get in.
 */
class StaffController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/staff/Index', [
            'staff' => User::query()
                ->role(Role::Admin)
                ->with('permissions:id,key')
                ->orderBy('name')
                ->get()
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'mobile' => $user->mobile,
                    'status' => $user->status->value,
                    'isOwner' => $user->is_owner,
                    'permissions' => $user->permissions->pluck('key'),
                    'permissionCount' => $user->permissions->count(),
                    'twoFactorEnabled' => $user->hasTwoFactorEnabled(),
                    'lastLoginAgo' => $user->last_login_at?->diffForHumans() ?? 'Never',
                    'mustChangePassword' => $user->must_change_password,
                ]),
            'permissionGroups' => $this->permissionGroups(),
        ]);
    }

    public function store(Request $request, AccountCreator $creator): RedirectResponse
    {
        $request->merge([
            'email' => mb_strtolower(trim((string) $request->input('email'))),
            'mobile' => $request->filled('mobile')
                ? Identifier::normaliseMobile((string) $request->input('mobile'))
                : null,
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'mobile' => ['nullable', 'string', 'regex:/^\+\d{10,15}$/', Rule::unique('users', 'mobile')],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'key')],
        ]);

        $result = $creator->create($validated, Role::Admin);

        if ($validated['permissions'] ?? false) {
            $result['user']->permissions()->sync(
                Permission::query()->whereIn('key', $validated['permissions'])->pluck('id'),
            );
        }

        return back()->with('success', 'Staff account created. Credentials sent.');
    }

    public function updatePermissions(Request $request, User $user, Auditor $auditor): RedirectResponse
    {
        abort_unless($user->isAdmin(), 404);

        if ($user->is_owner) {
            return back()->withErrors(['permissions' => 'The owner holds every permission and cannot be restricted here.']);
        }

        // Nobody edits their own permissions. Otherwise anybody trusted with
        // staff.manage could quietly grant themselves everything else.
        if ($user->is($request->user())) {
            return back()->withErrors(['permissions' => 'You cannot change your own permissions.']);
        }

        $validated = $request->validate([
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'key')],
        ]);

        $before = $user->permissions->pluck('key')->sort()->values()->all();
        $after = collect($validated['permissions'] ?? [])->sort()->values()->all();

        $user->permissions()->sync(
            Permission::query()->whereIn('key', $after)->pluck('id'),
        );

        if ($before !== $after) {
            $auditor->action('staff.permissions_changed', $user, [
                'granted' => array_values(array_diff($after, $before)),
                'revoked' => array_values(array_diff($before, $after)),
            ], $user->name);
        }

        return back()->with('success', 'Permissions updated.');
    }

    public function setStatus(Request $request, User $user, Auditor $auditor): RedirectResponse
    {
        abort_unless($user->isAdmin(), 404);

        if ($user->is_owner) {
            return back()->withErrors(['status' => 'The owner account cannot be suspended from here.']);
        }

        // Locking yourself out is not a recoverable mistake from inside the app.
        if ($user->is($request->user())) {
            return back()->withErrors(['status' => 'You cannot change the status of your own account.']);
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in(array_column(UserStatus::cases(), 'value'))],
        ]);

        $user->status = UserStatus::from($validated['status']);
        $auditor->updated($user, label: $user->name);
        $user->save();

        if (! $user->canSignIn()) {
            $user->tokens()->delete();
        }

        return back()->with('success', 'Staff account is now '.$validated['status'].'.');
    }

    /** @return array<int, array<string, mixed>> */
    protected function permissionGroups(): array
    {
        return Permission::query()
            ->orderBy('group')
            ->orderBy('key')
            ->get()
            ->groupBy('group')
            ->map(fn ($items, $group) => [
                'group' => $group,
                'permissions' => $items->map(fn (Permission $permission) => [
                    'key' => $permission->key,
                    'label' => $permission->label,
                ])->values(),
            ])
            ->values()
            ->all();
    }
}
