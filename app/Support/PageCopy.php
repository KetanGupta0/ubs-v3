<?php

namespace App\Support;

use App\Models\PageCopyEntry;
use Illuminate\Support\Facades\Cache;

/**
 * The wording of the site's fixed pages, editable in the panel.
 *
 * Every field has a starting wording in database/data/page_copy.php. An edit
 * is saved as a draft, which staff can preview on the real page with
 * ?preview=copy (kept out of search), and reaches visitors only when the page
 * is published. Only words are editable; layout stays in the page's code.
 */
class PageCopy
{
    protected static ?array $definitions = null;

    /** @return array<string, array{label: string, path: string, sections: array}> */
    public static function pages(): array
    {
        return static::$definitions ??= require database_path('data/page_copy.php');
    }

    public static function exists(string $page): bool
    {
        return isset(static::pages()[$page]);
    }

    /** @return array<string, array{label: string, type: string, max: int, default: string, items?: int, tokens?: array}> */
    public static function fields(string $page): array
    {
        return collect(static::pages()[$page]['sections'] ?? [])
            ->flatMap(fn (array $section) => $section['fields'])
            ->all();
    }

    /**
     * The wording a page renders: live for visitors, drafts for staff
     * previewing. Lists come back as arrays.
     *
     * @return array<string, string|array<int, string>>
     */
    public static function for(string $page): array
    {
        $preview = static::previewing();
        $fields = static::fields($page);

        $stored = $preview
            ? static::entries($page)
            : Cache::rememberForever("page-copy.{$page}", fn () => static::entries($page));

        return collect($fields)->map(function (array $field, string $key) use ($stored, $preview) {
            $entry = $stored[$key] ?? null;
            $value = match (true) {
                $preview && $entry && $entry['has_draft'] => $entry['draft'],
                $entry !== null => $entry['live'],
                default => null,
            } ?? $field['default'];

            return $field['type'] === 'list' ? static::lines($value) : $value;
        })->all();
    }

    /**
     * Whether this request is staff looking at unpublished wording.
     *
     * Asked for with ?preview=copy, and honoured only for somebody who may
     * edit the site; anybody else gets the live page.
     */
    public static function previewing(): bool
    {
        $request = request();

        return $request->query('preview') === 'copy'
            && (bool) $request->user()?->hasPermission('content.manage');
    }

    /** @return array<int, string> */
    public static function lines(?string $value): array
    {
        return collect(preg_split('/\r?\n/', (string) $value))
            ->map(fn (string $line) => trim($line))
            ->filter()
            ->values()
            ->all();
    }

    public static function forget(string $page): void
    {
        Cache::forget("page-copy.{$page}");
    }

    /** @return array<string, array{live: ?string, has_draft: bool, draft: ?string}> */
    protected static function entries(string $page): array
    {
        return PageCopyEntry::query()
            ->where('page', $page)
            ->get(['key', 'live', 'has_draft', 'draft'])
            ->mapWithKeys(fn (PageCopyEntry $entry) => [$entry->key => [
                'live' => $entry->live,
                'has_draft' => $entry->has_draft,
                'draft' => $entry->draft,
            ]])
            ->all();
    }
}
