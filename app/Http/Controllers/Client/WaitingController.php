<?php

namespace App\Http\Controllers\Client;

use App\Models\Project;
use App\Models\WaitingItem;
use App\Services\Client\WaitingItems;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Replying to what we asked the client for (CL10).
 *
 * Anybody in the organisation may reply: a logo is not a legal act. Files
 * join the organisation's documents, marked as sent by them.
 */
class WaitingController extends ClientController
{
    /** What an attachment may be, judged from its bytes, not its name. */
    public const FILE_TYPES = 'pdf,png,jpg,jpeg,webp,gif,svg,zip,doc,docx,xls,xlsx,ppt,pptx,txt,csv,psd,eps,ai';

    public function show(Request $request, int $project, int $item, WaitingItems $waiting): Response
    {
        $record = $this->ownItem($request, $project, $item);
        $time = $waiting->waitingTime($record->project);

        return Inertia::render('client/projects/Waiting', [
            'project' => ['id' => $record->project->id, 'name' => $record->project->name, 'code' => $record->project->code],
            'item' => $waiting->forScreen(WaitingItem::query()->whereKey($record->id)->get(), $time['items'])[0],
        ]);
    }

    public function answer(Request $request, int $project, int $item, WaitingItems $waiting): RedirectResponse
    {
        $record = $this->ownItem($request, $project, $item);

        $validated = $this->validatedInput($request, [
            'body' => ['nullable', 'string', 'max:5000'],
            'files' => ['array', 'max:5'],
            'files.*' => ['file', 'max:20480', 'mimes:'.self::FILE_TYPES],
        ], [
            'files.max' => 'Attach up to five files at a time.',
            'files.*.max' => 'Each file can be up to 20 MB. For anything larger, share a link in your reply.',
            'files.*.mimes' => 'That kind of file cannot be attached. Documents, images, spreadsheets and zip files can.',
        ]);

        try {
            $waiting->answer($record, $request->user(), $validated['body'], $request->file('files', []));
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['body' => $e->getMessage()]);
        }

        return back()->with('success', 'Sent. Thank you; we will let you know if anything else is needed.');
    }

    /** An item on one of the organisation's own projects, or not found. */
    protected function ownItem(Request $request, int $project, int $item): WaitingItem
    {
        $owned = $this->own($request, Project::query()->forClient($this->client($request)), $project);

        return $owned->waitingItems()->whereKey($item)->firstOrFail()->setRelation('project', $owned);
    }
}
