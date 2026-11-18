<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Shared ground for everything a client sees.
 *
 * Every read here starts from the signed in client's own relation rather than
 * from the model, so a record belonging to somebody else is not found rather
 * than found and then refused. That distinction matters: a 403 on
 * /client/projects/12 confirms that project 12 exists and is not theirs, which
 * is a fact they should not be able to harvest by walking the ids.
 *
 * Route model binding is deliberately not used for client owned records.
 */
abstract class ClientController extends Controller
{
    /** The record, or a 404 that says nothing about whose it is. */
    protected function own(Request $request, Builder $query, int|string $id, string $column = 'id'): Model
    {
        return $query->where($column, $id)->firstOrFail();
    }

    /**
     * The account whose records are being read: the organisation this person
     * belongs to (CL8), or their own. The person themselves is still
     * `$request->user()`, which is who raised a ticket or made a payment.
     */
    protected function client(Request $request)
    {
        return $request->user()->clientAccount();
    }

    /** Refuse, in words, something this person's role in the organisation does not allow. */
    protected function mayAsClient(Request $request, string $ability): void
    {
        abort_unless($request->user()->canAsClient($ability), 403, match ($ability) {
            'pay' => 'Only finance or an owner in your organisation can pay.',
            'approve', 'sign', 'accept' => 'Only an authorised signatory or an owner in your organisation can do this.',
            'api_keys' => 'Only technical people or an owner in your organisation can manage API keys.',
            default => 'Your role in the organisation does not allow this.',
        });

        // Signing, accepting and paying commit the organisation, so they need
        // two-step sign in (CL20); a stolen password alone cannot do them.
        abort_if(
            in_array($ability, User::COMMITTING_ABILITIES, true) && $request->user()->needsTwoFactorToCommit(),
            403,
            'Turn on two-step sign in under Security first. Signing and paying for your organisation need it.',
        );
    }
}
