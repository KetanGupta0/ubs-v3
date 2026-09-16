<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
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

    protected function client(Request $request)
    {
        return $request->user();
    }
}
