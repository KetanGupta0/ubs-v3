<?php

namespace Database\Seeders;

use App\Models\MessageTemplate;
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
        $templates = [
            [
                'key' => 'welcome.client.email',
                'name' => 'Welcome email with credentials',
                'channel' => 'mail',
                'subject' => 'Your {{company}} account is ready',
                'body' => "We have set up your account. Everything about your work with us lives there.\n\n"
                    ."Sign in at {{login_url}} with {{email}} and the temporary password {{password}}.\n\n"
                    .'You will be asked to choose your own password the first time you sign in. Please do that promptly, because until you do this password also exists in your inbox.',
                'variables' => ['name', 'email', 'mobile', 'password', 'login_url', 'company'],
            ],
            [
                'key' => 'welcome.client.sms',
                'name' => 'Welcome SMS with credentials',
                'channel' => 'sms',
                'subject' => null,
                'body' => '{{company}} account ready. Sign in at {{login_url}} with {{email}} and temporary password {{password}}. Change it after signing in. Do not share this message.',
                'variables' => ['name', 'email', 'password', 'login_url', 'company'],
            ],
            [
                'key' => 'lead.acknowledgement',
                'name' => 'Enquiry acknowledgement',
                'channel' => 'mail',
                'subject' => 'We have your enquiry ({{reference}})',
                'body' => "Thanks for getting in touch.\n\n"
                    ."Someone from our team will read this properly and reply within one working day. If it is urgent, reply to this email and say so.\n\n"
                    .'Your reference is {{reference}}.',
                'variables' => ['name', 'reference', 'subject', 'company'],
            ],
        ];

        foreach ($templates as $template) {
            MessageTemplate::query()->updateOrCreate(
                ['key' => $template['key']],
                [...$template, 'is_active' => false],
            );
        }

        $this->command?->info('Seeded '.count($templates).' message templates, all inactive.');
    }
}
