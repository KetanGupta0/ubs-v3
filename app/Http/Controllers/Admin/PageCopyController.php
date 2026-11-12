<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PageCopyEntry;
use App\Services\Admin\Auditor;
use App\Support\PageCopy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The wording of the fixed pages: written as a draft, previewed on the real
 * page, published when it is right.
 */
class PageCopyController extends Controller
{
    public function index(): Response
    {
        $counts = PageCopyEntry::query()
            ->selectRaw('page, sum(case when has_draft then 1 else 0 end) as drafts, sum(case when live is not null then 1 else 0 end) as edited, max(published_at) as published_at')
            ->groupBy('page')
            ->get()
            ->keyBy('page');

        return Inertia::render('admin/copy/Index', [
            'pages' => collect(PageCopy::pages())->map(fn (array $page, string $key) => [
                'key' => $key,
                'label' => $page['label'],
                'path' => $page['path'],
                'fields' => count(PageCopy::fields($key)),
                'edited' => (int) ($counts[$key]->edited ?? 0),
                'drafts' => (int) ($counts[$key]->drafts ?? 0),
                'publishedAt' => isset($counts[$key]) && $counts[$key]->published_at
                    ? Carbon::parse($counts[$key]->published_at)->diffForHumans()
                    : null,
            ])->values(),
        ]);
    }

    public function edit(string $page): Response
    {
        abort_unless(PageCopy::exists($page), 404);

        $entries = PageCopyEntry::query()->where('page', $page)->get()->keyBy('key');
        $definition = PageCopy::pages()[$page];

        return Inertia::render('admin/copy/Edit', [
            'page' => ['key' => $page, 'label' => $definition['label'], 'path' => $definition['path']],
            'sections' => collect($definition['sections'])->map(fn (array $section, string $id) => [
                'id' => $id,
                'label' => $section['label'],
                'fields' => collect($section['fields'])->map(function (array $field, string $key) use ($entries) {
                    $entry = $entries[$key] ?? null;
                    $live = $entry?->live ?? $field['default'];

                    return [
                        'key' => $key,
                        'label' => $field['label'],
                        'type' => $field['type'],
                        'max' => $field['max'],
                        'items' => $field['items'] ?? null,
                        'tokens' => $field['tokens'] ?? [],
                        'default' => $field['default'],
                        'live' => $live,
                        // What the editor starts from: the draft if there is one.
                        'value' => $entry?->has_draft ? ($entry->draft ?? $field['default']) : $live,
                        'hasDraft' => (bool) $entry?->has_draft,
                    ];
                })->values(),
            ])->values(),
        ]);
    }

    /** Keep the wording as typed as a draft. Visitors see no change. */
    public function save(Request $request, string $page): RedirectResponse
    {
        abort_unless(PageCopy::exists($page), 404);

        $fields = PageCopy::fields($page);
        $values = (array) $request->input('values', []);
        $errors = [];
        $clean = [];

        foreach ($fields as $key => $field) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            $value = $this->tidy($field, (string) $values[$key]);
            $problem = $this->problemWith($field, $value);

            if ($problem) {
                $errors["values.{$key}"] = $problem;
            } else {
                $clean[$key] = $value;
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $entries = PageCopyEntry::query()->where('page', $page)->get()->keyBy('key');
        $changed = 0;

        DB::transaction(function () use ($clean, $fields, $entries, $page, $request, &$changed) {
            foreach ($clean as $key => $value) {
                $entry = $entries[$key] ?? new PageCopyEntry(['page' => $page, 'key' => $key]);
                $default = $fields[$key]['default'];
                $published = $entry->live ?? $default;

                if ($value === $published) {
                    // Back to what is live: no draft to keep.
                    if ($entry->exists && $entry->has_draft) {
                        $entry->forceFill(['has_draft' => false, 'draft' => null, 'updated_by' => $request->user()->id])->save();
                        $changed++;
                    }

                    continue;
                }

                $draft = $value === $default ? null : $value;

                if (! $entry->has_draft || $entry->draft !== $draft) {
                    $entry->forceFill(['has_draft' => true, 'draft' => $draft, 'updated_by' => $request->user()->id])->save();
                    $changed++;
                }
            }
        });

        $label = PageCopy::pages()[$page]['label'];

        return back()->with('success', $changed
            ? "Draft saved. Preview it on the {$label} page, then publish when it reads right."
            : 'Nothing has changed from what is saved.');
    }

    /** Make every draft on the page live. */
    public function publish(Request $request, string $page, Auditor $auditor): RedirectResponse
    {
        abort_unless(PageCopy::exists($page), 404);

        $fields = PageCopy::fields($page);
        $drafts = PageCopyEntry::query()->where('page', $page)->where('has_draft', true)->get();

        if ($drafts->isEmpty()) {
            return back()->with('info', 'There are no unpublished changes on this page.');
        }

        $changes = [];

        DB::transaction(function () use ($drafts, $fields, $request, &$changes) {
            foreach ($drafts as $entry) {
                $default = $fields[$entry->key]['default'] ?? null;
                $changes[$entry->key] = ['from' => $entry->live ?? $default, 'to' => $entry->draft ?? $default];

                if ($entry->draft === null) {
                    // Published back to the starting wording: nothing left to keep.
                    $entry->delete();

                    continue;
                }

                $entry->forceFill([
                    'live' => $entry->draft,
                    'has_draft' => false,
                    'draft' => null,
                    'updated_by' => $request->user()->id,
                    'published_at' => now(),
                ])->save();
            }
        });

        PageCopy::forget($page);
        $label = PageCopy::pages()[$page]['label'];
        $auditor->action('copy.published', null, $changes, "Wording of the {$label} page");

        return back()->with('success', count($changes).' '.(count($changes) === 1 ? 'change is' : 'changes are')." live on the {$label} page.");
    }

    /** Throw away every draft on the page. What is live stays. */
    public function discard(string $page): RedirectResponse
    {
        abort_unless(PageCopy::exists($page), 404);

        PageCopyEntry::query()->where('page', $page)->where('has_draft', true)->whereNull('live')->delete();
        PageCopyEntry::query()->where('page', $page)->where('has_draft', true)->update(['has_draft' => false, 'draft' => null]);

        return back()->with('success', 'Unpublished changes thrown away. The page is as visitors see it.');
    }

    protected function tidy(array $field, string $value): string
    {
        $value = str_replace("\r\n", "\n", $value);

        return match ($field['type']) {
            'list' => implode("\n", PageCopy::lines($value)),
            // Paragraphs keep one blank line between them, however many were typed.
            'paragraphs' => trim(preg_replace(["/[ \t]+/", "/ ?\n ?/", "/\n{3,}/"], [' ', "\n", "\n\n"], $value)),
            default => trim(preg_replace('/[ \t]+/', ' ', $value)),
        };
    }

    protected function problemWith(array $field, string $value): ?string
    {
        if ($value === '') {
            return 'Leave something here, or put back the starting wording.';
        }

        if ($field['type'] === 'list') {
            $lines = PageCopy::lines($value);

            if (count($lines) > ($field['items'] ?? 10)) {
                return 'At most '.($field['items'] ?? 10).' lines, or the section stops fitting.';
            }

            foreach ($lines as $line) {
                if (mb_strlen($line) > $field['max']) {
                    return "Keep each line under {$field['max']} characters.";
                }
            }

            return null;
        }

        if (! in_array($field['type'], ['text', 'paragraphs'], true) && str_contains($value, "\n")) {
            return 'Keep this on one line.';
        }

        if (mb_strlen($value) > $field['max']) {
            return "Keep this under {$field['max']} characters, or it stops fitting where it is shown.";
        }

        // A token the field does not fill in would be shown as typed.
        preg_match_all('/\{([a-z_]+)\}/', $value, $matches);
        $unknown = array_diff($matches[1], $field['tokens'] ?? []);

        if ($unknown) {
            $known = $field['tokens'] ?? [];

            return '{'.reset($unknown).'} is not filled in here'.($known ? '. This field takes '.collect($known)->map(fn ($t) => '{'.$t.'}')->implode(' and ').'.' : '.');
        }

        return null;
    }
}
