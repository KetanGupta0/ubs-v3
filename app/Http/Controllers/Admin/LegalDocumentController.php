<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Marketing\LegalController;
use App\Models\LegalDocument;
use App\Models\LegalDocumentVersion;
use App\Services\Admin\Auditor;
use App\Support\Table\Column;
use App\Support\Table\Table;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Writing the privacy policy, the terms and the refund policy.
 *
 * Each document has at most one draft. Editing starts from the version in
 * force; publishing makes the draft the new version in force and the old one
 * part of the history. A published version is never edited: the next change
 * is the next version, with a note saying what changed.
 */
class LegalDocumentController extends Controller
{
    public function index(Request $request): Response|HttpResponse
    {
        LegalDocument::syncDocuments();

        $table = Table::for(LegalDocument::query()->with(['current', 'draft']))
            ->searchable(['title', 'key'])
            ->defaultSort('id')
            ->exportName('legal-documents')
            ->columns([
                Column::make('title', 'Document')->sortable(),
                Column::make('path', 'Address'),
                Column::make('in_force', 'In force'),
                Column::make('draft', 'Draft'),
                Column::make('updated_at', 'Changed'),
            ])
            ->transform(fn (LegalDocument $document) => [
                'id' => $document->id,
                'key' => $document->key,
                'title' => $document->title,
                'path' => $document->path(),
                'in_force' => $document->current
                    ? "Version {$document->current->version}, since {$document->current->published_at?->format('j M Y')}"
                    : 'Not published',
                'published' => $document->current !== null,
                'draft' => $document->draft ? "Version {$document->draft->version}" : '—',
                'draftVersion' => $document->draft?->version,
                'updated_at' => collect([$document->current?->updated_at, $document->draft?->updated_at])
                    ->filter()->max()?->diffForHumans() ?? '—',
            ]);

        if ($export = $table->exportResponse($request)) {
            return $export;
        }

        return Inertia::render('admin/legal/Index', ['table' => $table->toArray($request)]);
    }

    public function edit(LegalDocument $document): Response
    {
        $document->load(['current', 'draft']);
        $working = $document->draft ?? $document->current;

        return Inertia::render('admin/legal/Edit', [
            'document' => [
                'key' => $document->key,
                'title' => $document->title,
                'path' => $document->path(),
                'current' => $document->current ? [
                    'number' => $document->current->version,
                    'publishedAt' => $document->current->published_at?->format('j M Y'),
                ] : null,
                'draft' => $document->draft ? [
                    'number' => $document->draft->version,
                    'updatedAt' => $document->draft->updated_at?->diffForHumans(),
                ] : null,
                'nextNumber' => $document->draft?->version ?? $this->nextNumber($document),
            ],
            // A new draft starts from the words in force, so a change is an
            // edit of what people read rather than a rewrite from memory.
            'form' => [
                'intro' => $working?->intro ?? '',
                'body' => $working?->body ?? '',
                'change_note' => $document->draft?->change_note ?? '',
            ],
            'history' => $document->versions()->with('publisher:id,name')->get()
                ->map(fn (LegalDocumentVersion $version) => [
                    'number' => $version->version,
                    'status' => $version->status,
                    'publishedAt' => $version->published_at?->format('j M Y, g:i a'),
                    'publishedBy' => $version->publisher?->name,
                    'changeNote' => $version->change_note,
                    'current' => $version->id === $document->current?->id,
                ])->values(),
        ]);
    }

    /** Save the draft, starting one if there is none, and publish it if asked. */
    public function save(Request $request, LegalDocument $document, Auditor $auditor): RedirectResponse
    {
        $validated = $this->validatedInput($request, [
            'intro' => ['nullable', 'string', 'max:500'],
            'body' => ['required', 'string', 'max:60000'],
            'change_note' => ['nullable', 'string', 'max:300'],
            'publish' => ['boolean'],
        ], [
            'body.required' => 'A legal document needs its text.',
        ]);

        $document->load(['current', 'draft']);
        $current = $document->current;

        if ($validated['publish']) {
            if ($current && blank($validated['change_note'])) {
                throw ValidationException::withMessages([
                    'change_note' => 'Say what changed. It is shown with the new version, so somebody who agreed to the last one can see what is different.',
                ]);
            }

            if ($current && trim((string) $validated['intro']) === trim((string) $current->intro)
                && trim($validated['body']) === trim($current->body)) {
                throw ValidationException::withMessages([
                    'body' => "Nothing has changed since version {$current->version}, so there is nothing to publish.",
                ]);
            }
        }

        $version = DB::transaction(function () use ($document, $validated, $request, $auditor) {
            // One draft per document: two people saving at once must not
            // start two of them.
            LegalDocument::query()->whereKey($document->id)->lockForUpdate()->first();
            $draft = $document->draft()->first();
            $isNew = $draft === null;

            $draft ??= new LegalDocumentVersion([
                'legal_document_id' => $document->id,
                'version' => $this->nextNumber($document),
                'created_by' => $request->user()->id,
            ]);

            $draft->fill([
                'intro' => filled($validated['intro']) ? trim($validated['intro']) : null,
                'body' => trim($validated['body']),
                'change_note' => filled($validated['change_note']) ? trim($validated['change_note']) : null,
                'updated_by' => $request->user()->id,
            ]);

            $label = "{$document->title}, version {$draft->version}";

            if ($isNew) {
                $draft->save();
                $auditor->created($draft, $label);
            } else {
                $auditor->updated($draft, label: $label);
                $draft->save();
            }

            if ($validated['publish']) {
                $draft->publish($request->user());
                $auditor->action('legal.published', $draft, ['document' => $document->key, 'version' => $draft->version], $label);
            }

            return $draft;
        });

        return redirect()
            ->route('admin.legal.edit', $document)
            ->with('success', $version->isPublished()
                ? "Version {$version->version} is now in force at {$document->path()}. The earlier one stays readable in the history."
                : "Draft of version {$version->version} saved. Nothing on the site changes until you publish it.");
    }

    public function discard(LegalDocument $document, Auditor $auditor): RedirectResponse
    {
        $draft = $document->draft()->firstOrFail();

        $auditor->deleted($draft, "{$document->title}, version {$draft->version} (draft)");
        $draft->delete();

        return redirect()->route('admin.legal.edit', $document)->with('success', 'Draft thrown away. The version in force is unchanged.');
    }

    /** Any version, draft or published, as a visitor would see it, kept out of search. */
    public function preview(LegalDocument $document, int $version): Response
    {
        $shown = $document->versions()->where('version', $version)->firstOrFail();

        return LegalController::render($document, $shown, 'preview');
    }

    protected function nextNumber(LegalDocument $document): int
    {
        // reorder(): versions() is ordered, and MySQL refuses an ORDER BY on
        // a column beside an aggregate.
        return (int) $document->versions()->reorder()->max('version') + 1;
    }
}
