<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LegalDocument;
use App\Models\Page;
use App\Models\SeoOverride;
use App\Models\SiteMenu;
use App\Models\SiteMenuItem;
use App\Services\Admin\Auditor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The public site's header and footer menus.
 *
 * A link goes to a page on this site or to a secure address elsewhere. A path
 * on this site is checked against what the site actually serves when it is
 * saved, so a typo becomes an error on this screen rather than a 404 in the
 * footer of every page.
 */
class SiteMenuController extends Controller
{
    public function index(): Response
    {
        $menus = SiteMenu::query()->with('items')->get()->keyBy('key');

        return Inertia::render('admin/menus/Index', [
            'menus' => collect(SiteMenu::MENUS)
                ->filter(fn (array $definition, string $key) => $menus->has($key))
                ->map(fn (array $definition, string $key) => [
                    'key' => $key,
                    'name' => $definition['name'],
                    'titled' => $definition['titled'],
                    'max' => $definition['max'],
                    'title' => $menus[$key]->title,
                    'items' => $menus[$key]->items->map(fn (SiteMenuItem $item) => [
                        'label' => $item->label,
                        'href' => $item->href,
                    ])->values(),
                ])
                ->values(),
            'suggestions' => $this->suggestions(),
        ]);
    }

    public function update(Request $request, SiteMenu $menu, Auditor $auditor): RedirectResponse
    {
        $definition = SiteMenu::MENUS[$menu->key] ?? abort(404);
        $max = $definition['max'];

        $validated = $this->validatedInput($request, [
            'title' => [$definition['titled'] ? 'required' : 'nullable', 'string', 'max:60'],
            'items' => ['array', "max:{$max}"],
            'items.*.label' => ['required', 'string', 'max:40'],
            'items.*.href' => ['required', 'string', 'max:300'],
        ], [
            'items.max' => "This menu holds at most {$max} links, or it stops fitting where it is shown.",
            'items.*.label.required' => 'Give the link its words.',
            'items.*.href.required' => 'Say where the link goes.',
        ]);

        $items = collect($validated['items'] ?? [])->values()->map(fn (array $item) => [
            'label' => trim($item['label']),
            'href' => trim($item['href']),
        ]);

        $errors = [];
        foreach ($items as $index => $item) {
            if ($problem = $this->problemWith($item['href'])) {
                $errors["items.{$index}.href"] = $problem;
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $describe = fn ($links) => collect($links)->map(fn ($link) => "{$link['label']} → {$link['href']}")->implode(', ');
        $before = ['title' => $menu->title, 'links' => $describe($menu->items->map->only(['label', 'href']))];

        DB::transaction(function () use ($menu, $items, $validated, $definition) {
            if ($definition['titled']) {
                $menu->update(['title' => trim($validated['title'])]);
            }

            $menu->items()->delete();

            foreach ($items as $index => $item) {
                $menu->items()->create([...$item, 'sort_order' => $index + 1]);
            }

            $menu->touch();
        });

        SiteMenu::forget();

        $after = ['title' => $menu->title, 'links' => $describe($items)];
        $changes = collect($after)
            ->filter(fn ($value, $key) => $before[$key] !== $value)
            ->map(fn ($value, $key) => ['from' => $before[$key], 'to' => $value])
            ->all();

        if ($changes) {
            $auditor->action('updated', $menu, $changes, "Site menu: {$definition['name']}");
        }

        return back()->with('success', "{$definition['name']} saved. It is live on every public page.");
    }

    /** Why a link cannot be saved, or null when it can. */
    protected function problemWith(string $href): ?string
    {
        if (str_starts_with($href, 'https://')) {
            return filter_var($href, FILTER_VALIDATE_URL) ? null : 'That is not a complete web address.';
        }

        if (! preg_match('#^/(?!/)#', $href)) {
            return 'Use a path on this site, like /contact, or an address starting with https://.';
        }

        $path = strtok(strtok($href, '#'), '?') ?: '/';

        try {
            $route = app('router')->getRoutes()->match(Request::create($path, 'GET'));
        } catch (HttpException) {
            return "Nothing on this site answers at {$path}. Check the spelling.";
        }

        // Routes that match any address underneath them still need the thing
        // itself to be on the site.
        $missing = match ($route->getName()) {
            'pages.show' => ! Page::query()->published()->where('slug', $route->parameter('slug'))->exists(),
            'legal' => ! collect(LegalDocument::published())->contains('href', $path),
            default => false,
        };

        return $missing ? "{$path} is not published, so visitors would get a page that is not there." : null;
    }

    /**
     * Pages a link can go to, for the address box to offer.
     *
     * @return array<int, array{href: string, label: string}>
     */
    protected function suggestions(): array
    {
        return collect(SeoOverride::PAGES)
            ->map(fn (string $label, string $href) => ['href' => $href, 'label' => $label])
            ->values()
            ->concat([
                ['href' => '/login', 'label' => 'Sign in'],
                ['href' => '/register', 'label' => 'Student sign up'],
            ])
            ->concat(Page::query()->published()->orderBy('title')->get(['title', 'slug'])
                ->map(fn (Page $page) => ['href' => $page->path(), 'label' => $page->title]))
            ->concat(collect(LegalDocument::published())->map(fn (array $document) => ['href' => $document['href'], 'label' => $document['title']]))
            ->unique('href')
            ->values()
            ->all();
    }
}
