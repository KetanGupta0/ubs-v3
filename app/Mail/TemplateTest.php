<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\HtmlString;

/**
 * A message template sent to the person editing it, exactly as rendered,
 * marked as a test so it is never mistaken for the real thing.
 */
class TemplateTest extends Mailable
{
    public function __construct(public string $renderedSubject, public string $renderedHtml) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[Test] '.$this->renderedSubject);
    }

    public function content(): Content
    {
        return new Content(htmlString: (string) new HtmlString($this->renderedHtml));
    }
}
