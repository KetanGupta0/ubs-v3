<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\Course;
use App\Models\User;
use App\Services\Admin\Auditor;
use App\Support\Table\Column;
use App\Support\Table\Filter;
use App\Support\Table\Table;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Cohorts for courses and internships.
 *
 * Seat counts are advertised on the public site, so they are treated as a
 * number that must be true rather than a marketing dial.
 */
class BatchController extends Controller
{
    public const STATUSES = ['upcoming', 'running', 'completed', 'cancelled'];

    public function index(Request $request): Response|HttpResponse
    {
        $table = $this->table();

        if ($export = $table->exportResponse($request)) {
            return $export;
        }

        return Inertia::render('admin/catalogue/batches/Index', [
            'table' => $table->toArray($request),
            'counts' => [
                'upcoming' => Batch::query()->where('status', 'upcoming')->count(),
                'running' => Batch::query()->where('status', 'running')->count(),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('admin/catalogue/batches/Form', [
            'batch' => null,
            'courses' => $this->courseOptions(),
            'trainers' => $this->trainerOptions(),
            'statuses' => self::STATUSES,
            'defaultCourseId' => $request->query('course') ? (int) $request->query('course') : null,
        ]);
    }

    public function store(Request $request, Auditor $auditor): RedirectResponse
    {
        $batch = Batch::query()->create($this->validated($request));
        $auditor->created($batch);

        return redirect()->route('admin.batches.index')->with('success', 'Batch created.');
    }

    public function edit(Batch $batch): Response
    {
        return Inertia::render('admin/catalogue/batches/Form', [
            'batch' => [
                'id' => $batch->id,
                'course_id' => $batch->course_id,
                'trainer_id' => $batch->trainer_id,
                'name' => $batch->name,
                'code' => $batch->code,
                'starts_on' => $batch->starts_on?->toDateString(),
                'ends_on' => $batch->ends_on?->toDateString(),
                'schedule' => $batch->schedule ?? [],
                'capacity' => $batch->capacity,
                'seats_taken' => $batch->seats_taken,
                'status' => $batch->status,
                'is_published' => $batch->is_published,
            ],
            'courses' => $this->courseOptions(),
            'trainers' => $this->trainerOptions(),
            'statuses' => self::STATUSES,
            'defaultCourseId' => $batch->course_id,
        ]);
    }

    public function update(Request $request, Batch $batch, Auditor $auditor): RedirectResponse
    {
        $batch->fill($this->validated($request, $batch));
        $auditor->updated($batch);
        $batch->save();

        return back()->with('success', 'Batch saved.');
    }

    public function destroy(Batch $batch, Auditor $auditor): RedirectResponse
    {
        $auditor->deleted($batch);
        $batch->delete();

        return redirect()->route('admin.batches.index')->with('success', 'Batch deleted.');
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, ?Batch $batch = null): array
    {
        return $request->validate([
            'course_id' => ['required', 'integer', Rule::exists('courses', 'id')],
            'trainer_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('role', Role::Admin->value)],
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:40', Rule::unique('batches', 'code')->ignore($batch?->id)],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'schedule' => ['array'],
            'schedule.*.day' => ['required', 'string', 'max:12'],
            'schedule.*.from' => ['required', 'string', 'max:8'],
            'schedule.*.to' => ['required', 'string', 'max:8'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            // Seats taken is shown publicly, so it cannot exceed the capacity.
            'seats_taken' => ['nullable', 'integer', 'min:0', 'lte:capacity'],
            'status' => ['required', Rule::in(self::STATUSES)],
            'is_published' => ['boolean'],
        ], [
            'ends_on.after_or_equal' => 'The end date cannot be before the start date.',
            'seats_taken.lte' => 'More seats taken than the batch has.',
            'code.unique' => 'That batch code is already in use.',
        ]);
    }

    protected function table(): Table
    {
        return Table::for(Batch::query()->with(['course:id,title,type', 'trainer:id,name']))
            ->searchable(['name', 'code', 'course.title'])
            ->defaultSort('starts_on')
            ->exportName('batches')
            ->columns([
                Column::make('name', 'Batch')->sortable(),
                Column::make('course', 'For'),
                Column::make('code', 'Code')->sortable(),
                Column::make('starts_on', 'Starts')->sortable(),
                Column::make('seats', 'Seats'),
                Column::make('trainer', 'Trainer'),
                Column::make('status', 'Status')->sortable(),
            ])
            ->filters([
                Filter::select('status', self::STATUSES, 'Status')->placeholder('Any'),
                Filter::select('course_id', $this->courseOptions()->map(fn ($c) => ['value' => $c['value'], 'label' => $c['label']])->all(), 'Course')
                    ->placeholder('Any'),
                Filter::boolean('is_published', 'Published only'),
                Filter::dateRange('starts_on', 'Starting between'),
            ])
            ->transform(fn (Batch $batch) => [
                'id' => $batch->id,
                'name' => $batch->name,
                'course' => $batch->course?->title,
                'type' => $batch->course?->type,
                'code' => $batch->code,
                'starts_on' => $batch->starts_on?->format('j M Y') ?? '—',
                'seats' => $batch->capacity
                    ? "{$batch->seats_taken} / {$batch->capacity}"
                    : (string) $batch->seats_taken,
                'seatsLeft' => $batch->seatsLeft(),
                'nearlyFull' => $batch->isNearlyFull(),
                'trainer' => $batch->trainer?->name ?? 'Unassigned',
                'status' => $batch->status,
                'published' => $batch->is_published,
            ]);
    }

    protected function courseOptions()
    {
        return Course::query()
            ->orderBy('type')
            ->orderBy('title')
            ->get(['id', 'title', 'type'])
            ->map(fn (Course $course) => [
                'value' => $course->id,
                'label' => $course->title,
                'description' => ucfirst($course->type),
            ]);
    }

    protected function trainerOptions()
    {
        return User::query()
            ->role(Role::Admin)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user) => ['value' => $user->id, 'label' => $user->name]);
    }
}
