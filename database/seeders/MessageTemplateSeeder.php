<?php

namespace Database\Seeders;

use App\Support\MessageCatalogue;
use Illuminate\Database\Seeder;

/**
 * Editable wording for the automated messages.
 *
 * Seeded inactive. Until an administrator turns one on, the application uses
 * the copy written in the notification class, which is the version that has
 * been reviewed. A template is an override, not the default.
 */
class MessageTemplateSeeder extends Seeder
{
    public function run(): void
    {
        MessageCatalogue::sync();

        $this->command?->info('Message templates in place, any new ones switched off.');
    }
}
