<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A draft of the privacy policy's next version, for the owner to read, change
 * and publish (X19), because greetings and announcements by email (§8) would
 * break a promise version 1 makes: "we do not add you to a mailing list
 * because you contacted us". Nothing is published here, and no greeting goes
 * to somebody who only sent an enquiry until a version saying so is.
 *
 * It starts from whatever is published now, so an edit the owner already made
 * is kept. A policy that no longer makes the promise, or one with a draft in
 * progress, is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        $document = DB::table('legal_documents')->where('key', 'privacy')->first();

        if (! $document || DB::table('legal_document_versions')->where('legal_document_id', $document->id)->where('status', 'draft')->exists()) {
            return;
        }

        $published = DB::table('legal_document_versions')
            ->where('legal_document_id', $document->id)
            ->where('status', 'published')
            ->orderByDesc('version')
            ->first();

        if (! $published || ! str_contains($published->body, 'do not add you to a mailing list')) {
            return;
        }

        $body = str_replace(
            'and we do not add you to a mailing list because you contacted us.',
            'and we do not give your address to anybody else\'s mailing list.',
            $published->body,
        );

        $greetings = "## Greetings and announcements by email\n\n"
            .'If you hold an account with us, or have sent us an enquiry, we may email you a greeting on national days and festivals, '
            .'and now and then an announcement about our courses, products and services. We send them rarely. Every one says why you '
            .'received it and carries a link that stops them in one click, at once, and account holders can also turn them off in their '
            .'account. Stopping them does not stop the messages your account needs, such as sign in codes, receipts, invoices, licence '
            .'keys and class reminders. If you hold an account, the same greeting also appears among your notifications here.';

        // After the section on why we collect it, or at the end if it was renamed.
        $body = preg_match('/^## Why we collect it\n\n[^\n]*$/m', $body)
            ? preg_replace('/^(## Why we collect it\n\n[^\n]*)$/m', "$1\n\n{$greetings}", $body, 1)
            : $body."\n\n{$greetings}";

        $shop = 'If you buy from our shop we also store the billing details you give us (the name, GSTIN and address for the invoice), '
            .'your orders and invoices, the licences they issue, the sites on which the software reports a licence is in use, and the '
            .'downloads made under it.';

        $body = preg_match('/^## What we collect\n\n[^\n]*$/m', $body)
            ? preg_replace('/^(## What we collect\n\n[^\n]*)$/m', "$1 {$shop}", $body, 1)
            : $body."\n\n## Buying from our shop\n\n{$shop}";

        $now = now();

        DB::table('legal_document_versions')->insert([
            'legal_document_id' => $document->id,
            'version' => $published->version + 1,
            'status' => 'draft',
            'intro' => $published->intro,
            'body' => $body,
            'change_note' => 'Says that we email greetings and occasional announcements to account holders and to people who sent an enquiry, and how to stop them; and what we keep when you buy from the shop.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $document = DB::table('legal_documents')->where('key', 'privacy')->first();

        if ($document) {
            DB::table('legal_document_versions')
                ->where('legal_document_id', $document->id)
                ->where('status', 'draft')
                ->where('change_note', 'like', 'Says that we email greetings%')
                ->delete();
        }
    }
};
