<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\College;
use App\Services\Admin\Auditor;
use App\Support\Identifier;
use App\Support\Table\Column;
use App\Support\Table\Filter;
use App\Support\Table\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Colleges we have a tie-up with.
 *
 * A college is a record rather than a text field on a student, because the
 * memorandum, the coordinator and the batch all belong to the institution and
 * outlive any individual student.
 */
class CollegeController extends Controller
{
    public function index(Request $request): Response|HttpResponse
    {
        $table = $this->table();

        if ($export = $table->exportResponse($request)) {
            return $export;
        }

        return Inertia::render('admin/colleges/Index', [
            'table' => $table->toArray($request),
            'counts' => [
                'all' => College::query()->count(),
                'active' => College::query()->active()->count(),
                'expiring' => College::query()
                    ->whereNotNull('mou_expires_on')
                    ->whereBetween('mou_expires_on', [today(), today()->addDays(60)])
                    ->count(),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/colleges/Form', ['college' => null]);
    }

    public function store(Request $request, Auditor $auditor): RedirectResponse
    {
        $college = College::query()->create($this->validated($request));
        $auditor->created($college);

        return redirect()->route('admin.colleges.index')->with('success', 'College added.');
    }

    public function edit(College $college): Response
    {
        return Inertia::render('admin/colleges/Form', [
            'college' => [
                'id' => $college->id,
                'name' => $college->name,
                'slug' => $college->slug,
                'short_name' => $college->short_name,
                'city' => $college->city,
                'state' => $college->state,
                'university' => $college->university,
                'coordinator_name' => $college->coordinator_name,
                'coordinator_email' => $college->coordinator_email,
                'coordinator_mobile' => $college->coordinator_mobile,
                'mou_signed_on' => $college->mou_signed_on?->toDateString(),
                'mou_expires_on' => $college->mou_expires_on?->toDateString(),
                'notes' => $college->notes,
                'is_active' => $college->is_active,
                'studentCount' => $college->profiles()->count(),
            ],
        ]);
    }

    public function update(Request $request, College $college, Auditor $auditor): RedirectResponse
    {
        $college->fill($this->validated($request, $college));
        $auditor->updated($college);
        $college->save();

        return back()->with('success', 'College saved.');
    }

    public function destroy(College $college, Auditor $auditor): RedirectResponse
    {
        // Students reference the college, so removing it would orphan their
        // records. Marking it inactive is what an administrator means anyway.
        if ($college->profiles()->exists()) {
            return back()->withErrors([
                'college' => 'Students are linked to this college. Mark it inactive instead.',
            ]);
        }

        $auditor->deleted($college);
        $college->delete();

        return redirect()->route('admin.colleges.index')->with('success', 'College removed.');
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, ?College $college = null): array
    {
        if ($request->filled('coordinator_mobile')) {
            $request->merge([
                'coordinator_mobile' => Identifier::normaliseMobile((string) $request->input('coordinator_mobile')),
            ]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'slug' => ['nullable', 'string', 'max:220', 'regex:/^[a-z0-9-]+$/', Rule::unique('colleges', 'slug')->ignore($college?->id)],
            'short_name' => ['nullable', 'string', 'max:60'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'university' => ['nullable', 'string', 'max:200'],
            'coordinator_name' => ['nullable', 'string', 'max:120'],
            'coordinator_email' => ['nullable', 'email', 'max:255'],
            'coordinator_mobile' => ['nullable', 'string', 'regex:/^\+\d{10,15}$/'],
            'mou_signed_on' => ['nullable', 'date'],
            'mou_expires_on' => ['nullable', 'date', 'after:mou_signed_on'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'is_active' => ['boolean'],
        ], [
            'mou_expires_on.after' => 'The memorandum cannot expire before it was signed.',
        ]);

        $validated['slug'] = ($validated['slug'] ?? null) ?: Str::slug($validated['name']);

        return $validated;
    }

    protected function table(): Table
    {
        return Table::for(College::query()->withCount('profiles'))
            ->searchable(['name', 'short_name', 'city', 'university', 'coordinator_name'])
            ->defaultSort('name')
            ->exportName('colleges')
            ->columns([
                Column::make('name', 'College')->sortable(),
                Column::make('location', 'Location'),
                Column::make('coordinator', 'Coordinator'),
                Column::make('students', 'Students')->numeric(),
                Column::make('mou', 'Memorandum'),
                Column::make('state', 'State'),
            ])
            ->filters([
                Filter::boolean('is_active', 'Active only'),
                Filter::boolean('mou_expiring', 'Memorandum expiring within 60 days')
                    ->using(fn (Builder $query) => $query
                        ->whereNotNull('mou_expires_on')
                        ->whereBetween('mou_expires_on', [today(), today()->addDays(60)])),
                Filter::text('state', 'State'),
            ])
            ->transform(fn (College $college) => [
                'id' => $college->id,
                'slug' => $college->slug,
                'name' => $college->name,
                'location' => collect([$college->city, $college->state])->filter()->join(', ') ?: '—',
                'coordinator' => $college->coordinator_name ?? 'Not recorded',
                'students' => $college->profiles_count,
                'mou' => match (true) {
                    $college->mouHasExpired() => 'Expired',
                    $college->mouIsExpiring() => 'Expires '.$college->mou_expires_on->format('j M Y'),
                    (bool) $college->mou_expires_on => 'Valid to '.$college->mou_expires_on->format('j M Y'),
                    default => 'Not recorded',
                },
                'mouExpired' => $college->mouHasExpired(),
                'mouExpiring' => $college->mouIsExpiring(),
                'state' => $college->is_active ? 'Active' : 'Inactive',
                'active' => $college->is_active,
            ]);
    }
}
