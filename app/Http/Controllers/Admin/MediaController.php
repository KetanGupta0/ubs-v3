<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\Page;
use App\Services\Admin\Auditor;
use App\Support\Table\Column;
use App\Support\Table\Table;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The media library: upload a picture once, describe it, use it on any page.
 *
 * A picture a page still shows cannot be deleted: the page would be left with
 * a broken image and nobody would know until a visitor saw it.
 */
class MediaController extends Controller
{
    public function index(Request $request): Response|HttpResponse
    {
        $table = Table::for(Media::query()->with('uploader:id,name'))
            ->searchable(['alt', 'original_name'])
            ->defaultSort('-created_at')
            ->exportName('media')
            ->columns([
                Column::make('alt', 'Picture')->sortable(),
                Column::make('original_name', 'File')->sortable(),
                Column::make('dimensions', 'Size'),
                Column::make('size', 'Weight')->numeric()->sortable(),
                Column::make('used', 'Used on'),
                Column::make('created_at', 'Added')->sortable(),
            ])
            ->transform(function (Media $media) {
                $pages = $media->pages()->get(['id', 'title']);

                return [
                    'id' => $media->id,
                    'url' => $media->url(),
                    'alt' => $media->alt,
                    'original_name' => $media->original_name,
                    'dimensions' => "{$media->width} × {$media->height}",
                    'size' => Number::fileSize($media->size, 1),
                    'used' => $pages->isEmpty() ? 'Not used' : $pages->pluck('title')->implode(', '),
                    'usedCount' => $pages->count(),
                    'created_at' => $media->created_at?->format('j M Y'),
                    'uploader' => $media->uploader?->name,
                ];
            });

        if ($export = $table->exportResponse($request)) {
            return $export;
        }

        return Inertia::render('admin/media/Index', [
            'table' => $table->toArray($request),
            'summary' => [
                'count' => Media::query()->count(),
                'size' => Number::fileSize((int) Media::query()->sum('size'), 1),
            ],
        ]);
    }

    public function store(Request $request, Auditor $auditor): RedirectResponse
    {
        $validated = $this->validatedInput($request, [
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'alt' => ['required', 'string', 'max:300'],
        ], [
            'file.required' => 'Choose a picture.',
            'file.mimes' => 'Use a JPG, PNG or WebP.',
            'file.max' => 'Keep pictures under 8 MB.',
            'alt.required' => 'Describe the picture for somebody who cannot see it.',
        ]);

        $media = Media::upload($request->file('file'), $validated['alt'], $request->user());
        $auditor->created($media, $media->alt);

        return back()->with('success', 'Added to the library. Pick it from any page\'s picture block.');
    }

    public function update(Request $request, Media $media, Auditor $auditor): RedirectResponse
    {
        $validated = $this->validatedInput($request, [
            'alt' => ['required', 'string', 'max:300'],
        ], ['alt.required' => 'Describe the picture for somebody who cannot see it.']);

        $media->alt = trim($validated['alt']);
        $auditor->updated($media, label: $media->alt);
        $media->save();

        return back()->with('success', 'Description saved. Pages keep the description they were written with.');
    }

    public function destroy(Media $media, Auditor $auditor): RedirectResponse
    {
        $pages = $media->pages()->pluck('title');

        if ($pages->isNotEmpty()) {
            return back()->with('error', 'Still shown on '.$pages->implode(', ').'. Take it off those pages first.');
        }

        $auditor->deleted($media, $media->alt);
        Storage::disk('public')->delete($media->path);
        $media->delete();

        return back()->with('success', 'Deleted from the library, file included.');
    }

    /** Pictures for the chooser in the page editor, newest first, a page at a time. */
    public function picker(Request $request): JsonResponse
    {
        $page = Media::query()->latest('id')->paginate(24);

        return response()->json([
            'items' => collect($page->items())->map->toPickerArray()->values(),
            'nextPage' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
        ]);
    }
}
