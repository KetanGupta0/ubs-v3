<?php

namespace App\Support;

use App\Models\Lead;
use App\Models\MessageTemplate;
use App\Models\Solution;
use App\Models\User;
use App\Notifications\LeadAcknowledgement;
use App\Notifications\WelcomeCredentials;
use Illuminate\Notifications\Notification;

/**
 * Every automated message whose wording can be changed in the panel.
 *
 * One entry per message: when it is sent, what it can say (each placeholder
 * with what it means and a sample), what it must say, and its starting
 * wording. The settings screen, the seeder and the save check all read this,
 * so a placeholder cannot be offered on screen that the message does not
 * actually fill in.
 */
class MessageCatalogue
{
    public const MESSAGES = [
        'welcome.client.email' => [
            'name' => 'Welcome email with credentials',
            'channel' => 'mail',
            'sent' => 'When an administrator creates an account, or sends credentials again.',
            'variables' => [
                'name' => ['The person\'s name', 'Asha Verma'],
                'email' => ['The email address they sign in with', 'asha@example.com'],
                'mobile' => ['Their mobile number, if there is one', '+91 98765 43210'],
                'password' => ['Their temporary password', 'Tq7-mVx4-Lp2'],
                'login_url' => ['The sign in address', '/login'],
                'company' => ['The company\'s trading name', 'Unboundbyte Solutions'],
            ],
            // Without these the person receives a welcome and no way in.
            'required' => ['email', 'password'],
            'subject' => 'Your {{company}} account is ready',
            'body' => "We have set up your account. Everything about your work with us lives there.\n\n"
                ."Sign in at {{login_url}} with {{email}} and the temporary password {{password}}.\n\n"
                .'You will be asked to choose your own password the first time you sign in. Please do that promptly, because until you do this password also exists in your inbox.',
        ],
        'welcome.client.sms' => [
            'name' => 'Welcome SMS with credentials',
            'channel' => 'sms',
            'sent' => 'Alongside the welcome email, when the account has a mobile number.',
            'variables' => [
                'name' => ['The person\'s name', 'Asha Verma'],
                'email' => ['The email address they sign in with', 'asha@example.com'],
                'password' => ['Their temporary password', 'Tq7-mVx4-Lp2'],
                'login_url' => ['The sign in address', '/login'],
                'company' => ['The company\'s trading name', 'Unboundbyte Solutions'],
            ],
            'required' => ['password'],
            'subject' => null,
            'body' => '{{company}} account ready. Sign in at {{login_url}} with {{email}} and temporary password {{password}}. Change it after signing in. Do not share this message.',
        ],
        'lead.acknowledgement' => [
            'name' => 'Enquiry acknowledgement',
            'channel' => 'mail',
            'sent' => 'To whoever sends an enquiry through the site, straight away.',
            'variables' => [
                'name' => ['Their name', 'Asha Verma'],
                'reference' => ['The enquiry\'s reference', 'ENQ-7K2M9'],
                'subject' => ['What they asked about, if they picked something', 'Clinic management system'],
                'company' => ['The company\'s trading name', 'Unboundbyte Solutions'],
            ],
            // The reference is how a reply finds its enquiry.
            'required' => ['reference'],
            'subject' => 'We have your enquiry ({{reference}})',
            'body' => "Thanks for getting in touch.\n\n"
                ."Someone from our team will read this properly and reply within one working day. If it is urgent, reply to this email and say so.\n\n"
                .'Your reference is {{reference}}.',
        ],
    ];

    /** Make sure every message has its row, switched off, so nothing depends on a seeder having run. */
    public static function sync(): void
    {
        foreach (self::MESSAGES as $key => $message) {
            MessageTemplate::query()->firstOrCreate(['key' => $key], [
                'name' => $message['name'],
                'channel' => $message['channel'],
                'subject' => $message['subject'],
                'body' => $message['body'],
                'variables' => array_keys($message['variables']),
                'is_active' => false,
            ]);
        }
    }

    public static function for(string $key): ?array
    {
        return self::MESSAGES[$key] ?? null;
    }

    /** @return array<string, string> the sample value of each placeholder */
    public static function samples(string $key): array
    {
        return collect(self::MESSAGES[$key]['variables'] ?? [])
            ->map(fn (array $variable, string $name) => $name === 'login_url' ? url($variable[1]) : $variable[1])
            ->all();
    }

    /**
     * The real notification, built with sample details, for a preview or a test.
     *
     * @return array{0: Notification, 1: object} the notification and who it is for
     */
    public static function sampleNotification(string $key): array
    {
        $samples = self::samples($key);

        return match ($key) {
            'welcome.client.email', 'welcome.client.sms' => [
                new WelcomeCredentials(
                    new User(['name' => $samples['name'], 'email' => $samples['email'], 'mobile' => $samples['mobile'] ?? '+91 98765 43210']),
                    $samples['password'],
                ),
                new User(['name' => $samples['name'], 'email' => $samples['email'], 'mobile' => '+91 98765 43210']),
            ],
            'lead.acknowledgement' => (function () use ($samples) {
                $lead = new Lead(['name' => $samples['name'], 'email' => 'asha@example.com', 'reference' => $samples['reference']]);
                $lead->setRelation('solution', new Solution(['title' => $samples['subject']]));

                return [new LeadAcknowledgement($lead), new User(['name' => $samples['name'], 'email' => 'asha@example.com'])];
            })(),
        };
    }
}
