<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentFolder;
use App\Models\Project;
use App\Models\User;
use App\Services\Admin\Auditor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Uploading and organising what a client can see.
 *
 * A replacement is a new row that points at the one it supersedes, never an
 * overwrite. The previous file stays on disk and stays downloadable, which is
 * the only version history worth keeping.
 */
class DocumentController extends Controller
{
    public function index(Request $request): Response
    {
        $clientId = $request->integer('client') ?: null;
        $projectId = $request->integer('project') ?: null;

        $documents = Document::query()
            ->current()
            ->when($clientId, fn ($query) => $query->where('client_id', $clientId))
            ->when($projectId, fn ($query) => $query->where('project_id', $projectId))
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = addcslashes((string) $request->string('q'), '%_\\');
                $query->where('name', 'like', "%{$term}%");
            })
            ->with(['client:id,name', 'project:id,name', 'uploader:id,name'])
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('admin/documents/Index', [
            'documents' => $documents->through(fn (Document $document) => [
                'id' => $document->id,
                'name' => $document->name,
                'description' => $document->description,
                'size' => $document->sizeLabel(),
                'kind' => $document->kind(),
                'category' => $document->category,
                'version' => $document->version,
                'visibleToClient' => $document->visible_to_client,
                'client' => $document->client?->name,
                'clientId' => $document->client_id,
                'project' => $document->project?->name,
                'uploadedBy' => $document->uploader?->name ?? 'System',
                'viewedByClient' => $document->client_viewed_at?->diffForHumans(),
                'at' => $document->created_at->format('j M Y'),
            ]),
            'clients' => User::query()
                ->role(Role::Client)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $user) => ['value' => $user->id, 'label' => $user->name]),
            'projects' => Project::query()
                ->orderBy('name')
                ->get(['id', 'name', 'client_id'])
                ->map(fn (Project $project) => [
                    'value' => $project->id,
                    'label' => $project->name,
                    'clientId' => $project->client_id,
                ]),
            'folders' => DocumentFolder::query()
                ->when($clientId, fn ($query) => $query->where('client_id', $clientId))
                ->orderBy('name')
                ->get(['id', 'name', 'client_id'])
                ->map(fn (DocumentFolder $folder) => [
                    'value' => $folder->id,
                    'label' => $folder->name,
                    'clientId' => $folder->client_id,
                ]),
            'filters' => [
                'client' => $clientId,
                'project' => $projectId,
                'q' => $request->string('q')->toString(),
            ],
        ]);
    }

    public function store(Request $request, Auditor $auditor): RedirectResponse
    {
        $validated = $this->validatedInput($request, [
            'file' => ['required', 'file', 'max:25600'],
            'client_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', Role::Client->value)],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')],
            'folder_id' => ['nullable', 'integer', Rule::exists('document_folders', 'id')],
            'name' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:1000'],
            'category' => ['nullable', 'string', 'max:40'],
            'visible_to_client' => ['boolean'],
            'supersedes_id' => ['nullable', 'integer', Rule::exists('documents', 'id')],
        ], [
            'file.max' => 'That file is over 25 MB. Send a link to it instead.',
        ]);

        $file = $request->file('file');

        // Stored under the client's own directory with a generated name: the
        // original filename is kept as a label, never as a path, so a crafted
        // name cannot climb out of the directory.
        $path = $file->store("clients/{$validated['client_id']}", 'private');

        $superseded = $validated['supersedes_id']
            ? Document::query()->where('client_id', $validated['client_id'])->find($validated['supersedes_id'])
            : null;

        $document = Document::query()->create([
            'client_id' => $validated['client_id'],
            'project_id' => $validated['project_id'] ?? $superseded?->project_id,
            'folder_id' => $validated['folder_id'] ?? $superseded?->folder_id,
            'name' => $validated['name'] ?: $file->getClientOriginalName(),
            'description' => $validated['description'] ?? null,
            'path' => $path,
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'checksum' => hash_file('sha256', $file->getRealPath()),
            'version' => ($superseded?->version ?? 0) + 1,
            'supersedes_id' => $superseded?->id,
            'category' => $validated['category'] ?? $superseded?->category,
            'visible_to_client' => $this->boolInput($request, 'visible_to_client', true),
            'uploaded_by' => $request->user()->id,
        ]);

        $superseded?->forceFill(['is_current' => false])->save();

        $auditor->action($superseded ? 'document.replaced' : 'document.uploaded', $document, [
            'client_id' => $document->client_id,
            'size' => $document->size,
        ], $document->name);

        return back()->with('success', $superseded
            ? "Replaced. {$document->name} is now version {$document->version}."
            : "{$document->name} uploaded.");
    }

    public function update(Request $request, Document $document, Auditor $auditor): RedirectResponse
    {
        $validated = $this->validatedInput($request, [
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:1000'],
            'category' => ['nullable', 'string', 'max:40'],
            'folder_id' => ['nullable', 'integer', Rule::exists('document_folders', 'id')],
            'visible_to_client' => ['boolean'],
        ]);

        $document->fill($validated);
        $auditor->updated($document, label: $document->name);
        $document->save();

        return back()->with('success', 'Document saved.');
    }

    public function download(Document $document): StreamedResponse
    {
        abort_unless(Storage::disk('private')->exists($document->path), 404);

        return Storage::disk('private')->download($document->path, $document->name);
    }

    public function destroy(Document $document, Auditor $auditor): RedirectResponse
    {
        $auditor->deleted($document, $document->name);

        // The row is soft deleted and the file stays on disk. A document removed
        // by mistake is recoverable; one whose bytes were deleted is not.
        $document->delete();

        if ($document->supersedes_id) {
            Document::query()->where('id', $document->supersedes_id)->update(['is_current' => true]);
        }

        return back()->with('success', 'Document removed from the client view.');
    }

    public function storeFolder(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'client_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', Role::Client->value)],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')],
            'parent_id' => ['nullable', 'integer', Rule::exists('document_folders', 'id')],
            'name' => ['required', 'string', 'max:80'],
        ]);

        DocumentFolder::query()->create($validated);

        return back()->with('success', 'Folder created.');
    }
}
