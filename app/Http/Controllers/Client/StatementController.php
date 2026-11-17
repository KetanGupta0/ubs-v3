<?php

namespace App\Http\Controllers\Client;

use App\Services\Billing\Statement;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** The organisation's statement of account (CL14), on screen or to download. */
class StatementController extends ClientController
{
    public function __invoke(Request $request, Statement $statements): Response|HttpResponse
    {
        $account = $this->client($request);
        [$from, $to] = Statement::window($request->query('from'), $request->query('to'));
        $statement = $statements->for($account, $from, $to);

        if (in_array($request->query('format'), ['pdf', 'csv'], true)) {
            return $statements->download($account, $statement, $request->query('format'));
        }

        return Inertia::render('client/Statement', ['statement' => Statement::toScreen($statement)]);
    }
}
