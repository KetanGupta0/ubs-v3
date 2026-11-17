<?php

namespace App\Services\Client;

use App\Models\Handover;
use App\Models\Project;
use App\Models\SatisfactionSurvey;
use App\Models\User;
use App\Notifications\HandoverNotice;
use App\Services\Admin\Auditor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The handover pack (CL15) and the survey that follows it (CL18).
 *
 * Staff write the pack; publishing fixes what the client is shown as a
 * revision, which a signatory or an owner acknowledges. The pack says where
 * access was shared and never what it is (decision X6): anything that looks
 * like a credential is refused on save.
 */
class Handovers
{
    public const CONTENT = ['repository', 'deployment_guide', 'environment_notes', 'credentials_note', 'licences', 'videos', 'warranty_ends_on'];

    /** What a credential looks like, closely enough to refuse it. */
    protected const SECRET_PATTERNS = [
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
        // "password: Hunter2!" or DB_PASSWORD=s3cret, but not "API token: shared
        // in 1Password": the value must be one word with a digit or a symbol in
        // it, and letters may not touch the label ("passwordless" is fine).
        '/(?<![a-z])(password|passwd|pwd|passcode|secret|api[ _-]?key|access[ _-]?key|token)(?![a-z])\s*[:=]\s*["\']?(?=\S*[0-9!@#$%^&*_+=\/])\S{6,}/i',
        '/\bAKIA[0-9A-Z]{16}\b/',
        '/\bgh[pousr]_[A-Za-z0-9]{30,}\b/',
        '/\bsk_(live|test)_[A-Za-z0-9]{16,}\b/',
    ];

    public function __construct(protected Auditor $auditor) {}

    /** @param  array<string, mixed>  $content */
    public function save(Project $project, User $staff, array $content): Handover
    {
        $this->refuseCredentials($content);

        $handover = Handover::query()->firstOrNew(['project_id' => $project->id]);
        $handover->fill(collect($content)->only(self::CONTENT)->all());
        $this->auditor->actingAs($staff)->updated($handover, label: "Handover: {$project->name}");
        $handover->save();

        return $handover;
    }

    public function publish(Project $project, User $staff): Handover
    {
        $handover = Handover::query()->where('project_id', $project->id)->first();

        if (! $handover || (blank($handover->repository) && blank($handover->deployment_guide))) {
            throw new InvalidArgumentException('Say at least where the code is or how it is deployed before publishing.');
        }

        $this->refuseCredentials($handover->only(self::CONTENT));

        $handover->forceFill([
            'published_content' => [
                ...$handover->only(['repository', 'deployment_guide', 'environment_notes', 'credentials_note', 'licences', 'videos']),
                'warranty_ends_on' => $handover->warranty_ends_on?->toDateString(),
            ],
            'revision' => $handover->revision + 1,
            'published_at' => now(),
            'published_by' => $staff->id,
        ])->save();

        $this->auditor->actingAs($staff)->action('handover.published', $project, ['revision' => $handover->revision], $project->name);
        Notification::send($project->client->clientRecipients('all'), new HandoverNotice($handover, 'published'));

        return $handover;
    }

    public function acknowledge(Handover $handover, User $member, Request $request): void
    {
        DB::transaction(function () use ($handover, $member, $request) {
            $locked = Handover::query()->lockForUpdate()->findOrFail($handover->id);

            if (! $locked->isPublished() || $locked->isAcknowledged()) {
                throw new InvalidArgumentException('There is nothing new to acknowledge.');
            }

            $locked->forceFill([
                'acknowledged_revision' => $locked->revision,
                'acknowledged_at' => now(),
                'acknowledged_by' => $member->id,
                'acknowledged_ip' => $request->ip(),
            ])->save();
        });

        $handover->refresh();
        $project = $handover->project;
        $this->auditor->actingAs($member)->action('handover.acknowledged', $project, ['revision' => $handover->revision], $project->name);

        $staff = User::query()->whereIn('id', array_filter([$project->manager_id, $handover->published_by]))->get();
        Notification::send($staff->isNotEmpty() ? $staff : User::query()->where('is_owner', true)->get(), new HandoverNotice($handover, 'acknowledged'));
    }

    /** @param  array{rating: int, went_well: ?string, could_improve: ?string, quote: ?string, consent_to_publish: bool, credit_as: ?string}  $answers */
    public function survey(Project $project, User $member, array $answers): SatisfactionSurvey
    {
        if (SatisfactionSurvey::query()->where('project_id', $project->id)->where('user_id', $member->id)->exists()) {
            throw new InvalidArgumentException('You have already answered for this project. Thank you.');
        }

        $consent = $answers['consent_to_publish'] && filled($answers['quote']);

        return SatisfactionSurvey::query()->create([
            'project_id' => $project->id,
            'client_id' => $project->client->clientAccount()->id,
            'user_id' => $member->id,
            'rating' => $answers['rating'],
            'went_well' => $answers['went_well'] ?? null,
            'could_improve' => $answers['could_improve'] ?? null,
            'quote' => $answers['quote'] ?? null,
            'consent_to_publish' => $consent,
            'credit_as' => $consent ? $answers['credit_as'] : null,
            'consented_at' => $consent ? now() : null,
        ]);
    }

    /** @param  array<string, mixed>  $content */
    protected function refuseCredentials(array $content): void
    {
        $text = collect($content)->flatten()->filter(fn ($value) => is_string($value))->join("\n");

        foreach (self::SECRET_PATTERNS as $pattern) {
            if (preg_match($pattern, $text)) {
                throw ValidationException::withMessages(['handover' => 'That looks like a password, key or token. The handover says where access was shared, never what it is.']);
            }
        }
    }
}
