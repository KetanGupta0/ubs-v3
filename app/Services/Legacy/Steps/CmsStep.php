<?php

namespace App\Services\Legacy\Steps;

use App\Models\Faq;
use App\Models\Page;
use App\Models\SeoOverride;
use App\Models\Setting;
use App\Models\TeamMember;
use App\Services\Legacy\Context;
use App\Services\Legacy\Files;
use App\Services\Legacy\HtmlToMarkdown;
use App\Services\Legacy\Ledger;
use App\Support\ImageUpload;
use App\Support\SiteIdentity;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * The old site's content: FAQs, team, pages, search wording, contact
 * details and email templates.
 *
 * v3's own content was written for v3, so nothing here overwrites it. New
 * FAQs and pages arrive as drafts for a person to read before they are
 * published. Settings fill only what v3 has left empty, and secrets are
 * never imported: mail passwords and payment keys belong in .env. Email
 * templates are listed, not carried: v3's messages are checked and one of
 * v2's mailed a password in plain text.
 */
class CmsStep extends Step
{
    /** v2 setting keys and where each goes in v3, when v3 has nothing there yet. */
    public const SETTINGS = [
        'contact_email' => 'company.email', 'email' => 'company.email', 'support_email' => 'company.email',
        'contact_phone' => 'company.phone', 'phone' => 'company.phone',
        'address' => 'company.address',
        'gstin' => 'company.gstin',
        'social_linkedin' => 'site.social.linkedin', 'linkedin' => 'site.social.linkedin',
        'social_instagram' => 'site.social.instagram', 'instagram' => 'site.social.instagram',
        'social_youtube' => 'site.social.youtube', 'youtube' => 'site.social.youtube',
        'social_whatsapp' => 'site.whatsapp', 'whatsapp' => 'site.whatsapp',
    ];

    public function name(): string
    {
        return 'cms';
    }

    /** Each table is optional here and reported on its own when missing. */
    public function sourceTables(): array
    {
        return [];
    }

    public function run(Context $context): void
    {
        foreach (['faqs', 'team_members', 'custom_pages', 'page_metas', 'settings', 'email_templates'] as $table) {
            if (! Schema::connection('legacy')->hasTable($table)) {
                $context->report->warn('cms', 'source_missing', $table, 'The dump has no such table; nothing imported from it.');

                continue;
            }

            $method = Str::camel($table);
            $context->source()->table($table)->orderBy('id')->each(fn (object $row) => $this->{$method}($context, $row));
        }
    }

    protected function faqs(Context $context, object $row): void
    {
        $report = $context->report;
        $report->count('cms', 'read');

        if ($context->ledger->find('faqs', (int) $row->id)) {
            $report->count('cms', 'unchanged');

            return;
        }

        $key = $this->normalise($row->question);
        $existing = Faq::query()->get(['id', 'question'])->first(fn (Faq $faq) => $this->normalise($faq->question) === $key);

        if ($existing) {
            $context->ledger->record('faqs', (int) $row->id, $existing, Ledger::checksum($row));
            $report->count('cms', 'skipped');
            $report->warn('cms', 'faq_duplicate', "faqs#{$row->id}", 'v3 already answers this question; its own answer was kept.');

            return;
        }

        $faq = Faq::query()->create([
            'question' => Str::limit(trim(strip_tags($row->question)), 250),
            'answer' => trim(HtmlToMarkdown::convert($row->answer)) ?: trim(strip_tags((string) $row->answer)),
            'group' => 'general',
            'sort_order' => (int) $row->sort_order,
            // Read before it goes back on the site.
            'is_published' => false,
        ]);

        $context->ledger->record('faqs', (int) $row->id, $faq, Ledger::checksum($row));
        $report->count('cms', 'created');
    }

    protected function teamMembers(Context $context, object $row): void
    {
        $report = $context->report;
        $report->count('cms', 'read');
        $checksum = Ledger::checksum($row);
        $entry = $context->ledger->find('team_members', (int) $row->id);

        if ($entry && $entry->source_checksum === $checksum) {
            $report->count('cms', 'unchanged');

            return;
        }

        $member = $entry ? TeamMember::query()->find($entry->target_id) : null;
        $isNew = $member === null;
        $member ??= new TeamMember;

        $member->fill([
            'name' => $row->name,
            'role' => Str::limit($row->role, 145, ''),
            'bio' => $row->bio ? Str::limit(trim(strip_tags($row->bio)), 595, '') : null,
            'experience' => $row->experience ? Str::limit($row->experience, 58, '') : null,
            'skills' => collect(explode(',', (string) ($row->tech_stack ?? '')))->map(fn ($s) => trim($s))->filter()->unique()->take(8)->values()->all(),
            'on_about' => in_array($row->type, ['team', 'both'], true),
            'is_mentor' => in_array($row->type, ['mentor', 'both'], true),
            'is_active' => (bool) $row->is_active,
            'sort_order' => (int) $row->sort_order,
        ]);

        if ($isNew && filled($row->avatar_url ?? null)) {
            $member->photo_path = $this->photo($context, "team_members#{$row->id}", (string) $row->avatar_url);
        }

        $member->save();
        $context->ledger->record('team_members', (int) $row->id, $member, $checksum);
        $report->count('cms', $isNew ? 'created' : 'updated');
    }

    /** The photo, from the storage copy or its address, re-encoded like any v3 upload. */
    protected function photo(Context $context, string $source, string $avatar): ?string
    {
        if ($context->report->dryRun) {
            return null;
        }

        $temp = null;

        try {
            if (preg_match('#^https?://#', $avatar)) {
                $response = Http::timeout(15)->get($avatar);

                if (! $response->successful() || ! str_starts_with((string) $response->header('Content-Type'), 'image/')) {
                    throw new \RuntimeException('not an image');
                }

                $temp = tempnam(sys_get_temp_dir(), 'team');
                file_put_contents($temp, $response->body());
                $path = $temp;
            } else {
                $path = Files::locate($context, 'any', preg_replace('#^/?storage/#', '', $avatar));
            }

            if (! $path) {
                throw new \RuntimeException('missing');
            }

            $stored = ImageUpload::store(new UploadedFile($path, basename($path), null, null, true), 'public', 'team', 800, 'jpg', 'photo');
            $context->report->count('cms', 'files');

            return $stored;
        } catch (Throwable) {
            $context->report->warn('cms', 'photo_missing', $source, 'The photo could not be fetched or read; add it at Team and mentors.');

            return null;
        } finally {
            if ($temp) {
                @unlink($temp);
            }
        }
    }

    protected function customPages(Context $context, object $row): void
    {
        $report = $context->report;
        $report->count('cms', 'read');
        $source = "custom_pages#{$row->id}";
        $checksum = Ledger::checksum($row);
        $entry = $context->ledger->find('custom_pages', (int) $row->id);

        if ($entry && $entry->source_checksum === $checksum) {
            $report->count('cms', 'unchanged');

            return;
        }

        $markdown = HtmlToMarkdown::convert($row->content);

        if ($markdown === '') {
            $report->count('cms', 'skipped');
            $report->warn('cms', 'page_empty', $source, 'Nothing readable was left once the HTML was converted; not imported.');

            return;
        }

        $page = $entry ? Page::query()->find($entry->target_id) : null;
        $isNew = $page === null;

        if ($isNew) {
            $slug = Str::slug($row->slug) ?: Str::slug($row->title);

            if (Page::query()->where('slug', $slug)->exists()) {
                $slug .= '-v2';
                $report->warn('cms', 'page_address_changed', $source, "v3 already has a page at that address, so this one is at /pages/{$slug}.");
            }

            $page = new Page(['slug' => $slug, 'status' => 'draft']);
        }

        $page->forceFill([
            'title' => Str::limit($row->title, 155, ''),
            'blocks' => [['type' => 'text', 'markdown' => Str::limit($markdown, 19990, '')]],
            'seo_title' => $row->meta_title ? Str::limit($row->meta_title, 155, '') : null,
            'seo_description' => $row->meta_description ? Str::limit(strip_tags($row->meta_description), 150) : null,
            'is_indexable' => (bool) ($row->is_indexable ?? true),
        ])->save();

        if ($isNew) {
            $report->warn('cms', 'page_draft', $source, 'Imported as a draft, converted from HTML; read it and publish it at Pages.'.(($row->show_in_nav ?? false) ? ' It was in the old site\'s menu: add it at Site menus once published.' : ''));
        }

        $context->ledger->record('custom_pages', (int) $row->id, $page, $checksum);
        $report->count('cms', $isNew ? 'created' : 'updated');
    }

    protected function pageMetas(Context $context, object $row): void
    {
        $report = $context->report;
        $report->count('cms', 'read');
        SeoOverride::syncPages();

        $path = '/'.trim((string) parse_url((string) $row->page_url, PHP_URL_PATH), '/');
        $override = SeoOverride::query()->where('path', $path)->first();

        if (! $override) {
            $report->count('cms', 'skipped');
            $report->warn('cms', 'seo_no_page', "page_metas#{$row->id}", "v3 has no fixed page at {$path}; its search wording was not carried.");

            return;
        }

        $changed = false;

        if (blank($override->title) && filled($row->meta_title)) {
            $override->title = Str::limit($row->meta_title, 70, '');
            $changed = true;
        }

        if (blank($override->description) && filled($row->meta_description)) {
            $override->description = Str::limit(strip_tags($row->meta_description), 150);
            $changed = true;
        }

        if ($changed) {
            $override->save();
        }

        $report->count('cms', $changed ? 'updated' : 'unchanged');
    }

    protected function settings(Context $context, object $row): void
    {
        $report = $context->report;
        $report->count('cms', 'read');
        $key = (string) $row->key;

        // Never carried, whatever it holds.
        if (preg_match('/pass|secret|key|token|smtp|mail_|razorpay|client_id|api/i', $key)) {
            $report->count('cms', 'skipped');
            $report->warn('cms', 'setting_secret', "settings:{$key}", 'A credential; set it in .env by hand if it is still needed.');

            return;
        }

        $target = self::SETTINGS[$key] ?? null;
        $value = trim((string) $row->value);

        if (! $target || $value === '') {
            $report->count('cms', 'skipped');

            if ($target === null) {
                $report->warn('cms', 'setting_not_carried', "settings:{$key}", 'v3 has no such setting, or keeps it elsewhere; not carried.');
            }

            return;
        }

        if (filled(Setting::get($target))) {
            $report->count('cms', 'unchanged');

            return;
        }

        if (str_starts_with($target, 'site.social.') && ! SiteIdentity::belongsTo(substr($target, 12), $value)) {
            $report->count('cms', 'skipped');
            $report->warn('cms', 'setting_rejected', "settings:{$key}", 'Not an https address on that network; not carried.');

            return;
        }

        Setting::put($target, $value, explode('.', $target)[0]);
        $report->count('cms', 'updated');
    }

    protected function emailTemplates(Context $context, object $row): void
    {
        $context->report->count('cms', 'read');
        $context->report->count('cms', 'skipped');
        $context->report->warn('cms', 'template_not_carried', "email_templates:{$row->key}", 'Not carried. v3\'s messages are edited and checked at Settings → Messages; rewrite it there if it is still wanted.');
    }

    protected function normalise(?string $text): string
    {
        return Str::of(strip_tags((string) $text))->lower()->squish()->replaceMatches('/[^a-z0-9 ]/', '')->toString();
    }
}
