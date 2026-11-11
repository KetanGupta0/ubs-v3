<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Services\Admin\Auditor;
use App\Support\ImageUpload;
use App\Support\Seo;
use App\Support\Table\Column;
use App\Support\Table\Filter;
use App\Support\Table\Table;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Pages on the public site, written and published from the admin panel.
 *
 * Every block is checked against what its type may hold and nothing else is
 * kept, so a block can only ever be what it says it is. A page is a draft
 * until it is published, and a draft can be previewed by staff, never seen by
 * anybody else.
 */
class SitePageController extends Controller
{
    public function index(Request $request): Response|HttpResponse
    {
        $table = Table::for(Page::query())
            ->searchable(['title', 'slug'])
            ->defaultSort('-updated_at')
            ->exportName('pages')
            ->columns([
                Column::make('title', 'Page')->sortable(),
                Column::make('path', 'Address'),
                Column::make('status', 'Status')->sortable(),
                Column::make('blocks', 'Blocks')->numeric(),
                Column::make('updated_at', 'Changed')->sortable(),
            ])
            ->filters([
                Filter::select('status', ['draft' => 'Draft', 'published' => 'Published'], 'Status')->placeholder('Any'),
            ])
            ->transform(fn (Page $page) => [
                'id' => $page->id,
                'title' => $page->title,
                'path' => $page->path(),
                'status' => $page->isPublished() ? 'published' : 'draft',
                'blocks' => count($page->blocks ?? []),
                'updated_at' => $page->updated_at?->diffForHumans(),
            ]);

        if ($export = $table->exportResponse($request)) {
            return $export;
        }

        return Inertia::render('admin/pages/Index', ['table' => $table->toArray($request)]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/pages/Form', ['page' => null, 'blockTypes' => Page::BLOCK_TYPES]);
    }

    public function edit(Page $page): Response
    {
        return Inertia::render('admin/pages/Form', [
            'page' => [
                'id' => $page->id,
                'title' => $page->title,
                'slug' => $page->slug,
                'summary' => $page->summary,
                'blocks' => collect($page->blocks ?? [])->map(fn (array $block) => $block['type'] === 'image'
                    ? [...$block, 'previewUrl' => filled($block['src'] ?? null) ? Storage::disk('public')->url($block['src']) : null]
                    : $block)->values(),
                'seo_title' => $page->seo_title,
                'seo_description' => $page->seo_description,
                'is_indexable' => $page->is_indexable,
                'published' => $page->isPublished(),
                'publishedAt' => $page->published_at?->format('j M Y, g:i a'),
                'path' => $page->path(),
            ],
            'blockTypes' => Page::BLOCK_TYPES,
        ]);
    }

    public function store(Request $request, Auditor $auditor): RedirectResponse
    {
        $page = new Page(['created_by' => $request->user()->id]);
        $this->fill($request, $page);
        $page->save();
        $auditor->created($page, $page->title);

        return redirect()
            ->route('admin.pages.edit', $page)
            ->with('success', $page->isPublished() ? "Published at {$page->path()}." : 'Saved as a draft. Preview it before you publish.');
    }

    public function update(Request $request, Page $page, Auditor $auditor): RedirectResponse
    {
        $this->fill($request, $page);
        $auditor->updated($page, label: $page->title);
        $page->save();

        return back()->with('success', $page->isPublished() ? "Saved. It is live at {$page->path()}." : 'Saved as a draft.');
    }

    public function unpublish(Page $page, Auditor $auditor): RedirectResponse
    {
        $page->forceFill(['status' => 'draft']);
        $auditor->updated($page, label: $page->title);
        $page->save();

        return back()->with('success', 'Taken off the site. It is a draft again.');
    }

    public function destroy(Page $page, Auditor $auditor): RedirectResponse
    {
        $auditor->deleted($page, $page->title);
        $page->delete();

        return redirect()->route('admin.pages.index')->with('success', 'Page deleted.');
    }

    /** The page as a visitor would see it, draft or not, marked as a preview and kept out of search. */
    public function preview(Page $page): Response
    {
        return Inertia::render('marketing/CmsPage', [
            'page' => $this->publicArray($page),
            'preview' => true,
            'seo' => [...Seo::for($page->title.' (preview)', $page->description(), $page->path()), 'noindex' => true],
        ]);
    }

    /** @return array<string, mixed> */
    public static function publicArray(Page $page): array
    {
        return [
            'title' => $page->title,
            'summary' => $page->summary,
            'blocks' => $page->renderedBlocks(),
            'updatedAt' => $page->updated_at?->format('j M Y'),
        ];
    }

    /* -------------------------------------------------------------------- */

    protected function fill(Request $request, Page $page): void
    {
        $request->merge([
            'slug' => Str::slug((string) ($request->input('slug') ?: $request->input('title'))),
        ]);

        $validated = $this->validatedInput($request, [
            'title' => ['required', 'string', 'max:160'],
            'slug' => ['required', 'string', 'max:180', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('pages', 'slug')->ignore($page->id)],
            'summary' => ['nullable', 'string', 'max:300'],
            'seo_title' => ['nullable', 'string', 'max:160'],
            'seo_description' => ['nullable', 'string', 'max:158'],
            'is_indexable' => ['boolean'],
            'publish' => ['boolean'],
            'blocks' => ['array', 'max:60'],
            'blocks.*.type' => ['required', Rule::in(Page::BLOCK_TYPES)],
        ], [
            'slug.unique' => 'Another page already uses that address.',
            'seo_description.max' => 'Search engines cut descriptions off at about 158 characters.',
        ]);

        $page->fill([
            'title' => $validated['title'],
            'slug' => $validated['slug'],
            'summary' => $validated['summary'],
            'seo_title' => $validated['seo_title'],
            'seo_description' => $validated['seo_description'],
            'is_indexable' => $this->boolInput($request, 'is_indexable', true),
            // The raw blocks, not $validated's: with a rule on blocks.*.type,
            // validated() keeps only each block's type. blocks() checks every
            // field itself and keeps only what each type allows.
            'blocks' => $this->blocks($request, array_values(array_filter((array) $request->input('blocks', []), 'is_array'))),
            'updated_by' => $request->user()->id,
        ]);

        if ($validated['publish']) {
            $page->status = 'published';
            $page->published_at ??= now();
        }
    }

    /**
     * Keep each block to the fields its type allows, checked, in order.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<int, array<string, mixed>>
     */
    protected function blocks(Request $request, array $blocks): array
    {
        $errors = [];
        $clean = [];

        foreach (array_values($blocks) as $index => $block) {
            $key = "blocks.{$index}";
            // A closure, not an arrow function: an arrow function would take a
            // copy of $errors, and every message it added would be lost.
            $text = function (string $field, int $max, bool $required = true) use ($block, $key, &$errors) {
                return $this->text($block, $field, $max, $required, "{$key}.{$field}", $errors);
            };

            $clean[] = match ($block['type']) {
                'heading' => [
                    'type' => 'heading',
                    'text' => $text('text', 200),
                    'level' => in_array((int) ($block['level'] ?? 2), [2, 3], true) ? (int) ($block['level'] ?? 2) : 2,
                ],
                'text' => ['type' => 'text', 'markdown' => $text('markdown', 20000)],
                'image' => $this->imageBlock($request, $block, $index, $errors),
                'callout' => $this->calloutBlock($block, $key, $errors, $text),
                'faq' => $this->faqBlock($block, $key, $errors),
            };
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }

    protected function text(array $block, string $field, int $max, bool $required, string $key, array &$errors): ?string
    {
        $value = trim((string) ($block[$field] ?? ''));

        if ($required && $value === '') {
            $errors[$key] = 'This cannot be empty.';
        } elseif (mb_strlen($value) > $max) {
            $errors[$key] = "Keep this under {$max} characters.";
        }

        return $value === '' ? null : $value;
    }

    protected function imageBlock(Request $request, array $block, int $index, array &$errors): array
    {
        $key = "blocks.{$index}";
        $upload = $request->file("blocks.{$index}.upload");
        $src = $block['src'] ?? null;

        // An existing picture can only be one this page editor stored.
        if ($src !== null && ! preg_match('#^pages/[0-9a-f-]{36}\.(jpg|png)$#', (string) $src)) {
            $src = null;
        }

        if ($upload instanceof UploadedFile) {
            if ($upload->getSize() > 5 * 1024 * 1024) {
                $errors["{$key}.upload"] = 'Keep pictures under 5 MB.';
            } else {
                $src = ImageUpload::store($upload, 'public', 'pages', 1600, 'jpg', "{$key}.upload");
            }
        }

        if ($src === null && ! isset($errors["{$key}.upload"])) {
            $errors["{$key}.upload"] = 'Choose a picture.';
        }

        return [
            'type' => 'image',
            'src' => $src,
            'alt' => $this->text($block, 'alt', 200, true, "{$key}.alt", $errors),
            'caption' => $this->text($block, 'caption', 300, false, "{$key}.caption", $errors),
        ];
    }

    protected function calloutBlock(array $block, string $key, array &$errors, \Closure $text): array
    {
        $href = trim((string) ($block['button_href'] ?? ''));
        $label = trim((string) ($block['button_label'] ?? ''));

        // A page on this site, or a secure address elsewhere. Nothing that
        // could run as script when clicked.
        if ($href !== '' && ! preg_match('#^(/(?!/)|https://)#', $href)) {
            $errors["{$key}.button_href"] = 'Use a path on this site, like /contact, or an address starting with https://.';
        }

        if (($href === '') !== ($label === '')) {
            $errors["{$key}.button_label"] = 'A button needs both its words and where it goes.';
        }

        return [
            'type' => 'callout',
            'title' => $text('title', 160),
            'body' => $text('body', 1000, false),
            'button_label' => $label ?: null,
            'button_href' => $href ?: null,
        ];
    }

    protected function faqBlock(array $block, string $key, array &$errors): array
    {
        $items = array_values(array_filter((array) ($block['items'] ?? []), 'is_array'));

        if ($items === [] || count($items) > 30) {
            $errors["{$key}.items"] = 'Add between one and thirty questions.';
        }

        return [
            'type' => 'faq',
            'items' => collect($items)->map(fn (array $item, int $i) => [
                'question' => $this->text($item, 'question', 300, true, "{$key}.items.{$i}.question", $errors),
                'answer' => $this->text($item, 'answer', 3000, true, "{$key}.items.{$i}.answer", $errors),
            ])->all(),
        ];
    }
}
