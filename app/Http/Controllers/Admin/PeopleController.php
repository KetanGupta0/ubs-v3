<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\College;
use App\Models\CredentialDelivery;
use App\Models\User;
use App\Services\Admin\AccountCreator;
use App\Services\Admin\Auditor;
use App\Support\Identifier;
use App\Support\Table\Column;
use App\Support\Table\Filter;
use App\Support\Table\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Client and student accounts.
 *
 * One controller for both, because the screens are the same shape and the only
 * real differences are which role is created and which profile fields matter.
 * Two near identical controllers would drift apart within a month.
 */
class PeopleController extends Controller
{
    public function index(Request $request, string $role): Response|HttpResponse
    {
        $roleEnum = $this->roleFrom($role);
        $table = $this->table($roleEnum);

        if ($export = $table->exportResponse($request)) {
            return $export;
        }

        return Inertia::render('admin/people/Index', [
            'role' => $roleEnum->value,
            'roleLabel' => $roleEnum->label(),
            'table' => $table->toArray($request),
            'counts' => [
                'all' => User::query()->role($roleEnum)->count(),
                'active' => User::query()->role($roleEnum)->active()->count(),
                'neverSignedIn' => User::query()->role($roleEnum)->whereNull('last_login_at')->count(),
            ],
            // Students may self register, so an admin creating one is optional.
            'selfRegisterable' => $roleEnum->selfRegisterable(),
        ]);
    }

    public function create(string $role): Response
    {
        $roleEnum = $this->roleFrom($role);

        return Inertia::render('admin/people/Create', [
            'role' => $roleEnum->value,
            'roleLabel' => $roleEnum->label(),
            'colleges' => $roleEnum === Role::Student ? $this->collegeOptions() : [],
        ]);
    }

    public function store(Request $request, string $role, AccountCreator $creator): RedirectResponse
    {
        $roleEnum = $this->roleFrom($role);

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

            'company' => ['nullable', 'string', 'max:160'],
            'designation' => ['nullable', 'string', 'max:120'],
            'gstin' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],

            'college_id' => ['nullable', 'integer', Rule::exists('colleges', 'id')],
            'enrollment_number' => ['nullable', 'string', 'max:60'],
            'course_of_study' => ['nullable', 'string', 'max:120'],
            'current_semester' => ['nullable', 'integer', 'min:1', 'max:12'],
            'graduation_year' => ['nullable', 'integer', 'min:2000', 'max:2100'],

            'note' => ['nullable', 'string', 'max:1000'],
        ], [
            'email.unique' => 'An account already uses that email address.',
            'mobile.unique' => 'An account already uses that mobile number.',
            'mobile.regex' => 'Enter a valid mobile number, including the country code.',
        ]);

        $result = $creator->create($validated, $roleEnum, $validated['note'] ?? null);

        $failed = collect($result['deliveries'])->where('status', 'failed');

        return redirect()
            ->route('admin.people.show', ['role' => $roleEnum->value, 'user' => $result['user']->id])
            ->with(
                $failed->isEmpty() ? 'success' : 'warning',
                $failed->isEmpty()
                    ? 'Account created. Credentials sent to '.collect($result['deliveries'])->pluck('destination')->join(' and ').'.'
                    : 'Account created, but the welcome message could not be sent. Use resend below.',
            );
    }

    public function show(string $role, User $user): Response
    {
        $roleEnum = $this->roleFrom($role);
        abort_unless($user->role === $roleEnum, 404);

        $user->load(['profile.college:id,name', 'socialAccounts:id,user_id,provider']);

        return Inertia::render('admin/people/Show', [
            'role' => $roleEnum->value,
            'roleLabel' => $roleEnum->label(),
            'person' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'mobile' => $user->mobile,
                'status' => $user->status->value,
                'emailVerified' => $user->hasVerifiedEmail(),
                'mobileVerified' => $user->mobile_verified_at !== null,
                'mustChangePassword' => $user->must_change_password,
                'twoFactorEnabled' => $user->hasTwoFactorEnabled(),
                'googleLinked' => $user->socialAccounts->contains('provider', 'google'),
                'lastLoginAt' => $user->last_login_at?->format('j M Y, g:i a'),
                'lastLoginAgo' => $user->last_login_at?->diffForHumans(),
                'createdAt' => $user->created_at->format('j M Y'),
                'profile' => [
                    'company' => $user->profile?->company,
                    'designation' => $user->profile?->designation,
                    'gstin' => $user->profile?->gstin,
                    'city' => $user->profile?->city,
                    'state' => $user->profile?->state,
                    'college' => $user->profile?->college?->name,
                    'collegeId' => $user->profile?->college_id,
                    'enrollmentNumber' => $user->profile?->enrollment_number,
                    'courseOfStudy' => $user->profile?->course_of_study,
                    'currentSemester' => $user->profile?->current_semester,
                    'graduationYear' => $user->profile?->graduation_year,
                ],
            ],
            'colleges' => $roleEnum === Role::Student ? $this->collegeOptions() : [],
            'deliveries' => CredentialDelivery::query()
                ->where('user_id', $user->id)
                ->with('creator:id,name')
                ->latest('id')
                ->limit(10)
                ->get()
                ->map(fn (CredentialDelivery $delivery) => [
                    'id' => $delivery->id,
                    'channel' => $delivery->channel,
                    'destination' => $delivery->destination,
                    'status' => $delivery->status,
                    'reason' => $delivery->failure_reason,
                    'by' => $delivery->creator?->name,
                    'at' => $delivery->created_at->diffForHumans(),
                ]),
        ]);
    }

    public function update(Request $request, string $role, User $user, Auditor $auditor): RedirectResponse
    {
        $roleEnum = $this->roleFrom($role);
        abort_unless($user->role === $roleEnum, 404);

        $request->merge([
            'email' => mb_strtolower(trim((string) $request->input('email'))),
            'mobile' => $request->filled('mobile')
                ? Identifier::normaliseMobile((string) $request->input('mobile'))
                : null,
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'mobile' => ['nullable', 'string', 'regex:/^\+\d{10,15}$/', Rule::unique('users', 'mobile')->ignore($user->id)],
            'company' => ['nullable', 'string', 'max:160'],
            'designation' => ['nullable', 'string', 'max:120'],
            'gstin' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'college_id' => ['nullable', 'integer', Rule::exists('colleges', 'id')],
            'enrollment_number' => ['nullable', 'string', 'max:60'],
            'course_of_study' => ['nullable', 'string', 'max:120'],
            'current_semester' => ['nullable', 'integer', 'min:1', 'max:12'],
            'graduation_year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
        ]);

        $user->fill([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'mobile' => $validated['mobile'] ?? null,
        ]);

        // Changing the address on file un-verifies it. Otherwise an admin edit
        // would silently mark a brand new address as already confirmed.
        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        if ($user->isDirty('mobile')) {
            $user->mobile_verified_at = null;
        }

        $auditor->updated($user, label: $user->name);
        $user->save();

        $user->profile()->updateOrCreate([], collect($validated)->only([
            'company', 'designation', 'gstin', 'city', 'state',
            'college_id', 'enrollment_number', 'course_of_study',
            'current_semester', 'graduation_year',
        ])->all());

        return back()->with('success', 'Account updated.');
    }

    public function resendCredentials(string $role, User $user, AccountCreator $creator): RedirectResponse
    {
        abort_unless($user->role === $this->roleFrom($role), 404);

        $result = $creator->resendCredentials($user);
        $failed = collect($result['deliveries'])->where('status', 'failed');

        return back()->with(
            $failed->isEmpty() ? 'success' : 'error',
            $failed->isEmpty()
                ? 'A new temporary password has been sent. The previous one no longer works.'
                : 'The message could not be sent. Check the mail and SMS settings.',
        );
    }

    public function setStatus(Request $request, string $role, User $user, Auditor $auditor): RedirectResponse
    {
        abort_unless($user->role === $this->roleFrom($role), 404);

        $validated = $request->validate([
            'status' => ['required', Rule::in(array_column(UserStatus::cases(), 'value'))],
        ]);

        $user->status = UserStatus::from($validated['status']);
        $auditor->updated($user, label: $user->name);
        $user->save();

        // A suspended account should not keep working on a device it is already
        // signed in on, so its tokens go with it.
        if (! $user->canSignIn()) {
            $user->tokens()->delete();
        }

        return back()->with('success', 'Account is now '.$validated['status'].'.');
    }

    protected function table(Role $role): Table
    {
        return Table::for(User::query()->role($role)->with('profile.college:id,name'))
            ->searchable(['name', 'email', 'mobile'])
            ->defaultSort('-created_at')
            ->exportName($role->value.'s')
            ->columns([
                Column::make('name', 'Name')->sortable(),
                Column::make('email', 'Email')->sortable(),
                Column::make('mobile', 'Mobile'),
                Column::make('organisation', $role === Role::Student ? 'College' : 'Company'),
                Column::make('status', 'Status')->sortable(),
                Column::make('last_login', 'Last sign in')->sortable(),
                Column::make('created_at', 'Added')->sortable(),
            ])
            ->filters([
                Filter::select('status', array_column(UserStatus::cases(), 'value'), 'Status')->placeholder('Any'),
                Filter::boolean('never_signed_in', 'Never signed in')
                    ->using(fn (Builder $query) => $query->whereNull('last_login_at')),
                Filter::boolean('must_change_password', 'Still on a temporary password')
                    ->using(fn (Builder $query) => $query->where('must_change_password', true)),
                Filter::dateRange('created_at', 'Added between'),
            ])
            ->transform(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'mobile' => $user->mobile,
                'organisation' => $user->profile?->college?->name ?? $user->profile?->company,
                'status' => $user->status->value,
                'last_login' => $user->last_login_at?->diffForHumans() ?? 'Never',
                'created_at' => $user->created_at->format('j M Y'),
                'mustChangePassword' => $user->must_change_password,
                'neverSignedIn' => $user->last_login_at === null,
            ]);
    }

    /** Only client and student are managed here. Staff have their own screen. */
    protected function roleFrom(string $role): Role
    {
        $resolved = Role::tryFrom($role);

        abort_if($resolved === null || $resolved === Role::Admin, 404);

        return $resolved;
    }

    protected function collegeOptions()
    {
        return College::query()
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'city'])
            ->map(fn (College $college) => [
                'value' => $college->id,
                'label' => $college->name,
                'description' => $college->city,
            ]);
    }
}
