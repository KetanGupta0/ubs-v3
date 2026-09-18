<?php

namespace App\Http\Controllers;

use App\Models\SavedView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;

/**
 * Filter sets somebody uses often, kept.
 *
 * What is stored is the query, not the rows, so a view is always current and
 * applying one is the same act as following a link a colleague sent. The two
 * were always the same thing; this just gives it a name.
 */
class SavedViewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'views' => SavedView::query()
                ->visibleTo($request->user(), $request->query('screen'))
                ->get()
                ->map(fn (SavedView $view) => $this->shape($view, $request))
                ->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatedInput($request, [
            'name' => ['required', 'string', 'max:120'],
            'screen' => ['required', 'string', 'max:120'],
            'state' => ['array'],
            'is_shared' => ['boolean'],
        ]);

        // A path, and nothing else. A saved view is a filter set on one of our
        // own screens, so anything that looks like a URL somewhere else is not
        // one of ours and is refused rather than stored and followed later.
        if (! str_starts_with($validated['screen'], '/') || str_starts_with($validated['screen'], '//')) {
            return back()->withErrors(['screen' => 'That is not a screen on this platform.']);
        }

        SavedView::query()->create([
            'user_id' => $request->user()->id,
            'screen' => $validated['screen'],
            'name' => $validated['name'],
            'state' => $validated['state'] ?: [],
            'is_shared' => $validated['is_shared'],
        ]);

        return back()->with('success', 'View saved.');
    }

    public function apply(Request $request, int $view): RedirectResponse
    {
        $record = SavedView::query()->visibleTo($request->user())->findOrFail($view);

        $record->used();

        return Redirect::to($record->href());
    }

    public function destroy(Request $request, int $view): RedirectResponse
    {
        // Only the person who saved it. A shared view is somebody's bookmark,
        // and removing a colleague's is not a thing a list screen should offer.
        $record = SavedView::query()
            ->where('user_id', $request->user()->id)
            ->findOrFail($view);

        $record->delete();

        return back()->with('success', 'View removed.');
    }

    /** @return array<string, mixed> */
    protected function shape(SavedView $view, Request $request): array
    {
        return [
            'id' => $view->id,
            'name' => $view->name,
            'href' => $view->href(),
            'shared' => $view->is_shared,
            'mine' => $view->user_id === $request->user()->id,
            'timesUsed' => $view->times_used,
        ];
    }
}
