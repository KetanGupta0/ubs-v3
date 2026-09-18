<?php

namespace App\Mail;

use App\Models\ReportSchedule;
use App\Services\Reports\Report;
use App\Services\Reports\ReportWindow;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A report, in somebody's inbox.
 *
 * The headline figures are in the body rather than only in the attachment,
 * because most of the time the answer is one number and opening a spreadsheet
 * on a phone to find it is a poor way to spend a morning.
 */
class ScheduledReport extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ReportSchedule $schedule,
        public array $payload,
        public string $filePath,
        public string $fileName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->payload['title'].' — '.$this->payload['window']['label'],
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.scheduled-report',
            with: [
                'title' => $this->payload['title'],
                'description' => $this->payload['description'],
                'window' => $this->payload['window']['label'],
                'summary' => $this->payload['summary'],
                'rowCount' => $this->payload['rowCount'],
                'cadence' => $this->schedule->cadenceLabel(),
            ],
        );
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromPath($this->filePath)->as($this->fileName)];
    }

    /** @return array<string, mixed> */
    public static function build(Report $report, ReportWindow $window): array
    {
        return $report->run($window);
    }
}
