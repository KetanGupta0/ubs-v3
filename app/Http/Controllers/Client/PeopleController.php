<?php

namespace App\Http\Controllers\Client;

use App\Models\ClientMember;
use App\Services\Client\Organisation;
use App\Support\Identifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * The people in a client organisation (CL8). Everybody in it can see who
 * else is; only an owner brings people in, changes roles or takes them out.
 */
class PeopleController extends ClientController
{
    public function index(Request $request): Response
    {
        $account = $this->client($request);
        $account->loadMissing('profile');

        return Inertia::render('client/People', [
            'organisation' => $account->organisationName(),
            'holder' => [
                'name' => $account->name,
                'email' => $account->email,
                'me' => $account->is($request->user()),
            ],
            'members' => $account->clientMembers()
                ->with('user:id,name,email,last_login_at', 'inviter:id,name')
                ->orderByRaw('joined_at is null')
                ->orderBy('id')
                ->get()
                ->map(fn (ClientMember $member) => [
                    'id' => $member->id,
                    'name' => $member->user?->name,
                    'email' => $member->user?->email,
                    'role' => $member->role,
                    'roleLabel' => $member->roleLabel(),
                    'pending' => $member->joined_at === null,
                    'me' => $member->user_id === $request->user()->id,
                    'invitedBy' => $member->inviter?->name,
                    'lastSignIn' => $member->user?->last_login_at?->diffForHumans(),
                ]),
            'roles' => collect(ClientMember::ROLES)->map(fn (array $role, string $key) => [
                'value' => $key,
                'label' => $role[0],
                'description' => $role[1],
            ])->values(),
            'myRole' => $request->user()->clientRole(),
            'canManage' => $request->user()->canAsClient('manage_people'),
        ]);
    }

    public function store(Request $request, Organisation $organisation): RedirectResponse
    {
        $this->mayAsClient($request, 'manage_people');

        $request->merge([
            'email' => mb_strtolower(trim((string) $request->input('email'))),
            'mobile' => $request->filled('mobile') ? Identifier::normaliseMobile((string) $request->input('mobile')) : null,
        ]);

        $validated = $this->validatedInput($request, [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'mobile' => ['nullable', 'string', 'regex:/^\+\d{10,15}$/'],
            'role' => ['required', Rule::in(array_keys(ClientMember::ROLES))],
        ], ['mobile.regex' => 'Enter a valid mobile number, including the country code.']);

        $organisation->invite($this->client($request), $request->user(), $validated);

        // The same words whatever the address turned out to be.
        return back()->with('success', "Invitation sent to {$validated['email']}.");
    }

    public function update(Request $request, int $member, Organisation $organisation): RedirectResponse
    {
        $this->mayAsClient($request, 'manage_people');
        $validated = $this->validatedInput($request, ['role' => ['required', Rule::in(array_keys(ClientMember::ROLES))]]);

        $record = $this->own($request, $this->client($request)->clientMembers()->getQuery(), $member);
        abort_if($record->user_id === $request->user()->id, 422, 'Ask another owner to change your own role.');

        $organisation->changeRole($record, $validated['role'], $request->user());

        return back()->with('success', "{$record->user->name} is now ".mb_strtolower(ClientMember::ROLES[$validated['role']][0]).'.');
    }

    public function destroy(Request $request, int $member, Organisation $organisation): RedirectResponse
    {
        $this->mayAsClient($request, 'manage_people');

        $record = $this->own($request, $this->client($request)->clientMembers()->getQuery(), $member);
        abort_if($record->user_id === $request->user()->id, 422, 'Ask another owner to take you out.');

        $name = $record->user->name;
        $pending = $record->joined_at === null;
        $organisation->remove($record, $request->user());

        return back()->with('success', $pending ? 'Invitation withdrawn.' : "{$name} is no longer in the organisation.");
    }

    /* ---------------------------------------------- an invitation to me */

    public function accept(Request $request, int $member, Organisation $organisation): RedirectResponse
    {
        $record = ClientMember::query()->where('user_id', $request->user()->id)->pending()->findOrFail($member);

        $organisation->accept($record);

        return redirect('/client')->with('success', 'You are now in '.$record->account->organisationName().'.');
    }

    public function decline(Request $request, int $member, Organisation $organisation): RedirectResponse
    {
        $record = ClientMember::query()->where('user_id', $request->user()->id)->pending()->findOrFail($member);

        try {
            $organisation->decline($record);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Declined.');
    }
}
