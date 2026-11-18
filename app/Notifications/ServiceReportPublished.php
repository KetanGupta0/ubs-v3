<?php

namespace App\Notifications;

use App\Models\ServiceReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The month's service report is in the client's documents (CL17). */
class ServiceReportPublished extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public ServiceReport $report) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $figures = $this->report->figures;

        return (new MailMessage)
            ->subject("Your service report for {$this->report->monthLabel()}")
            ->greeting("Hello {$notifiable->name},")
            ->line("Your service report for **{$this->report->monthLabel()}** ({$this->report->contract->reference}) is in your documents.")
            ->line("Tickets raised: {$figures['opened']}; resolved: {$figures['resolved']}.")
            ->action('Open your documents', url('/client/documents'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'service_report.published',
            'title' => "Service report: {$this->report->monthLabel()}",
            'url' => '/client/documents',
        ];
    }
}
