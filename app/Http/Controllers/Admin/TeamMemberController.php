<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TeamMember;
use App\Services\Admin\Auditor;
use App\Support\ImageUpload;
use App\Support\Table\Column;
use App\Support\Table\Filter;
use App\Support\Table\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The team on the About page and the mentors on the Training page.
 *
 * A photo is re-encoded on the way in, which drops whatever the camera wrote
 * into it, and the old file goes when it is replaced or the person is
 * removed, so a face somebody asked to take down is really gone.
 */
class TeamMemberController extends Controller
{
    public function index(Request $request): Response|HttpResponse
    {
        $table = Table::for(TeamMember::query())
            ->searchable(['name', 'role'])
            ->defaultSort('sort_order')
            ->exportName('team')
            ->columns([
                Column::make('name', 'Person')->sortable(),
                Column::make('role', 'Role')->sortable(),
                Column::make('shown', 'Shown on'),
                Column::make('is_active', 'Active')->sortable(),
                Column::make('sort_order', 'Order')->numeric()->sortable(),
            ])
            ->filters([
                Filter::select('shown', ['about' => 'About page', 'mentor' => 'Training page (mentors)'], 'Shown on')
                    ->placeholder('Anywhere')
                    ->using(fn (Builder $query, string $value) => match ($value) {
                        'about' => $query->where('on_about', true),
                        'mentor' => $query->where('is_mentor', true),
                        default => $query,
                    }),
                Filter::boolean('is_active', 'Active only'),
            ])
            ->transform(fn (TeamMember $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'role' => $member->role,
                'photo' => $member->photoUrl(),
                'shown' => $this->shownOn($member),
                'is_active' => $member->is_active,
                'sort_order' => $member->sort_order,
            ]);

        if ($export = $table->exportResponse($request)) {
            return $export;
        }

        return Inertia::render('admin/team/Index', ['table' => $table->toArray($request)]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/team/Form', [
            'member' => null,
            'nextOrder' => (int) TeamMember::query()->max('sort_order') + 1,
        ]);
    }

    public function edit(TeamMember $member): Response
    {
        return Inertia::render('admin/team/Form', [
            'member' => [
                'id' => $member->id,
                'name' => $member->name,
                'role' => $member->role,
                'bio' => $member->bio,
                'experience' => $member->experience,
                'skills' => implode(', ', $member->skills ?? []),
                'linkedin_url' => $member->linkedin_url,
                'on_about' => $member->on_about,
                'is_mentor' => $member->is_mentor,
                'is_active' => $member->is_active,
                'sort_order' => $member->sort_order,
                'photo' => $member->photoUrl(),
            ],
            'nextOrder' => $member->sort_order,
        ]);
    }

    public function store(Request $request, Auditor $auditor): RedirectResponse
    {
        $member = new TeamMember;
        $this->fill($request, $member);
        $member->save();
        $auditor->created($member, $member->name);

        return redirect()
            ->route('admin.team.edit', $member)
            ->with('success', $member->is_active ? "{$member->name} added. {$this->where($member)}" : "{$member->name} saved, not shown anywhere while inactive.");
    }

    public function update(Request $request, TeamMember $member, Auditor $auditor): RedirectResponse
    {
        $oldPhoto = $member->photo_path;
        $this->fill($request, $member);
        $auditor->updated($member, label: $member->name);
        $member->save();

        if ($oldPhoto && $oldPhoto !== $member->photo_path) {
            Storage::disk('public')->delete($oldPhoto);
        }

        return back()->with('success', 'Saved.');
    }

    public function destroy(TeamMember $member, Auditor $auditor): RedirectResponse
    {
        $auditor->deleted($member, $member->name);

        if ($member->photo_path) {
            Storage::disk('public')->delete($member->photo_path);
        }

        $member->delete();

        return redirect()->route('admin.team.index')->with('success', "{$member->name} removed from the site, photo included.");
    }

    protected function fill(Request $request, TeamMember $member): void
    {
        $validated = $this->validatedInput($request, [
            'name' => ['required', 'string', 'max:150'],
            'role' => ['required', 'string', 'max:150'],
            'bio' => ['nullable', 'string', 'max:600'],
            'experience' => ['nullable', 'string', 'max:60'],
            'skills' => ['nullable', 'string', 'max:300'],
            'linkedin_url' => ['nullable', 'string', 'max:300', 'regex:#^https://([a-z]{2,3}\.)?linkedin\.com/#i'],
            'on_about' => ['boolean'],
            'is_mentor' => ['boolean'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'remove_photo' => ['boolean'],
        ], [
            'linkedin_url.regex' => 'Use the full LinkedIn address, starting with https://www.linkedin.com/.',
            'photo.mimes' => 'Use a JPG, PNG or WebP photo.',
        ]);

        $onAbout = $this->boolInput($request, 'on_about', true);
        $isMentor = $this->boolInput($request, 'is_mentor', false);

        if (! $onAbout && ! $isMentor) {
            throw ValidationException::withMessages([
                'on_about' => 'Choose where this person appears: the About page, the Training page, or both.',
            ]);
        }

        $member->fill([
            'name' => trim($validated['name']),
            'role' => trim($validated['role']),
            'bio' => filled($validated['bio']) ? trim($validated['bio']) : null,
            'experience' => filled($validated['experience']) ? trim($validated['experience']) : null,
            // Typed as a comma separated line; stored as a list, at most eight.
            'skills' => collect(explode(',', (string) $validated['skills']))
                ->map(fn (string $skill) => trim($skill))
                ->filter()
                ->unique(fn (string $skill) => mb_strtolower($skill))
                ->take(8)
                ->values()
                ->all(),
            'linkedin_url' => filled($validated['linkedin_url']) ? trim($validated['linkedin_url']) : null,
            'on_about' => $onAbout,
            'is_mentor' => $isMentor,
            'is_active' => $this->boolInput($request, 'is_active', true),
            'sort_order' => $validated['sort_order'] ?? ((int) TeamMember::query()->max('sort_order') + 1),
        ]);

        if ($validated['remove_photo']) {
            $member->photo_path = null;
        }

        if ($file = $request->file('photo')) {
            $member->photo_path = ImageUpload::store($file, 'public', 'team', 800, 'jpg', 'photo');
        }
    }

    protected function shownOn(TeamMember $member): string
    {
        return match (true) {
            $member->on_about && $member->is_mentor => 'About and Training',
            $member->is_mentor => 'Training',
            default => 'About',
        };
    }

    protected function where(TeamMember $member): string
    {
        return match (true) {
            $member->on_about && $member->is_mentor => 'Shown on the About page and as a mentor on the Training page.',
            $member->is_mentor => 'Shown as a mentor on the Training page.',
            default => 'Shown on the About page.',
        };
    }
}
