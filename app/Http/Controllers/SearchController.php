<?php

namespace App\Http\Controllers;

use App\Services\Search\GlobalSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The search box in the header, answered.
 *
 * JSON rather than a page, because the palette opens over whatever somebody is
 * already doing and taking them away from it to show a list of links would be
 * a strange way to help.
 */
class SearchController extends Controller
{
    public function __invoke(Request $request, GlobalSearch $search): JsonResponse
    {
        $term = (string) $request->query('q', '');

        return response()->json([
            'term' => $term,
            'groups' => $search->for($request->user(), $term),
        ]);
    }
}
