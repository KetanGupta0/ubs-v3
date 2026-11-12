<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\Enrollment;
use App\Services\Lms\Performance;
use App\Support\Table\Column;
use App\Support\Table\Filter;
use App\Support\Table\Table;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * A batch at a glance, for its trainer: who is slipping, before a warning is
 * needed. Every figure is a sortable column, and the signals are a filter, so
 * "everybody below 75% attendance" is one click and a shareable link.
 */
class BatchPerformanceController extends Controller
{
    public function __invoke(Request $request, Batch $batch, Performance $performance): Response|HttpResponse
    {
        $held = $performance->heldCount($batch);
        $due = $performance->dueCount($batch);

        $table = Table::for($performance->batchQuery($batch))
            ->searchable(['user.name', 'user.email'])
            ->defaultSort('attended')
            ->exportName("performance-{$batch->code}")
            ->columns([
                Column::make('student', 'Student'),
                Column::make('signals', 'Signals'),
                Column::make('attended', 'Attendance')->numeric()->sortable(),
                Column::make('on_time', 'On time')->numeric()->sortable(),
                Column::make('missing', 'Not handed in')->numeric()->sortable(),
                Column::make('quiz_average', 'Quiz average')->numeric()->sortable(),
                Column::make('progress_percent', 'Lessons')->numeric()->sortable(),
                Column::make('points', 'Points')->numeric()->sortable(),
                Column::make('last_active', 'Last active')->sortable(),
            ])
            ->filters([
                Filter::select('signal', ['any' => 'Any signal'] + Performance::SIGNALS, 'Signal')
                    ->placeholder('Everybody')
                    ->using(fn ($query, $value) => $performance->applySignal($query, $batch, (string) $value)),
            ])
            ->transform(function (Enrollment $enrolment) use ($held, $due) {
                $attendance = $held ? (int) round($enrolment->attended / $held * 100) : null;
                $quiz = $enrolment->quiz_average !== null ? (int) round((float) $enrolment->quiz_average) : null;
                $lastActive = $enrolment->last_active ? now()->parse($enrolment->last_active) : null;

                $signals = collect([
                    'attendance' => $held >= 2 && $attendance < Performance::ATTENDANCE_FLOOR,
                    'missing' => $enrolment->missing >= Performance::MISSING_LIMIT,
                    'quiz' => $quiz !== null && $quiz < Performance::QUIZ_FLOOR,
                    'quiet' => ! $lastActive || $lastActive->lt(now()->subDays(Performance::QUIET_DAYS)),
                ])->filter()->keys();

                return [
                    'id' => $enrolment->id,
                    'userId' => $enrolment->user_id,
                    'student' => $enrolment->user?->name,
                    'email' => $enrolment->user?->email,
                    'attended' => $attendance === null ? '—' : "{$attendance}% ({$enrolment->attended} of {$held})",
                    'on_time' => $due ? "{$enrolment->on_time} of {$due}" : '—',
                    'missing' => (int) $enrolment->missing,
                    'quiz_average' => $quiz === null ? '—' : "{$quiz}%",
                    'progress_percent' => "{$enrolment->progress_percent}%",
                    'points' => (int) $enrolment->points,
                    'last_active' => $lastActive?->diffForHumans() ?? 'Never',
                    'signals' => $signals->map(fn (string $key) => Performance::SIGNALS[$key])->implode(', ') ?: '—',
                    'signalKeys' => $signals->values(),
                ];
            });

        if ($export = $table->exportResponse($request)) {
            return $export;
        }

        return Inertia::render('admin/batches/Performance', [
            'batch' => [
                'id' => $batch->id,
                'routeKey' => $batch->getRouteKey(),
                'name' => $batch->name,
                'course' => $batch->course?->title,
                'held' => $held,
                'due' => $due,
            ],
            'table' => $table->toArray($request),
            'slipping' => $this->slipping($performance, $batch),
        ]);
    }

    protected function slipping(Performance $performance, Batch $batch): int
    {
        $query = $performance->batchQuery($batch);
        $performance->applySignal($query, $batch, 'any');

        return $query->count();
    }
}
