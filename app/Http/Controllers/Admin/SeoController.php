<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SeoOverride;
use App\Services\Admin\Auditor;
use App\Support\ImageUpload;
use App\Support\Table\Column;
use App\Support\Table\Filter;
use App\Support\Table\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Search wording for the site's fixed pages: what a search result and a
 * shared link show. Anything left empty keeps the page's own wording.
 */
class SeoController extends Controller
{
    public function index(Request $request): Response
    {
        SeoOverride::syncPages();

        $table = Table::for(SeoOverride::query()->whereIn('path', array_keys(SeoOverride::PAGES)))
            ->searchable(['label', 'path', 'title'])
            ->defaultSort('path')
            ->exportName('search-wording')
            ->columns([
                Column::make('label', 'Page')->sortable(),
                Column::make('path', 'Address')->sortable(),
                Column::make('title', 'Search title'),
                Column::make('state', 'Set here'),
            ])
            ->filters([
                Filter::boolean('changed', 'Changed here only')->using(fn (Builder $query) => $query->where(fn ($q) => $q
                    ->whereNotNull('title')->orWhereNotNull('description')->orWhereNotNull('image_path')->orWhere('noindex', true))),
            ])
            ->transform(fn (SeoOverride $row) => [
                'id' => $row->id,
                'label' => $row->label,
                'path' => $row->path,
                'title' => $row->title ?? '—',
                'state' => collect([
                    $row->title ? 'title' : null,
                    $row->description ? 'description' : null,
                    $row->image_path ? 'image' : null,
                    $row->noindex ? 'hidden from search' : null,
                ])->filter()->join(', ') ?: 'Page default',
                'form' => [
                    'title' => $row->title,
                    'description' => $row->description,
                    'noindex' => $row->noindex,
                    'imageUrl' => $row->image_path ? Storage::disk('public')->url($row->image_path) : null,
                ],
            ]);

        return Inertia::render('admin/Seo', ['table' => $table->toArray($request)]);
    }

    public function update(Request $request, SeoOverride $override, Auditor $auditor): RedirectResponse
    {
        abort_unless(array_key_exists($override->path, SeoOverride::PAGES), 404);

        $validated = $this->validatedInput($request, [
            'title' => ['nullable', 'string', 'max:70'],
            'description' => ['nullable', 'string', 'max:158'],
            'noindex' => ['boolean'],
            'image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
            'remove_image' => ['boolean'],
        ], [
            'title.max' => 'Search results show about 60 characters of a title; keep it under 70.',
            'description.max' => 'Search engines cut descriptions off at about 158 characters.',
        ]);

        $override->fill([
            'title' => $validated['title'],
            'description' => $validated['description'],
            'noindex' => $validated['noindex'],
            'updated_by' => $request->user()->id,
        ]);

        if ($validated['remove_image']) {
            $override->image_path = null;
        }

        if ($file = $request->file('image')) {
            // The size link previews use: 1200 by 630.
            $override->image_path = ImageUpload::store($file, 'public', 'seo', 1200, 'jpg', 'image');
        }

        $auditor->updated($override, label: $override->label);
        $override->save();

        return back()->with('success', "Saved. {$override->label} shows this in search results from now on.");
    }
}
