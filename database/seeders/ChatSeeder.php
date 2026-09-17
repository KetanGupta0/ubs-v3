<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Batch;
use App\Models\Project;
use App\Models\User;
use App\Services\Chat\Messenger;
use App\Services\Chat\Rooms;
use Illuminate\Database\Seeder;

/**
 * A conversation already in progress, for development.
 *
 * An empty chat screen tells you nothing about whether chat works. This gives
 * one client thread about a real project and one batch group with several
 * students in it, so read receipts, grouping by sender and the unread badge all
 * have something to be right or wrong about.
 *
 * Text only: a seeded photo would mean committing a binary, and a voice note
 * would mean committing a binary that nobody can read.
 */
class ChatSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('The worked example is never seeded in production.');

            return;
        }

        $rooms = app(Rooms::class);
        $messenger = app(Messenger::class);

        $admin = User::query()->role(Role::Admin)->first();
        $client = User::query()->role(Role::Client)->first();

        if ($admin && $client) {
            $project = Project::query()->where('client_id', $client->id)->first();
            $conversation = $rooms->forClient($client, $project);

            if ($conversation->messages()->doesntExist()) {
                $script = [
                    [$client, 'Morning. The picking screens on staging look right, but scanning on the Zebra device beeps twice and adds the item once. Is that expected?'],
                    [$admin, 'Not expected. That reads like the scanner sending both a keystroke and a hardware event. We will reproduce it this afternoon and come back.'],
                    [$admin, 'Reproduced. The device is configured to send an Enter after the barcode, and our field was already submitting on Enter. Fix is ours, going to staging today.'],
                    [$client, 'Good. Also, can the despatch note show the driver name? The warehouse manager asked.'],
                    [$admin, 'It can. That is a small change — I will raise it against the current milestone rather than a new quotation.'],
                ];

                foreach ($script as $index => [$sender, $body]) {
                    $message = $messenger->sendText($conversation, $sender, $body);

                    // Spread across two days, so the day separator has work to do.
                    $message->forceFill([
                        'sent_at' => now()->subDays($index < 3 ? 1 : 0)->subMinutes((5 - $index) * 37),
                    ])->save();
                }

                $conversation->forceFill([
                    'last_message_at' => $conversation->messages()->max('sent_at'),
                ])->save();
            }
        }

        $batch = Batch::query()->where('status', 'running')->with('course')->first();

        if ($batch && $admin) {
            $conversation = $rooms->forBatch($batch);

            if ($conversation->messages()->doesntExist()) {
                $students = $conversation->participants()
                    ->where('role', 'member')
                    ->with('user')
                    ->get()
                    ->pluck('user')
                    ->filter();

                $script = [
                    [$admin, 'Reminder: session five moves to Thursday this week. Same link, same time.'],
                    [$students->get(0), 'Thanks. Is the project brief the one on the assignments page, or will there be another?'],
                    [$admin, 'That one. Start it this week rather than the night before — most of the mark is for what you understood, and that shows in the commits.'],
                    [$students->get(1), 'My migration keeps failing with a foreign key error. Is there a session recording that covers that bit?'],
                    [$admin, 'Session two, from about forty minutes in. If it still fails after that, paste the error here and we will look.'],
                ];

                foreach ($script as $index => [$sender, $body]) {
                    if (! $sender) {
                        continue;
                    }

                    $message = $messenger->sendText($conversation, $sender, $body);

                    $message->forceFill([
                        'sent_at' => now()->subHours(6 - $index),
                    ])->save();
                }

                $conversation->forceFill([
                    'last_message_at' => $conversation->messages()->max('sent_at'),
                ])->save();
            }
        }

        $this->command?->info('Seeded a client thread and a batch group.');
    }
}
