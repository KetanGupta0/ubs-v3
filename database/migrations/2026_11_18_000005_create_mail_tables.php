<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Greetings and announcements by email (§8).
 *
 * Promotional mail, kept apart from the service mail an account needs: it has
 * its own do-not-mail list, which service mail never reads, and it goes out a
 * little at a time, inside a daily budget, because the hosting's mail allows
 * a hundred a day in all (X8) and a sign in code must never wait behind a
 * festival greeting.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Addresses that get no greetings or announcements. Service mail ignores this.
        Schema::create('mail_suppressions', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique(); // lower case
            $table->string('reason', 20); // unsubscribed, bounced, complained, manual
            $table->string('note', 300)->nullable();
            $table->unsignedBigInteger('campaign_id')->nullable(); // which mail they stopped from
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        /*
         * Days worth a greeting, set by the admin (X7). A national day falls on
         * the same date every year; a festival's date moves, so next year's is
         * a guess until a person confirms it, and an unconfirmed one is not sent.
         */
        Schema::create('occasions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->date('date')->nullable(); // the next time it falls
            $table->string('repeats', 20)->default('confirm_yearly'); // same_date, confirm_yearly
            $table->boolean('date_confirmed')->default(false);
            $table->string('send_time', 5)->default('08:00');
            $table->json('audience'); // students, clients, enquiries
            $table->string('subject', 200);
            $table->text('message');
            $table->foreignId('media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->boolean('is_active')->default(false);
            $table->date('last_sent_on')->nullable();
            $table->date('reminded_for')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        /*
         * One send: a broadcast, or one year's greeting for an occasion. The
         * words are copied in when it is made, so editing the occasion later
         * does not rewrite what went out.
         */
        Schema::create('mail_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 10); // broadcast, greeting
            $table->foreignId('occasion_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject', 200);
            $table->text('message');
            $table->foreignId('media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->json('audience');
            $table->string('status', 20)->default('draft'); // draft, scheduled, sending, sent, cancelled
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            // A greeting is no use the day after: what has not gone by then does not go.
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
        });

        Schema::create('mail_campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('mail_campaigns')->cascadeOnDelete();
            $table->string('email');
            $table->string('name')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 10)->default('queued'); // queued, sent, skipped, failed
            $table->string('skip_reason', 30)->nullable();
            $table->string('error', 300)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'email']);
            $table->index(['campaign_id', 'status']);
            $table->index(['email', 'status', 'sent_at']);
            $table->index(['status', 'sent_at']);
        });

        // The national days, each switched off until the admin turns it on.
        $now = now();
        $next = function (int $month, int $day) use ($now) {
            $date = $now->copy()->setDate((int) $now->format('Y'), $month, $day)->startOfDay();

            return $date->isBefore($now->copy()->startOfDay()) ? $date->addYear() : $date;
        };

        foreach ([
            ['Republic Day', 1, 26, 'Happy Republic Day, {first_name}', "Wishing you and your family a happy Republic Day.\n\nFrom all of us at Unboundbyte."],
            ['Independence Day', 8, 15, 'Happy Independence Day, {first_name}', "Wishing you and your family a happy Independence Day.\n\nFrom all of us at Unboundbyte."],
            ['Gandhi Jayanti', 10, 2, 'Remembering Gandhiji today', "On Gandhi Jayanti, our good wishes to you and your family, {first_name}.\n\nFrom all of us at Unboundbyte."],
        ] as [$name, $month, $day, $subject, $message]) {
            DB::table('occasions')->insert([
                'name' => $name,
                'date' => $next($month, $day)->toDateString(),
                'repeats' => 'same_date',
                'date_confirmed' => true,
                'send_time' => '08:00',
                'audience' => json_encode(['students', 'clients', 'enquiries']),
                'subject' => $subject,
                'message' => $message,
                'is_active' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_campaign_recipients');
        Schema::dropIfExists('mail_campaigns');
        Schema::dropIfExists('occasions');
        Schema::dropIfExists('mail_suppressions');
    }
};
