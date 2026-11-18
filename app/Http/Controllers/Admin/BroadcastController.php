<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\College;
use App\Models\Course;
use App\Models\MailCampaign;
use App\Models\MailCampaignRecipient;
use App\Models\Media;
use App\Models\Setting;
use App\Services\Admin\Auditor;
use App\Services\Mail\Audience;
use App\Services\Mail\Campaigns;
use App\Support\Table\Column;
use App\Support\Table\Filter;
use App\Support\Table\Table;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Announcements by email (§8): written here, for an audience chosen by
 * filters with a live count, tried on yourself first, sent now or at a time.
 * They go out inside the day's budget (X8), with greetings, from the
 * scheduler; this screen never sends a mail to an audience directly.
 */
class BroadcastController extends Controller
{
    public function index(Request $request, Campaigns $campaigns): Response|SymfonyResponse
    {
        $table = Table::for(MailCampaign::query()->with('author:id,name', 'occasion:id,name')->withCount([
            'recipients',
            'recipients as sent_count' => fn ($q) => $q->where('status', 'sent'),
            'recipients as queued_count' => fn ($q) => $q->where('status', 'queued'),
        ]))
            ->searchable(['subject'])
            ->defaultSort('-created_at')
            ->exportName('mail-sends')
            ->columns([
                Column::make('subject', 'Subject')->sortable(),
                Column::make('kind', 'Kind'),
                Column::make('when', 'When'),
                Column::make('reach', 'Sent')->numeric(),
                Column::make('status', 'Status')->sortable(),
            ])
            ->filters([
                Filter::select('kind', ['broadcast' => 'Announcements', 'greeting' => 'Greetings'], 'Kind')->placeholder('Both'),
                Filter::select('status', MailCampaign::STATUSES, 'Status')->placeholder('Any status'),
            ])
            ->transform(fn (MailCampaign $campaign) => [
                'id' => $campaign->id,
                'subject' => $campaign->subject,
                'kind' => $campaign->kind === 'greeting' ? 'Greeting: '.($campaign->occasion?->name ?? 'occasion') : 'Announcement',
                'when' => ($campaign->started_at ?? $campaign->scheduled_at)?->format('j M Y, g:i a') ?? 'Not yet',
                'reach' => "{$campaign->sent_count} of {$campaign->recipients_count}".($campaign->queued_count ? ", {$campaign->queued_count} to go" : ''),
                'status' => $campaign->statusLabel(),
                'statusKey' => $campaign->status,
                'by' => $campaign->author?->name,
            ]);

        if ($export = $table->exportResponse($request)) {
            return $export;
        }

        return Inertia::render('admin/mail/Broadcasts', [
            'table' => $table->toArray($request),
            'budget' => ['left' => $campaigns->budgetLeft(), 'limit' => (int) config('company.mail.promotional_daily_limit')],
            'enquiries' => $this->enquiriesState(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/mail/Compose', [...$this->options(), 'campaign' => null]);
    }

    public function edit(MailCampaign $campaign): Response
    {
        abort_unless($campaign->kind === 'broadcast' && in_array($campaign->status, ['draft', 'scheduled'], true), 404);

        return Inertia::render('admin/mail/Compose', [...$this->options(), 'campaign' => [
            'id' => $campaign->id,
            'subject' => $campaign->subject,
            'message' => $campaign->message,
            'media_id' => $campaign->media_id,
            'audience' => Audience::normalise($campaign->audience ?? []),
            'scheduled_at' => $campaign->scheduled_at?->format('Y-m-d\TH:i'),
            'status' => $campaign->status,
        ]]);
    }

    public function store(Request $request, Auditor $auditor, Campaigns $campaigns): RedirectResponse
    {
        [$fields, $action] = $this->validated($request);

        $campaign = MailCampaign::query()->create([...$fields, 'kind' => 'broadcast', 'created_by' => $request->user()->id]);
        $auditor->created($campaign, $campaign->subject);

        return $this->after($campaign, $action, $campaigns, $request);
    }

    public function update(Request $request, MailCampaign $campaign, Auditor $auditor, Campaigns $campaigns): RedirectResponse
    {
        abort_unless($campaign->kind === 'broadcast' && in_array($campaign->status, ['draft', 'scheduled'], true), 404);

        [$fields, $action] = $this->validated($request);
        $campaign->fill($fields);
        $auditor->updated($campaign, label: $campaign->subject);
        $campaign->save();

        return $this->after($campaign, $action, $campaigns, $request);
    }

    public function show(Request $request, MailCampaign $campaign): Response|SymfonyResponse
    {
        $table = Table::for(MailCampaignRecipient::query()->where('campaign_id', $campaign->id))
            ->searchable(['email', 'name'])
            ->defaultSort('id')
            ->exportName('mail-recipients')
            ->columns([
                Column::make('email', 'Address')->sortable(),
                Column::make('name', 'Name'),
                Column::make('who', 'Who'),
                Column::make('status', 'Status')->sortable(),
                Column::make('sent_at', 'Sent')->sortable(),
            ])
            ->filters([
                Filter::select('status', ['queued' => 'Waiting', 'sent' => 'Sent', 'skipped' => 'Not sent', 'failed' => 'Failed'], 'Status')->placeholder('Any'),
            ])
            ->transform(fn (MailCampaignRecipient $recipient) => [
                'id' => $recipient->id,
                'email' => $recipient->email,
                'name' => $recipient->name ?? '—',
                'who' => $recipient->user_id ? 'Account' : 'Enquiry',
                'status' => match ($recipient->status) {
                    'queued' => 'Waiting',
                    'sent' => 'Sent',
                    'skipped' => MailCampaignRecipient::SKIP_REASONS[$recipient->skip_reason] ?? 'Not sent',
                    default => 'Failed'.($recipient->error ? ": {$recipient->error}" : ''),
                },
                'statusKey' => $recipient->status,
                'sent_at' => $recipient->sent_at?->format('j M, g:i a') ?? '—',
            ]);

        if ($export = $table->exportResponse($request)) {
            return $export;
        }

        return Inertia::render('admin/mail/Send', [
            'campaign' => [
                'id' => $campaign->id,
                'kind' => $campaign->kind,
                'subject' => $campaign->subject,
                'status' => $campaign->status,
                'statusLabel' => $campaign->statusLabel(),
                'occasion' => $campaign->occasion?->name,
                'scheduledAt' => $campaign->scheduled_at?->format('j M Y, g:i a'),
                'startedAt' => $campaign->started_at?->format('j M Y, g:i a'),
                'finishedAt' => $campaign->finished_at?->format('j M Y, g:i a'),
                'expiresAt' => $campaign->expires_at?->format('j M Y, g:i a'),
                'counts' => $campaign->counts(),
                'canEdit' => $campaign->kind === 'broadcast' && in_array($campaign->status, ['draft', 'scheduled'], true),
                'canCancel' => in_array($campaign->status, ['draft', 'scheduled', 'sending'], true),
            ],
            'table' => $table->toArray($request),
        ]);
    }

    public function cancel(Request $request, MailCampaign $campaign, Campaigns $campaigns): RedirectResponse
    {
        try {
            $campaigns->cancel($campaign, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Cancelled. Nothing more of it goes out.');
    }

    /** The live count while the audience is chosen. */
    public function count(Request $request, Audience $audience): JsonResponse
    {
        return response()->json($audience->count(Audience::normalise((array) $request->input('audience', []))));
    }

    /** The mail as a recipient would see it, from what is on the screen now. */
    public function preview(Request $request, Campaigns $campaigns): HttpResponse
    {
        return response($campaigns->preview($this->unsaved($request), $request->user()));
    }

    public function test(Request $request, Campaigns $campaigns): RedirectResponse
    {
        $campaigns->testSend($this->unsaved($request), $request->user());

        return back()->with('success', "Sent to {$request->user()->email}.");
    }

    /**
     * Mail people who only sent an enquiry (X19): only once the privacy policy
     * that says so is published, and only when somebody says so on purpose.
     */
    public function enquiries(Request $request, Auditor $auditor): RedirectResponse
    {
        $validated = $this->validatedInput($request, ['on' => ['boolean'], 'confirm' => ['nullable', 'accepted']], [
            'confirm.accepted' => 'Tick this to say the published privacy policy tells them.',
        ]);

        if ($validated['on'] && ! Audience::policyAllows()) {
            return back()->with('error', 'Publish the privacy policy that says we send these first (Website, Legal documents).');
        }

        if ($validated['on'] && ! $validated['confirm']) {
            throw ValidationException::withMessages(['confirm' => 'Tick this to say the published privacy policy tells them.']);
        }

        Setting::put(Audience::SETTING, (bool) $validated['on'], 'mail');
        $auditor->action('mail.enquiries_'.($validated['on'] ? 'included' : 'left_out'));

        return back()->with('success', $validated['on'] ? 'People who sent an enquiry are included from now on.' : 'People who sent an enquiry are left out from now on.');
    }

    /** @return array{0: array<string, mixed>, 1: string} */
    protected function validated(Request $request): array
    {
        $validated = $this->validatedInput($request, [
            'subject' => ['required', 'string', 'max:200'],
            'message' => ['required', 'string', 'max:20000'],
            'media_id' => ['nullable', 'integer', Rule::exists('media', 'id')],
            'audience' => ['required', 'array'],
            'audience.groups' => ['required', 'array', 'min:1'],
            'action' => ['required', Rule::in(['draft', 'schedule', 'send'])],
            'scheduled_at' => ['nullable', 'required_if:action,schedule', 'date', 'after:now'],
        ], [
            'audience.groups.required' => 'Choose who it is for.',
            'audience.groups.min' => 'Choose who it is for.',
            'scheduled_at.required_if' => 'Choose when it goes.',
            'scheduled_at.after' => 'That time has passed.',
        ]);

        $audience = Audience::normalise($validated['audience']);

        if ($validated['action'] !== 'draft' && app(Audience::class)->count($audience)['reachable'] === 0) {
            throw ValidationException::withMessages(['audience' => 'Nobody it could go to: choose a wider audience.']);
        }

        return [[
            'subject' => trim($validated['subject']),
            'message' => $validated['message'],
            'media_id' => $validated['media_id'],
            'audience' => $audience,
            'status' => $validated['action'] === 'draft' ? 'draft' : 'scheduled',
            'scheduled_at' => match ($validated['action']) {
                'schedule' => $validated['scheduled_at'],
                'send' => now(),
                default => null,
            },
        ], $validated['action']];
    }

    protected function after(MailCampaign $campaign, string $action, Campaigns $campaigns, Request $request): RedirectResponse
    {
        if ($action === 'send') {
            $campaigns->start($campaign, $request->user());

            return redirect("/admin/mail/broadcasts/{$campaign->id}")
                ->with('success', 'On its way. It is in everybody\'s notifications now, and the mail goes out a few at a time within the day\'s budget.');
        }

        return redirect("/admin/mail/broadcasts/{$campaign->id}")->with('success', $action === 'schedule'
            ? 'Scheduled for '.$campaign->scheduled_at->format('j M Y, g:i a').'.'
            : 'Saved as a draft.');
    }

    /** A send that exists only for a preview or a test, never saved. */
    protected function unsaved(Request $request): MailCampaign
    {
        $validated = $this->validatedInput($request, [
            'subject' => ['required', 'string', 'max:200'],
            'message' => ['required', 'string', 'max:20000'],
            'media_id' => ['nullable', 'integer', Rule::exists('media', 'id')],
        ]);

        return (new MailCampaign)->forceFill([...$validated, 'kind' => 'broadcast', 'audience' => []])->setRelation('media', $validated['media_id'] ? Media::query()->find($validated['media_id']) : null);
    }

    /** @return array<string, mixed> */
    protected function options(): array
    {
        return [
            'groups' => collect(Audience::GROUPS)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values(),
            'courses' => Course::query()->orderBy('title')->get(['id', 'title'])->map(fn (Course $course) => ['value' => $course->id, 'label' => $course->title]),
            'batches' => Batch::query()->with('course:id,title')->latest('id')->limit(200)->get(['id', 'name', 'course_id'])->map(fn (Batch $batch) => ['value' => $batch->id, 'label' => $batch->name.($batch->course ? " ({$batch->course->title})" : '')]),
            'colleges' => College::query()->orderBy('name')->get(['id', 'name'])->map(fn (College $college) => ['value' => $college->id, 'label' => $college->name]),
            'interests' => [
                ['value' => 'solution', 'label' => 'A solution'], ['value' => 'service', 'label' => 'A service'],
                ['value' => 'training', 'label' => 'Training'], ['value' => 'internship', 'label' => 'An internship'],
                ['value' => 'college', 'label' => 'A college tie-up'], ['value' => 'demo', 'label' => 'A live demo'],
                ['value' => 'general', 'label' => 'Anything else'],
            ],
            'media' => Media::query()->latest('id')->limit(60)->get()->map(fn (Media $media) => ['value' => $media->id, 'label' => $media->alt, 'url' => $media->url()]),
            'enquiries' => $this->enquiriesState(),
        ];
    }

    /** @return array{allowed: bool, on: bool, policyAllows: bool} */
    protected function enquiriesState(): array
    {
        return ['allowed' => Audience::enquiriesAllowed(), 'on' => (bool) Setting::get(Audience::SETTING, false), 'policyAllows' => Audience::policyAllows()];
    }
}
