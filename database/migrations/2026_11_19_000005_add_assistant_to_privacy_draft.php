<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Says in the privacy policy what happens to what a visitor types into the
 * assistant (§9.2), before it can be switched on: it goes to Anthropic to be
 * answered, it is kept for a limited time, and contact details are passed on
 * only when the visitor presses Send.
 *
 * Added to the draft in progress if there is one, else to a new draft made
 * from what is published. Nothing is published here: the owner reads and
 * publishes it. The version it lands in is recorded, and the assistant cannot
 * be switched on until that version, or a later one, is published.
 */
return new class extends Migration
{
    public const SECTION = "## The assistant on our website\n\n"
        .'Our website has an assistant that answers questions about our products, services and courses. It is an AI. What you type '
        .'into it is sent to Anthropic, the company in the United States that makes the Claude models it runs on, to produce each '
        .'answer. We keep the conversation for no more than 90 days, so we can see where our website does not answer people\'s '
        .'questions, and then delete it. It never passes your name or contact details to our team by itself: it shows you what it '
        .'would send, and only sends it when you press Send. Please do not type passwords, card numbers or other sensitive details '
        .'into it.';

    public function up(): void
    {
        $document = DB::table('legal_documents')->where('key', 'privacy')->first();

        if (! $document) {
            return;
        }

        $versions = DB::table('legal_document_versions')->where('legal_document_id', $document->id);
        $draft = (clone $versions)->where('status', 'draft')->orderByDesc('version')->first();
        $now = now();

        if ($draft) {
            if (! str_contains($draft->body, '## The assistant on our website')) {
                DB::table('legal_document_versions')->where('id', $draft->id)->update([
                    'body' => rtrim($draft->body)."\n\n".self::SECTION,
                    'change_note' => trim(($draft->change_note ? rtrim($draft->change_note, '.').'. ' : '').'Says what happens to what you type into the assistant on our website.'),
                    'updated_at' => $now,
                ]);
            }
            $version = $draft->version;
        } else {
            $published = (clone $versions)->where('status', 'published')->orderByDesc('version')->first();

            if (! $published) {
                return;
            }

            $version = $published->version + 1;
            DB::table('legal_document_versions')->insert([
                'legal_document_id' => $document->id,
                'version' => $version,
                'status' => 'draft',
                'intro' => $published->intro,
                'body' => rtrim($published->body)."\n\n".self::SECTION,
                'change_note' => 'Says what happens to what you type into the assistant on our website.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Through the model, which keeps the settings cache honest.
        Setting::put('assistant.policy_version', (int) $version, 'assistant');
    }

    public function down(): void
    {
        Setting::query()->where('key', 'assistant.policy_version')->delete();
    }
};
