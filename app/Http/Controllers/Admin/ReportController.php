<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ReportSchedule;
use App\Services\Reports\Report;
use App\Services\Reports\ReportExport;
use App\Services\Reports\ReportLibrary;
use App\Services\Reports\ReportWindow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The report library.
 *
 * Every report answers through the same shape, so this controller does not
 * know what any of them are about: it resolves one, hands it the window, and
 * renders what comes back. Adding a report means writing a report, not
 * touching this.
 */
class ReportController extends Controller
{
    public function __construct(
        protected ReportLibrary $library,
        protected ReportExport $exporter,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('admin/reports/Index', [
            'reports' => $this->library->for($request->user())->map(fn (Report $report) => [
                'key' => $report->key(),
                'title' => $report->title(),
                'description' => $report->description(),
            ]),
            'schedules' => $this->schedulesFor($request),
        ]);
    }

    public function show(Request $request, string $report): Response|HttpResponse
    {
        $resolved = $this->library->findFor($request->user(), $report);
        $window = ReportWindow::fromRequest($request);

        // The download carries the same window the screen is showing, so a
        // spreadsheet cannot quietly cover a different period.
        if ($format = $request->query('export')) {
            abort_unless(in_array($format, ['csv', 'xlsx', 'pdf'], true), 400);

            return $this->exporter->download($resolved, $window, $format);
        }

        return Inertia::render('admin/reports/Show', [
            'report' => $resolved->run($window),
            'library' => $this->library->for($request->user())->map(fn (Report $one) => [
                'key' => $one->key(),
                'title' => $one->title(),
            ]),
            'schedules' => $this->schedulesFor($request, $resolved->key()),
        ]);
    }

    /* ------------------------------------------------------- scheduling */

    public function schedule(Request $request, string $report): RedirectResponse
    {
        $resolved = $this->library->findFor($request->user(), $report);

        $validated = $this->validatedInput($request, [
            'cadence' => ['required', Rule::in(ReportSchedule::CADENCES)],
            'day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'hour' => ['required', 'integer', 'min:0', 'max:23'],
            'recipients' => ['required', 'array', 'min:1', 'max:10'],
            'recipients.*' => ['email'],
            'format' => ['required', Rule::in(['pdf', 'xlsx', 'csv'])],
            'filters' => ['array'],
        ], [
            'recipients.*.email' => 'Each recipient needs to be an email address.',
        ]);

        ReportSchedule::query()->create([
            'user_id' => $request->user()->id,
            'report' => $resolved->key(),
            'name' => $resolved->title(),
            'cadence' => $validated['cadence'],
            'day' => $validated['day'],
            'hour' => $validated['hour'],
            'recipients' => array_values(array_unique($validated['recipients'])),
            'format' => $validated['format'],
            'filters' => $validated['filters'] ?: null,
        ]);

        return back()->with('success', 'Scheduled. The first one goes out at the next slot.');
    }

    public function unschedule(Request $request, int $schedule): RedirectResponse
    {
        $record = ReportSchedule::query()
            ->where('user_id', $request->user()->id)
            ->findOrFail($schedule);

        $record->delete();

        return back()->with('success', 'Stopped.');
    }

    /** @return array<int, array<string, mixed>> */
    protected function schedulesFor(Request $request, ?string $report = null): array
    {
        return ReportSchedule::query()
            ->where('user_id', $request->user()->id)
            ->when($report, fn ($query) => $query->where('report', $report))
            ->latest('id')
            ->get()
            ->map(fn (ReportSchedule $schedule) => [
                'id' => $schedule->id,
                'report' => $schedule->report,
                'name' => $schedule->name ?? $schedule->report,
                'cadence' => $schedule->cadenceLabel(),
                'hour' => sprintf('%02d:00', $schedule->hour),
                'recipients' => $schedule->recipients,
                'format' => $schedule->format,
                'lastSentAt' => $schedule->last_sent_at?->format('j M Y, g:i a'),
                'lastError' => $schedule->last_error,
            ])
            ->all();
    }
}
