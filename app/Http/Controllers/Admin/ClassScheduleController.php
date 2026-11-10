<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\Course;
use App\Models\LiveSession;
use App\Models\User;
use App\Support\Table\Column;
use App\Support\Table\Filter;
use App\Support\Table\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Every live class across every batch.
 *
 * A batch's own screen schedules its classes and marks its registers. This
 * list answers the questions that cut across batches: what is on today, what
 * happened last week, which classes were cancelled, and which were held but
 * never had their register marked, because attendance is marked, never
 * inferred, and an unmarked register is a hole in somebody's record.
 */
class ClassScheduleController extends Controller
{
    public function index(Request $request): Response|HttpResponse
    {
        $table = $this->table();

        if ($export = $table->exportResponse($request)) {
            return $export;
        }

        return Inertia::render('admin/classes/Index', [
            'table' => $table->toArray($request),
            'counts' => [
                'today' => LiveSession::query()
                    ->whereBetween('scheduled_at', [now()->startOfDay(), now()->endOfDay()])
                    ->whereNot('status', 'cancelled')
                    ->count(),
                'week' => LiveSession::query()
                    ->whereBetween('scheduled_at', [now(), now()->addDays(7)])
                    ->whereNot('status', 'cancelled')
                    ->count(),
                'unmarked' => $this->unmarked(LiveSession::query())->count(),
            ],
        ]);
    }

    protected function table(): Table
    {
        return Table::for(
            LiveSession::query()
                ->with(['batch:id,name,course_id,meet_link', 'batch.course:id,title', 'trainer:id,name'])
                ->withCount([
                    'attendances',
                    'attendances as present_count' => fn (Builder $query) => $query->whereIn('status', ['present', 'late']),
                ])
        )
            ->searchable(['title', 'batch.name'])
            ->defaultSort('scheduled_at')
            ->exportName('classes')
            ->columns([
                Column::make('scheduled_at', 'When')->sortable(),
                Column::make('title', 'Class')->sortable(),
                Column::make('course', 'Course'),
                Column::make('batch', 'Batch'),
                Column::make('trainer', 'Trainer'),
                Column::make('status', 'Status')->sortable(),
                Column::make('attendance', 'Attendance'),
                Column::make('duration_minutes', 'Minutes')->numeric()->hidden(),
                Column::make('recording', 'Recording')->hidden(),
            ])
            ->filters([
                Filter::select('when', [
                    'upcoming' => 'Upcoming',
                    'today' => 'Today',
                    'past' => 'Already happened',
                    'unmarked' => 'Held, register not marked',
                ], 'When')
                    ->placeholder('Any time')
                    ->using(fn (Builder $query, string $value) => match ($value) {
                        'upcoming' => $query->where('scheduled_at', '>=', now()->subHours(2))->whereNot('status', 'cancelled'),
                        'today' => $query->whereBetween('scheduled_at', [now()->startOfDay(), now()->endOfDay()]),
                        'past' => $query->where('scheduled_at', '<', now()),
                        'unmarked' => $this->unmarked($query),
                        default => $query,
                    }),
                Filter::multi('status', ['scheduled', 'held', 'cancelled'], 'Status'),
                Filter::select('course', Course::query()->orderBy('title')->pluck('title', 'id')->all(), 'Course')
                    ->placeholder('Any course')
                    ->using(fn (Builder $query, $value) => $query->whereHas('batch', fn (Builder $batch) => $batch->where('course_id', (int) $value))),
                Filter::select('batch', Batch::query()->orderByDesc('starts_on')->pluck('name', 'id')->all(), 'Batch')
                    ->column('batch_id')
                    ->placeholder('Any batch'),
                Filter::select('trainer', User::query()->role(Role::Admin)->orderBy('name')->pluck('name', 'id')->all(), 'Trainer')
                    ->column('trainer_id')
                    ->placeholder('Anyone'),
                Filter::dateRange('scheduled_at', 'Between'),
            ])
            ->transform(fn (LiveSession $session) => [
                'id' => $session->id,
                'batchId' => $session->batch_id,
                'scheduled_at' => $session->scheduled_at->format('D j M Y, g:i a'),
                'relative' => $session->scheduled_at->diffForHumans(),
                'title' => $session->title,
                'course' => $session->batch?->course?->title,
                'batch' => $session->batch?->name,
                'trainer' => $session->trainer?->name ?? '—',
                'status' => $session->status,
                'isLive' => $session->status !== 'cancelled' && $session->isLive(),
                'isPast' => $session->scheduled_at->isPast(),
                'attendance' => $session->attendances_count
                    ? "{$session->present_count} of {$session->attendances_count}"
                    : ($session->scheduled_at->isPast() && $session->status !== 'cancelled' ? 'Not marked' : '—'),
                // The same rule as the "not marked" filter and count.
                'unmarked' => $session->attendances_count === 0
                    && $session->status !== 'cancelled'
                    && $session->scheduled_at->lt(now()->subHours(3)),
                'duration_minutes' => $session->duration_minutes,
                'recording' => $session->recording_url ? 'Yes' : 'No',
                'recordingUrl' => $session->recording_url,
                'joinUrl' => $session->isJoinable() ? $session->link() : null,
            ]);
    }

    /**
     * Held, or due to have finished, with nobody marked either way.
     *
     * A class that has ended and is still "scheduled" counts too: forgetting
     * to mark it held is the same forgetting as not marking the register.
     */
    protected function unmarked(Builder $query): Builder
    {
        return $query
            ->whereNot('status', 'cancelled')
            ->where('scheduled_at', '<', now()->subHours(3))
            ->whereDoesntHave('attendances');
    }
}
