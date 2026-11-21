<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClientMember;
use App\Models\User;
use App\Rules\PersonName;
use App\Services\Client\Organisation;
use App\Support\Identifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * The people in a client organisation, from the client's page in the panel.
 *
 * The same rules as the client's own People screen, through the same
 * service; staff are simply told what actually happened.
 */
class ClientMemberController extends Controller
{
    public function store(Request $request, User $user, Organisation $organisation): RedirectResponse
    {
        $account = $this->account($user);

        $request->merge([
            'email' => mb_strtolower(trim((string) $request->input('email'))),
            'mobile' => $request->filled('mobile') ? Identifier::normaliseMobile((string) $request->input('mobile')) : null,
        ]);

        $validated = $this->validatedInput($request, [
            'name' => ['required', 'string', 'max:120', new PersonName],
            'email' => ['required', 'string', 'email', 'max:255'],
            'mobile' => ['nullable', 'string', 'regex:/^\+\d{10,15}$/'],
            'role' => ['required', Rule::in(array_keys(ClientMember::ROLES))],
        ]);

        return match ($organisation->invite($account, $request->user(), $validated)) {
            'created' => back()->with('success', "Account made for {$validated['email']} and added; their sign in details are on the way."),
            'invited' => back()->with('success', "{$validated['email']} already has a client account, so they have been asked to accept."),
            default => back()->with('error', "{$validated['email']} cannot be added: it belongs to staff, a student, a client with work of their own, or somebody already in an organisation."),
        };
    }

    public function update(Request $request, User $user, ClientMember $member, Organisation $organisation): RedirectResponse
    {
        abort_unless($member->account_id === $this->account($user)->id, 404);

        $validated = $this->validatedInput($request, ['role' => ['required', Rule::in(array_keys(ClientMember::ROLES))]]);

        try {
            $organisation->changeRole($member, $validated['role'], $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Role changed.');
    }

    public function destroy(Request $request, User $user, ClientMember $member, Organisation $organisation): RedirectResponse
    {
        abort_unless($member->account_id === $this->account($user)->id, 404);

        $organisation->remove($member, $request->user());

        return back()->with('success', 'Taken out of the organisation.');
    }

    /** The organisation's account, whichever of its people the page is about. */
    protected function account(User $user): User
    {
        abort_unless($user->isClient(), 404);

        return $user->clientAccount();
    }
}
