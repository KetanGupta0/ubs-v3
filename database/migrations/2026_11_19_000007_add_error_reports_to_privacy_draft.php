<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Says in the privacy policy that a fault on the site is reported to Sentry
 * (Step I), and what such a report does and does not carry.
 *
 * Added to the draft in progress if there is one, else to a new draft made
 * from what is published. Nothing is published here: the owner reads and
 * publishes it. Error tracking does not wait for it, because a report is
 * stripped of personal details before it leaves (App\Support\Monitoring\ErrorReports).
 */
return new class extends Migration
{
    public const HEADING = '## When something goes wrong on our website';

    public const SECTION = self::HEADING."\n\n"
        .'When a page or the platform fails, a report of the fault goes to Sentry, an error tracking service run by Functional '
        .'Software, Inc., so that we can find and fix it. A report says which page failed, which browser was used and where in '
        .'our code the fault happened. Before a report leaves our server we take out names, email addresses, phone numbers, '
        .'anything typed into a form and your IP address. Sentry keeps these reports on servers outside India for a limited '
        .'time and then deletes them.';

    public function up(): void
    {
        $document = DB::table('legal_documents')->where('key', 'privacy')->first();

        if (! $document) {
            return;
        }

        $versions = DB::table('legal_document_versions')->where('legal_document_id', $document->id);
        $draft = (clone $versions)->where('status', 'draft')->orderByDesc('version')->first();
        $now = now();
        $note = 'Says what an error report from our website carries, and what it never does.';

        if ($draft) {
            if (! str_contains($draft->body, self::HEADING)) {
                DB::table('legal_document_versions')->where('id', $draft->id)->update([
                    'body' => rtrim($draft->body)."\n\n".self::SECTION,
                    'change_note' => trim(($draft->change_note ? rtrim($draft->change_note, '.').'. ' : '').$note),
                    'updated_at' => $now,
                ]);
            }

            return;
        }

        $published = (clone $versions)->where('status', 'published')->orderByDesc('version')->first();

        if (! $published || str_contains($published->body, self::HEADING)) {
            return;
        }

        DB::table('legal_document_versions')->insert([
            'legal_document_id' => $document->id,
            'version' => $published->version + 1,
            'status' => 'draft',
            'intro' => $published->intro,
            'body' => rtrim($published->body)."\n\n".self::SECTION,
            'change_note' => $note,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        // A draft somebody may since have edited is theirs; nothing is undone.
    }
};
