<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A page on the public site, written in the admin panel as a list of blocks.
 *
 * Text is written as Markdown and turned into HTML here, on the server, with
 * any HTML typed into it stripped and unsafe links (javascript: and the like)
 * refused. The page never receives raw HTML from anybody.
 */
class Page extends Model
{
    public const BLOCK_TYPES = ['heading', 'text', 'image', 'callout', 'faq'];

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'draft', 'is_indexable' => true];

    protected function casts(): array
    {
        return [
            'blocks' => 'array',
            'published_at' => 'datetime',
            'is_indexable' => 'boolean',
        ];
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function isPublished(): bool
    {
        return $this->status === 'published' && $this->published_at !== null && $this->published_at->isPast();
    }

    public function path(): string
    {
        return "/pages/{$this->slug}";
    }

    /**
     * The blocks as the public page renders them, with Markdown already turned
     * into safe HTML and images turned into URLs.
     *
     * @return array<int, array<string, mixed>>
     */
    public function renderedBlocks(): array
    {
        return collect($this->blocks ?? [])->map(function (array $block) {
            return match ($block['type'] ?? null) {
                'text' => ['type' => 'text', 'html' => self::markdown($block['markdown'] ?? '')],
                'image' => [
                    'type' => 'image',
                    'url' => filled($block['src'] ?? null) ? Storage::disk('public')->url($block['src']) : null,
                    'alt' => $block['alt'] ?? '',
                    'caption' => $block['caption'] ?? null,
                ],
                'faq' => [
                    'type' => 'faq',
                    'items' => collect($block['items'] ?? [])->map(fn (array $item) => [
                        'question' => $item['question'] ?? '',
                        'html' => self::markdown($item['answer'] ?? ''),
                    ])->values()->all(),
                ],
                default => $block,
            };
        })->values()->all();
    }

    /** Markdown to HTML, with typed HTML stripped and unsafe links refused. */
    public static function markdown(string $markdown): string
    {
        return Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 10,
        ]);
    }

    /** A description for search results when none was written: the summary, else the first text. */
    public function description(): string
    {
        if (filled($this->seo_description)) {
            return $this->seo_description;
        }

        if (filled($this->summary)) {
            return $this->summary;
        }

        $firstText = collect($this->blocks ?? [])->firstWhere('type', 'text')['markdown'] ?? '';

        return Str::of(strip_tags(self::markdown($firstText)))->squish()->limit(155)->toString() ?: $this->title;
    }
}
