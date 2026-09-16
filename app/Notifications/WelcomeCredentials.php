<?php

namespace App\Notifications;

use App\Models\MessageTemplate;
use App\Models\User;
use App\Notifications\Channels\SmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The welcome message carrying a new account's first password.
 *
 * Sent to both the email address and the mobile number, because an
 * administrator creating an account cannot know which one the person actually
 * checks, and an account nobody can get into is worse than a duplicate message.
 *
 * The password is deliberately temporary. It exists in two inboxes and a
 * delivery log from the moment this is sent, which is exactly why the account
 * is forced to replace it before it can be used for anything.
 */
class WelcomeCredentials extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public User $account,
        public string $temporaryPassword,
        public ?string $personalNote = null,
    ) {}

    public function via(object $notifiable): array
    {
        $channels = [];

        if (filled($notifiable->email)) {
            $channels[] = 'mail';
        }

        if (filled($notifiable->mobile)) {
            $channels[] = SmsChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $company = config('app.name');
        $signIn = url('/login');

        $template = MessageTemplate::find_by_key('welcome.client.email');

        $values = [
            'name' => $this->account->name,
            'email' => $this->account->email,
            'mobile' => $this->account->mobile,
            'password' => $this->temporaryPassword,
            'login_url' => $signIn,
            'company' => $company,
        ];

        $message = (new MailMessage)
            ->subject($template?->renderSubject($values) ?? "Your {$company} account is ready")
            ->greeting("Hello {$this->account->name},");

        if ($template) {
            // An administrator has customised the wording, so use it verbatim
            // rather than layering our own copy on top of theirs.
            foreach (preg_split('/\n{2,}/', $template->render($values)) as $paragraph) {
                $message->line(trim($paragraph));
            }

            return $message->action('Sign in', $signIn);
        }

        if ($this->personalNote) {
            $message->line($this->personalNote);
        }

        return $message
            ->line("We have set up your {$company} account. Everything about your work with us lives there.")
            ->line('**Your sign in details**')
            ->line('Email: '.$this->account->email)
            ->line($this->account->mobile ? 'Mobile: '.$this->account->mobile.' (either one works)' : '')
            ->line('Temporary password: **'.$this->temporaryPassword.'**')
            ->action('Sign in', $signIn)
            ->line('You will be asked to choose your own password the first time you sign in. Please do that promptly, because until you do this password also exists in your inbox and your messages.')
            ->line('If you were not expecting this, tell us and we will close the account.');
    }

    public function toSms(object $notifiable): string
    {
        $template = MessageTemplate::find_by_key('welcome.client.sms');

        $values = [
            'name' => $this->account->name,
            'email' => $this->account->email,
            'password' => $this->temporaryPassword,
            'login_url' => url('/login'),
            'company' => config('app.name'),
        ];

        if ($template) {
            return $template->render($values);
        }

        return sprintf(
            '%s account ready. Sign in at %s with %s and temporary password %s. Change it after signing in. Do not share this message.',
            config('app.name'),
            url('/login'),
            $this->account->email,
            $this->temporaryPassword,
        );
    }
}
