<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\MessageReport;
use App\Services\Chat\Messenger;
use App\Services\Chat\Moderation;
use App\Support\Table\Column;
use App\Support\Table\Filter;
use App\Support\Table\Table;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Messages members reported in their batch groups, for a person to decide.
 *
 * Remove the message, keep its sender from posting for a while, or dismiss
 * the report. Each of those is audited where it happens, in Messenger and
 * Moderation, so this screen cannot do anything the chat screen could not.
 */
class ChatReportController extends Controller
{
    public function index(Request $request): Response|HttpResponse
    {
        $table = Table::for(MessageReport::query()->with([
            'message' => fn ($query) => $query->withTrashed()->with('sender:id,name'),
            'conversation.batch:id,name,trainer_id',
            'reporter:id,name',
            'handler:id,name',
        ]))
            ->defaultSort('-created_at')
            ->exportName('reported-messages')
            ->columns([
                Column::make('message', 'Message'),
                Column::make('sender', 'Sent by'),
                Column::make('group', 'Group'),
                Column::make('reporter', 'Reported by'),
                Column::make('reason', 'Why'),
                Column::make('status', 'Status')->sortable(),
                Column::make('created_at', 'Reported')->sortable(),
            ])
            ->filters([
                Filter::select('status', MessageReport::STATUSES, 'Status')->placeholder('Any'),
            ])
            ->transform(fn (MessageReport $report) => [
                'id' => $report->id,
                'message' => $report->message?->trashed() ? 'Removed' : str($report->message?->preview())->limit(200)->toString(),
                'removed' => (bool) $report->message?->trashed(),
                'sender' => $report->message?->sender?->name,
                'senderId' => $report->message?->sender_id,
                'group' => $report->conversation?->displayTitle(),
                'conversationId' => $report->conversation_id,
                'messageId' => $report->message_id,
                'reporter' => $report->reporter?->name,
                'reason' => $report->reason ?? '—',
                'status' => $report->status,
                'statusLabel' => MessageReport::STATUSES[$report->status] ?? $report->status,
                'handledBy' => $report->handler?->name,
                'created_at' => $report->created_at->diffForHumans(),
                'canModerate' => (bool) $report->conversation?->canBeModeratedBy($request->user()),
            ]);

        if ($export = $table->exportResponse($request)) {
            return $export;
        }

        return Inertia::render('admin/chat/Reports', [
            'table' => $table->toArray($request),
            'waiting' => MessageReport::query()->where('status', 'open')->distinct('message_id')->count('message_id'),
            'silenceOptions' => collect(Moderation::SILENCE_FOR)->map(fn (array $option, string $key) => ['value' => $key, 'label' => $option[0]])->values(),
        ]);
    }

    public function remove(Request $request, MessageReport $report, Messenger $messenger): RedirectResponse
    {
        $message = Message::query()->withTrashed()->findOrFail($report->message_id);

        if ($message->trashed()) {
            app(Moderation::class)->resolveReportsOn($message, $request->user());

            return back()->with('success', 'It had already been removed.');
        }

        abort_unless($report->conversation->canBeModeratedBy($request->user()), 403);
        $messenger->remove($message, $request->user());

        return back()->with('success', 'Removed. The bubble says so, and the reports on it are closed.');
    }

    public function silence(Request $request, MessageReport $report, Moderation $moderation): RedirectResponse
    {
        $validated = $this->validatedInput($request, [
            'for' => ['required', Rule::in(array_keys(Moderation::SILENCE_FOR))],
            'reason' => ['nullable', 'string', 'max:300'],
        ]);

        $sender = Message::query()->withTrashed()->findOrFail($report->message_id)->sender;

        try {
            $until = $moderation->silence($report->conversation, $sender, $validated['for'], $request->user(), $validated['reason']);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$sender->name} cannot post in that group until {$until->format('g:i a, j M')}.");
    }

    public function dismiss(Request $request, MessageReport $report, Moderation $moderation): RedirectResponse
    {
        $moderation->dismiss($report, $request->user());

        return back()->with('success', 'Dismissed. The message stays.');
    }
}
