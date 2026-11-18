<?php

namespace App\Services\Client;

use App\Models\Document;
use App\Models\MaintenanceContract;
use App\Models\ServiceReport;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\ServiceReportPublished;
use App\Services\Admin\Auditor;
use App\Services\Billing\Invoicer;
use App\Support\Markdown;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Monthly service reports (CL17).
 *
 * What the tickets say is worked out from the tickets: how many, and how
 * many met the response and resolution times copied onto each when it was
 * raised. What only our side knows (uptime, hours, the work done) is
 * written in by a person before publishing. Published, the report is a PDF
 * in the client's documents, and is never redrafted.
 */
class ServiceReports
{
    public function __construct(protected Auditor $auditor) {}

    /** Draft, or refresh the draft of, a contract's report for a month. */
    public function draftFor(MaintenanceContract $contract, Carbon $month): ServiceReport
    {
        $month = $month->copy()->startOfMonth();
        // whereDate: a date column can come back with a time on some drivers.
        $report = ServiceReport::query()->where('contract_id', $contract->id)->whereDate('month', $month->toDateString())->first()
            ?? new ServiceReport(['contract_id' => $contract->id, 'month' => $month->toDateString()]);

        if ($report->status === 'published') {
            return $report;
        }

        $report->fill([
            'client_id' => $contract->client_id,
            'figures' => $this->figures($contract, $month),
            'status' => 'draft',
        ])->save();

        return $report;
    }

    /** @return array<string, mixed> */
    public function figures(MaintenanceContract $contract, Carbon $month): array
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        // Met, missed, or not yet decided (still open and not yet due at the month's end).
        $judge = fn (?Carbon $due, ?Carbon $done): ?bool => match (true) {
            $due === null => null,
            $done !== null => $done->lte($due),
            default => $due->lt($end) ? false : null,
        };

        $tickets = SupportTicket::query()->where('contract_id', $contract->id)->whereBetween('created_at', [$start, $end])->orderBy('created_at')->get();
        $rows = $tickets->map(fn (SupportTicket $ticket) => [
            'reference' => $ticket->reference,
            'subject' => $ticket->subject,
            'priority' => $ticket->priority,
            'opened' => $ticket->created_at->format('j M'),
            'resolved' => $ticket->resolved_at?->format('j M'),
            'responseMet' => $judge($ticket->response_due_at, $ticket->first_response_at),
            'resolutionMet' => $judge($ticket->resolution_due_at, $ticket->resolved_at),
        ]);

        $share = function (string $key) use ($rows): ?int {
            $decided = $rows->filter(fn (array $row) => $row[$key] !== null);

            return $decided->isEmpty() ? null : (int) round($decided->where($key, true)->count() / $decided->count() * 100);
        };

        // The contract year the month falls in, for tickets counted against a yearly allowance.
        $yearStart = $contract->starts_on->copy();
        while ($yearStart->copy()->addYear()->lte($end)) {
            $yearStart->addYear();
        }

        return [
            'opened' => $tickets->count(),
            'resolved' => SupportTicket::query()->where('contract_id', $contract->id)->whereBetween('resolved_at', [$start, $end])->count(),
            'responseMet' => $share('responseMet'),
            'resolutionMet' => $share('resolutionMet'),
            'responseHours' => $contract->response_hours,
            'resolutionHours' => $contract->resolution_hours,
            'ticketsThisYear' => SupportTicket::query()->where('contract_id', $contract->id)->whereBetween('created_at', [$yearStart, $end])->count(),
            'includedTickets' => $contract->included_tickets,
            'includedHours' => $contract->included_hours,
            'tickets' => $rows->all(),
        ];
    }

    /** @param  array{uptime_percent: ?float, hours_used: ?float, work_done: ?string}  $data */
    public function update(ServiceReport $report, User $staff, array $data): void
    {
        if ($report->status === 'published') {
            throw new InvalidArgumentException('A published report is the client\'s record. Write next month\'s instead.');
        }

        $report->fill($data);
        $this->auditor->actingAs($staff)->updated($report, label: "Service report {$report->monthLabel()}");
        $report->save();
    }

    public function publish(ServiceReport $report, User $staff): Document
    {
        if ($report->status === 'published') {
            throw new InvalidArgumentException('This report is already published.');
        }

        $contract = $report->contract;
        $report->forceFill(['figures' => $this->figures($contract, $report->month)]);
        $output = $this->render($report);
        $path = "clients/{$report->client_id}/service-report-{$contract->reference}-{$report->month->format('Y-m')}.pdf";
        Storage::disk('private')->put($path, $output);

        $document = Document::query()->create([
            'client_id' => $report->client_id,
            'project_id' => $contract->project_id,
            'name' => "Service report, {$report->monthLabel()} ({$contract->reference}).pdf",
            'description' => "Monthly service report for {$contract->plan}, {$contract->reference}.",
            'path' => $path,
            'mime' => 'application/pdf',
            'size' => strlen($output),
            'checksum' => hash('sha256', $output),
            'category' => 'Service report',
            'visible_to_client' => true,
            'uploaded_by' => $staff->id,
        ]);

        $report->forceFill(['status' => 'published', 'published_at' => now(), 'published_by' => $staff->id, 'document_id' => $document->id])->save();
        $this->auditor->actingAs($staff)->action('service_report.published', $report, ['month' => $report->month->toDateString()], $contract->reference);
        Notification::send($report->client->clientRecipients('all'), new ServiceReportPublished($report->fresh()));

        return $document;
    }

    public function render(ServiceReport $report): string
    {
        $report->loadMissing('contract', 'client.profile');

        return Pdf::loadView('documents.service-report', [
            'report' => $report,
            'contract' => $report->contract,
            'figures' => $report->figures,
            'workDone' => filled($report->work_done) ? Markdown::html($report->work_done) : null,
            'from' => app(Invoicer::class)->billedFrom(),
            'to' => app(Invoicer::class)->billedTo($report->client),
        ])->setPaper('a4')->output();
    }
}
