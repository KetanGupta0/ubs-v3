<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\LegalDocument;
use App\Models\LegalDocumentVersion;
use App\Support\Markdown;
use App\Support\Seo;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The privacy policy, the terms and the refund policy, as published from the
 * admin panel.
 *
 * The version in force is at /legal/{document}. Every earlier published
 * version stays at /legal/{document}/versions/{n}, kept out of search, so
 * anybody can read what they agreed to. A document with nothing published,
 * and any draft, is not found.
 */
class LegalController extends Controller
{
    public function show(string $document): Response
    {
        $legal = $this->published($document);

        return static::render($legal, $legal->current, 'current');
    }

    public function version(string $document, int $version): Response|RedirectResponse
    {
        $legal = $this->published($document);

        $shown = $legal->versions()
            ->where('status', LegalDocumentVersion::PUBLISHED)
            ->where('version', $version)
            ->firstOrFail();

        if ($shown->is($legal->current)) {
            return redirect($legal->path(), 301);
        }

        return static::render($legal, $shown, 'archived');
    }

    /**
     * One version of a document, as a visitor sees it.
     *
     * $mode is `current` for the version in force, `archived` for an older
     * one and `preview` for staff looking at a draft. Only the version in
     * force is indexed.
     */
    public static function render(LegalDocument $document, LegalDocumentVersion $shown, string $mode): Response
    {
        $published = $document->versions()
            ->where('status', LegalDocumentVersion::PUBLISHED)
            ->get(['id', 'legal_document_id', 'version', 'change_note', 'published_at']);

        $current = $published->first();
        $next = $published->where('version', '>', $shown->version)->sortBy('version')->first();
        $title = $mode === 'archived' ? "{$document->title}, version {$shown->version}" : $document->title;

        $seo = Seo::for(
            "{$title} — Unboundbyte Solutions",
            Markdown::excerpt($shown->intro ?: $shown->body) ?: $document->title,
            $mode === 'archived' ? "{$document->path()}/versions/{$shown->version}" : $document->path(),
        );

        return Inertia::render('marketing/Legal', [
            'document' => [
                'key' => $document->key,
                'title' => $document->title,
                'path' => $document->path(),
            ],
            'version' => [
                'number' => $shown->version,
                'intro' => $shown->intro,
                'html' => $shown->html(),
                'changeNote' => $shown->change_note,
                'publishedAt' => $shown->published_at?->format('j F Y'),
                'supersededAt' => $mode === 'archived' ? $next?->published_at?->format('j F Y') : null,
            ],
            'mode' => $mode,
            'history' => $published->map(fn (LegalDocumentVersion $version) => [
                'number' => $version->version,
                'publishedAt' => $version->published_at?->format('j M Y'),
                'changeNote' => $version->change_note,
                'href' => $version->is($current)
                    ? $document->path()
                    : "{$document->path()}/versions/{$version->version}",
                'current' => $version->is($current),
                'shown' => $version->is($shown),
            ])->values(),
            'seo' => $mode === 'current' ? $seo : [...$seo, 'noindex' => true],
        ]);
    }

    protected function published(string $key): LegalDocument
    {
        $document = LegalDocument::query()->where('key', $key)->with('current')->first();

        abort_if($document?->current === null, 404);

        return $document;
    }
}
