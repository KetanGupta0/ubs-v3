<?php

namespace App\Http\Controllers\Client;

use App\Models\Document;
use App\Models\DocumentFolder;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The client's document repository.
 *
 * Files are on a private disk and never served by URL. Every download goes
 * through here so that it is scoped to the signed in client first: a storage
 * path that can be guessed is a document repository with no access control at
 * all, however carefully the listing is filtered.
 */
class DocumentController extends ClientController
{
    public function index(Request $request): Response
    {
        $client = $this->client($request);

        $folderId = $request->integer('folder') ?: null;
        $projectId = $request->integer('project') ?: null;

        $documents = Document::query()
            ->where('client_id', $client->id)
            ->current()
            ->visibleToClient()
            ->when($folderId, fn ($query) => $query->where('folder_id', $folderId))
            ->when($projectId, fn ($query) => $query->where('project_id', $projectId))
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = addcslashes((string) $request->string('q'), '%_\\');
                $query->where('name', 'like', "%{$term}%");
            })
            ->with(['project:id,name', 'uploader:id,name'])
            ->latest('id')
            ->get();

        return Inertia::render('client/documents/Index', [
            'documents' => $documents->map(fn (Document $document) => [
                'id' => $document->id,
                'name' => $document->name,
                'description' => $document->description,
                'size' => $document->sizeLabel(),
                'kind' => $document->kind(),
                'previewable' => $document->isPreviewable(),
                'category' => $document->category,
                'version' => $document->version,
                'project' => $document->project?->name,
                'uploadedBy' => $document->uploader?->name ?? 'Unboundbyte',
                'at' => $document->created_at->format('j M Y'),
                'isNew' => $document->client_viewed_at === null,
            ]),

            'folders' => $this->folderTree($client->id),

            'projects' => Project::query()
                ->forClient($client)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Project $project) => ['value' => $project->id, 'label' => $project->name]),

            'filters' => [
                'folder' => $folderId,
                'project' => $projectId,
                'q' => $request->string('q')->toString(),
            ],
        ]);
    }

    public function download(Request $request, int $document): StreamedResponse
    {
        return $this->stream($request, $document, asAttachment: true);
    }

    public function preview(Request $request, int $document): StreamedResponse
    {
        return $this->stream($request, $document, asAttachment: false);
    }

    public function versions(Request $request, int $document): Response
    {
        $record = $this->own($request, $this->readable($request), $document);

        $versions = collect([$record]);
        $cursor = $record;

        while ($cursor->supersedes_id && $previous = Document::query()->find($cursor->supersedes_id)) {
            $versions->push($previous);
            $cursor = $previous;
        }

        return Inertia::render('client/documents/Versions', [
            'document' => ['id' => $record->id, 'name' => $record->name],
            'versions' => $versions->map(fn (Document $version) => [
                'id' => $version->id,
                'version' => $version->version,
                'size' => $version->sizeLabel(),
                'at' => $version->created_at->format('j M Y, g:i a'),
                'current' => $version->is_current,
            ]),
        ]);
    }

    protected function stream(Request $request, int $document, bool $asAttachment): StreamedResponse
    {
        /** @var Document $record */
        $record = $this->own($request, $this->readable($request), $document);

        abort_unless(Storage::disk('private')->exists($record->path), 404);

        // First open is worth recording: it answers "did they ever see it".
        if ($record->client_viewed_at === null) {
            $record->forceFill(['client_viewed_at' => now()])->save();
        }

        return Storage::disk('private')->{$asAttachment ? 'download' : 'response'}(
            $record->path,
            $record->name,
            ['Content-Type' => $record->mime ?? 'application/octet-stream'],
        );
    }

    /** Every version the client may read, current or superseded. */
    protected function readable(Request $request)
    {
        return Document::query()
            ->where('client_id', $this->client($request)->id)
            ->visibleToClient();
    }

    /** @return array<int, array<string, mixed>> */
    protected function folderTree(int $clientId): array
    {
        $folders = DocumentFolder::query()
            ->where('client_id', $clientId)
            ->withCount(['documents' => fn ($query) => $query->where('is_current', true)->where('visible_to_client', true)])
            ->orderBy('name')
            ->get();

        $build = function (?int $parentId) use ($folders, &$build): array {
            return $folders
                ->where('parent_id', $parentId)
                ->map(fn (DocumentFolder $folder) => [
                    'id' => $folder->id,
                    'name' => $folder->name,
                    'count' => $folder->documents_count,
                    'children' => $build($folder->id),
                ])
                ->values()
                ->all();
        };

        return $build(null);
    }
}
