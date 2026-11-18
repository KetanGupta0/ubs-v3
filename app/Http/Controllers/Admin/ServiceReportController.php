<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ServiceReport;
use App\Services\Client\ServiceReports;
use App\Support\Table\Column;
use App\Support\Table\Filter;
use App\Support\Table\Table;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** Monthly service reports, drafted on the 1st, finished and published by a person (CL17). */
class ServiceReportController extends Controller
{
    public function index(Request $request): Response|HttpResponse
    {
        $table = Table::for(ServiceReport::query()->with('contract:id,reference,plan', 'client.profile'))
            ->defaultSort('-month')
            ->exportName('service-reports')
            ->columns([
                Column::make('month', 'Month')->sortable(),
                Column::make('contract', 'Contract'),
                Column::make('client', 'Client'),
                Column::make('tickets', 'Tickets'),
                Column::make('status', 'Status')->sortable(),
            ])
            ->filters([
                Filter::select('status', ['draft' => 'To finish', 'published' => 'Published'], 'Status')->placeholder('Any'),
            ])
            ->transform(fn (ServiceReport $report) => [
                'id' => $report->id,
                'month' => $report->monthLabel(),
                'contract' => $report->contract ? "{$report->contract->reference} · {$report->contract->plan}" : '—',
                'client' => $report->client?->organisationName(),
                'tickets' => $report->figures['opened'] ?? 0,
                'status' => $report->status,
            ]);

        if ($export = $table->exportResponse($request)) {
            return $export;
        }

        return Inertia::render('admin/service-reports/Index', ['table' => $table->toArray($request)]);
    }

    public function show(ServiceReport $serviceReport): Response
    {
        $serviceReport->load('contract', 'client.profile', 'document:id');

        return Inertia::render('admin/service-reports/Show', [
            'report' => [
                'id' => $serviceReport->id,
                'month' => $serviceReport->monthLabel(),
                'status' => $serviceReport->status,
                'client' => $serviceReport->client?->organisationName(),
                'contract' => "{$serviceReport->contract->reference} · {$serviceReport->contract->plan}",
                'figures' => $serviceReport->figures,
                'uptime_percent' => $serviceReport->uptime_percent,
                'hours_used' => $serviceReport->hours_used,
                'work_done' => $serviceReport->work_done ?? '',
                'publishedAt' => $serviceReport->published_at?->format('j M Y, g:i a'),
                'documentId' => $serviceReport->document_id,
            ],
        ]);
    }

    public function update(Request $request, ServiceReport $serviceReport, ServiceReports $reports): RedirectResponse
    {
        $validated = $this->validatedInput($request, [
            'uptime_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'hours_used' => ['nullable', 'numeric', 'min:0', 'max:744'],
            'work_done' => ['nullable', 'string', 'max:10000'],
        ]);

        try {
            $reports->update($serviceReport, $request->user(), $validated);

            if ($request->boolean('publish')) {
                $reports->publish($serviceReport->fresh(), $request->user());

                return back()->with('success', 'Published into the client\'s documents. They have been told.');
            }
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Saved.');
    }

    /** The report as it would be published now. */
    public function preview(ServiceReport $serviceReport, ServiceReports $reports): HttpResponse
    {
        return response($reports->render($serviceReport), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="service-report-preview.pdf"',
        ]);
    }
}
