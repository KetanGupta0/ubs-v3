<?php

namespace App\Mail;

use App\Models\Lead;
use App\Models\MailCampaign;
use App\Models\MailCampaignRecipient;
use App\Services\Mail\Suppressions;
use App\Support\Markdown;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\Carbon;

/**
 * A greeting or an announcement, in somebody's inbox (§8).
 *
 * Every one says why it came and how to stop it, and carries the headers that
 * let a mail app offer its own unsubscribe button, which posts straight to us
 * (RFC 8058). Stopping these never stops the mail an account needs.
 */
class CampaignMail extends Mailable
{
    /**
     * @param  array{email: string, name: ?string, why: string}  $recipient  Who it is for, and why they get it.
     */
    public function __construct(
        public MailCampaign $campaign,
        public array $recipient,
        public bool $test = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: ($this->test ? '[Test] ' : '').self::fill($this->campaign->subject, $this->recipient['name']));
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<'.$this->unsubscribeUrl().'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.campaign',
            with: [
                'html' => Markdown::html(self::fill($this->campaign->message, $this->recipient['name'])),
                'image' => $this->campaign->media?->url(),
                'imageAlt' => $this->campaign->media?->alt ?? '',
                'why' => $this->recipient['why'],
                'unsubscribe' => $this->unsubscribeUrl(),
            ],
        );
    }

    protected function unsubscribeUrl(): string
    {
        return Suppressions::unsubscribeUrl($this->recipient['email'], $this->test ? null : $this->campaign->id);
    }

    /** {first_name} and {name}, from the name we hold, or a plain greeting without one. */
    public static function fill(string $text, ?string $name): string
    {
        $name = trim((string) $name);

        return strtr($text, [
            '{first_name}' => $name !== '' ? str($name)->before(' ')->toString() : 'there',
            '{name}' => $name !== '' ? $name : 'there',
        ]);
    }

    /** Why this address gets it, as the footer says. */
    public static function whyFor(MailCampaignRecipient|array $recipient): string
    {
        $userId = is_array($recipient) ? ($recipient['user_id'] ?? null) : $recipient->user_id;
        $leadId = is_array($recipient) ? ($recipient['lead_id'] ?? null) : $recipient->lead_id;

        if ($userId) {
            return 'You have an account with '.config('company.name').'.';
        }

        $sentOn = $leadId ? Lead::query()->whereKey($leadId)->value('created_at') : null;

        return 'You sent us an enquiry'.($sentOn ? ' on '.Carbon::parse($sentOn)->format('j F Y') : '').'.';
    }
}
