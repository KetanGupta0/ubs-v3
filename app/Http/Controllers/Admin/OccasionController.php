<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MailCampaign;
use App\Models\Media;
use App\Models\Occasion;
use App\Services\Admin\Auditor;
use App\Services\Mail\Audience;
use App\Services\Mail\Campaigns;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The days worth a greeting, and the greeting for each (§8, X7), set by the
 * admin. A festival's date is confirmed each year; until it is, its greeting
 * does not go. What went out is under Broadcasts, with the rest of the mail.
 */
class OccasionController extends Controller
{
    public function index(Campaigns $campaigns): Response
    {
        $occasions = Occasion::query()->with('media')->withCount('campaigns')->orderByRaw('date is null')->orderBy('date')->get();

        return Inertia::render('admin/mail/Occasions', [
            'occasions' => $occasions->map(fn (Occasion $occasion) => [
                'id' => $occasion->id,
                'name' => $occasion->name,
                'date' => $occasion->date?->toDateString(),
                'dateLabel' => $occasion->date?->format('j M Y'),
                'daysAway' => $occasion->date ? (int) today()->diffInDays($occasion->date, false) : null,
                'repeats' => $occasion->repeats,
                'date_confirmed' => $occasion->date_confirmed,
                'send_time' => $occasion->send_time,
                'audience' => $occasion->audience ?? [],
                'subject' => $occasion->subject,
                'message' => $occasion->message,
                'media_id' => $occasion->media_id,
                'image' => $occasion->media?->url(),
                'is_active' => $occasion->is_active,
                'ready' => $occasion->isReady(),
                'lastSentOn' => $occasion->last_sent_on?->format('j M Y'),
                'sends' => $occasion->campaigns_count,
            ])->values(),
            'groups' => collect(Audience::GROUPS)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values(),
            'repeats' => collect(Occasion::REPEATS)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values(),
            'media' => Media::query()->latest('id')->limit(60)->get()->map(fn (Media $media) => ['value' => $media->id, 'label' => $media->alt, 'url' => $media->url()]),
            'enquiriesAllowed' => Audience::enquiriesAllowed(),
            'budget' => ['left' => $campaigns->budgetLeft(), 'limit' => (int) config('company.mail.promotional_daily_limit')],
        ]);
    }

    public function store(Request $request, Auditor $auditor): RedirectResponse
    {
        $occasion = Occasion::query()->create([...$this->validated($request), 'created_by' => $request->user()->id]);
        $auditor->created($occasion, $occasion->name);

        return back()->with('success', $occasion->isReady() ? "{$occasion->name} is set." : "{$occasion->name} is saved. Switch it on, with a confirmed date, for its greeting to go.");
    }

    public function update(Request $request, Occasion $occasion, Auditor $auditor): RedirectResponse
    {
        $occasion->fill($this->validated($request));
        $auditor->updated($occasion, label: $occasion->name);
        $occasion->save();

        return back()->with('success', 'Saved. A greeting already sent keeps the words it went with.');
    }

    public function destroy(Occasion $occasion, Auditor $auditor): RedirectResponse
    {
        if ($occasion->campaigns()->exists()) {
            return back()->with('error', 'A greeting went out for this day, so it stays on record. Switch it off instead.');
        }

        $auditor->deleted($occasion, $occasion->name);
        $occasion->delete();

        return back()->with('success', 'Removed.');
    }

    public function preview(Request $request, Occasion $occasion, Campaigns $campaigns): HttpResponse
    {
        return response($campaigns->preview($this->asSend($occasion), $request->user()));
    }

    public function test(Request $request, Occasion $occasion, Campaigns $campaigns): RedirectResponse
    {
        $campaigns->testSend($this->asSend($occasion), $request->user());

        return back()->with('success', "Sent to {$request->user()->email}.");
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request): array
    {
        $validated = $this->validatedInput($request, [
            'name' => ['required', 'string', 'max:120'],
            'date' => ['nullable', 'date'],
            'repeats' => ['required', Rule::in(array_keys(Occasion::REPEATS))],
            'date_confirmed' => ['boolean'],
            'send_time' => ['required', 'date_format:H:i'],
            'audience' => ['required', 'array', 'min:1'],
            'audience.*' => [Rule::in(array_keys(Audience::GROUPS))],
            'subject' => ['required', 'string', 'max:200'],
            'message' => ['required', 'string', 'max:5000'],
            'media_id' => ['nullable', 'integer', Rule::exists('media', 'id')],
            'is_active' => ['boolean'],
        ], [
            'audience.required' => 'Choose who it goes to.',
            'audience.min' => 'Choose who it goes to.',
            'send_time.date_format' => 'A time of day, such as 08:00.',
        ]);

        return [
            ...$validated,
            'name' => trim($validated['name']),
            'audience' => array_values(array_unique($validated['audience'])),
            // A date that moves is only ever confirmed by somebody saying so.
            'date_confirmed' => $validated['date'] !== null && ($validated['repeats'] === 'same_date' || $validated['date_confirmed']),
        ];
    }

    protected function asSend(Occasion $occasion): MailCampaign
    {
        return (new MailCampaign)->forceFill([
            'kind' => 'greeting',
            'subject' => $occasion->subject,
            'message' => $occasion->message,
            'media_id' => $occasion->media_id,
            'audience' => [],
        ])->setRelation('media', $occasion->media);
    }
}
