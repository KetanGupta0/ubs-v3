<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Admin\MeetingController as AdminMeetings;
use App\Models\Meeting;
use App\Models\MeetingMinutes;
use App\Services\Client\Meetings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Meetings as the client sees them (CL11): when and where, and the minutes
 * once published, to acknowledge or question. Anybody in the organisation
 * may answer: it records that the minutes are right, not a commitment.
 */
class MeetingController extends ClientController
{
    public function index(Request $request): Response
    {
        $meetings = Meeting::query()
            ->forClient($this->client($request))
            ->with('project:id,name', 'minutes')
            ->orderByDesc('starts_at')
            ->take(100)
            ->get();

        $row = fn (Meeting $meeting) => [
            ...AdminMeetings::meetingForScreen($meeting),
            'minutesWaiting' => (bool) $meeting->publishedMinutes()?->awaitsAnswer(),
        ];

        return Inertia::render('client/meetings/Index', [
            'upcoming' => $meetings->filter(fn (Meeting $meeting) => $meeting->status === 'scheduled' && $meeting->endsAt()->isFuture())
                ->sortBy('starts_at')->map($row)->values(),
            'past' => $meetings->reject(fn (Meeting $meeting) => $meeting->status === 'scheduled' && $meeting->endsAt()->isFuture())
                ->map($row)->values(),
        ]);
    }

    public function show(Request $request, int $meeting): Response
    {
        $record = $this->own($request, Meeting::query()
            ->forClient($this->client($request))
            ->with('project:id,name', 'minutes.publisher:id,name', 'minutes.acknowledger:id,name', 'minutes.disputer:id,name'), $meeting);

        $published = $record->minutes->filter(fn (MeetingMinutes $minutes) => $minutes->published_at !== null)->values();
        $latest = $published->last();

        return Inertia::render('client/meetings/Show', [
            'meeting' => [...AdminMeetings::meetingForScreen($record), 'cancelReason' => $record->cancel_reason],
            'minutes' => $latest ? AdminMeetings::minutesForScreen($latest) : null,
            'earlier' => $published->slice(0, -1)->map(fn (MeetingMinutes $minutes) => AdminMeetings::minutesForScreen($minutes))->reverse()->values(),
            'canAnswer' => (bool) $latest?->awaitsAnswer(),
        ]);
    }

    public function acknowledge(Request $request, int $meeting, Meetings $meetings): RedirectResponse
    {
        try {
            $meetings->acknowledge($this->latestMinutes($request, $meeting), $request->user(), $request);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Acknowledged. Thank you.');
    }

    public function dispute(Request $request, int $meeting, Meetings $meetings): RedirectResponse
    {
        $validated = $this->validatedInput($request, [
            'note' => ['required', 'string', 'min:10', 'max:3000'],
        ], [
            'note.required' => 'Say what is not right, so we can correct it.',
            'note.min' => 'Say a little more about what is not right.',
        ]);

        try {
            $meetings->dispute($this->latestMinutes($request, $meeting), $request->user(), $validated['note']);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Sent. We will correct the minutes and send the new version.');
    }

    protected function latestMinutes(Request $request, int $meeting): MeetingMinutes
    {
        $record = $this->own($request, Meeting::query()->forClient($this->client($request)), $meeting);

        // reorder(): the relation already sorts oldest first.
        return $record->minutes()->whereNotNull('published_at')->reorder('version', 'desc')->firstOrFail();
    }
}
