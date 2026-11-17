<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Billing\Statement;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** A client organisation's statement of account, from our side (CL14). */
class StatementController extends Controller
{
    public function __invoke(Request $request, User $user, Statement $statements): Response|HttpResponse
    {
        abort_unless($user->isClient(), 404);
        $account = $user->clientAccount();
        [$from, $to] = Statement::window($request->query('from'), $request->query('to'));
        $statement = $statements->for($account, $from, $to);

        if (in_array($request->query('format'), ['pdf', 'csv'], true)) {
            return $statements->download($account, $statement, $request->query('format'));
        }

        return Inertia::render('admin/billing/Statement', [
            'client' => ['id' => $account->id, 'name' => $account->organisationName()],
            'statement' => Statement::toScreen($statement),
        ]);
    }
}
