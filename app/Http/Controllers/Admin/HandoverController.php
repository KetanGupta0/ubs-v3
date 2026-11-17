<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Handover;
use App\Models\Project;
use App\Services\Client\Handovers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/** Writing and publishing a project's handover pack (CL15). */
class HandoverController extends Controller
{
    public function edit(Project $project): Response
    {
        $handover = Handover::query()->with('acknowledger:id,name')->firstWhere('project_id', $project->id);

        return Inertia::render('admin/projects/Handover', [
            'project' => ['id' => $project->id, 'name' => $project->name, 'code' => $project->code],
            'handover' => [
                'repository' => $handover?->repository ?? '',
                'deployment_guide' => $handover?->deployment_guide ?? '',
                'environment_notes' => $handover?->environment_notes ?? '',
                'credentials_note' => $handover?->credentials_note ?? '',
                'licences' => $handover?->licences ?? [],
                'videos' => $handover?->videos ?? [],
                'warranty_ends_on' => $handover?->warranty_ends_on?->toDateString() ?? '',
            ],
            'state' => [
                'revision' => $handover?->revision ?? 0,
                'publishedAt' => $handover?->published_at?->format('j M Y, g:i a'),
                'acknowledged' => (bool) $handover?->isAcknowledged(),
                'acknowledgedBy' => $handover?->acknowledger?->name,
                'acknowledgedAt' => $handover?->acknowledged_at?->format('j M Y, g:i a'),
                'acknowledgedRevision' => $handover?->acknowledged_revision,
            ],
        ]);
    }

    public function update(Request $request, Project $project, Handovers $handovers): RedirectResponse
    {
        $validated = $this->validatedInput($request, [
            'repository' => ['nullable', 'string', 'max:5000'],
            'deployment_guide' => ['nullable', 'string', 'max:20000'],
            'environment_notes' => ['nullable', 'string', 'max:20000'],
            'credentials_note' => ['nullable', 'string', 'max:2000'],
            'licences' => ['array', 'max:30'],
            'licences.*.name' => ['required', 'string', 'max:160'],
            'licences.*.detail' => ['nullable', 'string', 'max:300'],
            'videos' => ['array', 'max:30'],
            'videos.*.title' => ['required', 'string', 'max:160'],
            'videos.*.url' => ['required', 'url:https', 'max:255'],
            'warranty_ends_on' => ['nullable', 'date'],
        ], [
            'videos.*.url.url' => 'A video link must be an https:// address on the video host.',
        ]);

        $handovers->save($project, $request->user(), $validated);

        if ($request->boolean('publish')) {
            try {
                $handover = $handovers->publish($project, $request->user());
            } catch (InvalidArgumentException $e) {
                return back()->with('error', $e->getMessage());
            }

            return back()->with('success', "Published revision {$handover->revision}. The client has been asked to acknowledge it.");
        }

        return back()->with('success', 'Saved. The client sees it when you publish.');
    }
}
