<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AssistantConversation;
use App\Models\AssistantGap;
use App\Models\AssistantMessage;
use App\Models\Setting;
use App\Services\Admin\Auditor;
use App\Services\Ai\ClaudeChat;
use App\Services\Assistant\Assistant;
use App\Support\Table\Column;
use App\Support\Table\Filter;
use App\Support\Table\Table;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The website assistant from our side (§9.2): switching it on, how long its
 * conversations are kept and how many it takes a day (both set here, as the
 * owner asked), its conversations and the leads they produced, and the
 * questions it could not answer, which show where the site's words fall short.
 */
class AssistantController extends Controller
{
    public const OUTCOMES = ['lead' => 'Became a lead', 'capped' => 'Reached the limit', 'refused' => 'Declined by the model'];

    public function index(Request $request, ClaudeChat $chat): Response|SymfonyResponse
    {
        $first = AssistantMessage::query()->select('body')
            ->whereColumn('assistant_conversation_id', 'assistant_conversations.id')
            ->where('role', 'visitor')->orderBy('id')->limit(1);

        $table = Table::for(AssistantConversation::query()->select('assistant_conversations.*')->addSelect(['first_question' => $first])->with('lead:id,name'))
            ->defaultSort('-created_at')
            ->exportName('assistant-conversations')
            ->columns([
                Column::make('created_at', 'Started')->sortable(),
                Column::make('first_question', 'First question'),
                Column::make('visitor_messages', 'Messages')->sortable(),
                Column::make('outcome', 'Outcome')->sortable(),
                Column::make('page', 'Started on'),
            ])
            ->filters([
                Filter::select('outcome', self::OUTCOMES, 'Outcome')->placeholder('Any outcome'),
            ])
            ->transform(fn (AssistantConversation $conversation) => [
                'id' => $conversation->id,
                'created_at' => $conversation->created_at->format('j M, g:i a'),
                'first_question' => Str::limit((string) $conversation->first_question, 120),
                'visitor_messages' => $conversation->visitor_messages,
                'outcome' => $conversation->lead ? 'Lead: '.$conversation->lead->name : (self::OUTCOMES[$conversation->outcome] ?? '—'),
                'page' => $conversation->page ?? '—',
            ]);

        if ($export = $table->exportResponse($request)) {
            return $export;
        }

        $settings = Assistant::settings();
        $month = AssistantConversation::query()->where('created_at', '>=', now()->subDays(30));

        return Inertia::render('admin/assistant/Index', [
            'table' => $table->toArray($request),
            'settings' => [
                'enabled' => $settings['enabled'],
                'retention_days' => $settings['retention_days'],
                'daily_limit' => $settings['daily_limit'],
            ],
            'state' => [
                'configured' => $chat->configured(),
                'policyAllows' => Assistant::policyAllows(),
                'policyVersion' => $settings['policy_version'],
                'live' => app(Assistant::class)->available(),
                'retentionLimit' => Assistant::RETENTION_LIMIT,
                'model' => config('company.ai.assistant_model'),
            ],
            'stats' => [
                'today' => AssistantConversation::query()->where('created_at', '>=', today())->count(),
                'month' => (clone $month)->count(),
                'leads' => (clone $month)->whereNotNull('lead_id')->count(),
                'tokens' => (int) (clone $month)->sum('input_tokens') + (int) (clone $month)->sum('output_tokens'),
            ],
            // The newest questions it could not answer, the same question counted once.
            'gaps' => AssistantGap::query()->latest('id')->limit(200)->get()
                ->groupBy(fn (AssistantGap $gap) => Str::lower(Str::squish($gap->question)))
                ->map(fn ($same) => ['question' => $same->first()->question, 'times' => $same->count(), 'last' => $same->first()->created_at?->format('j M')])
                ->sortByDesc('times')->take(30)->values(),
        ]);
    }

    public function update(Request $request, Auditor $auditor, ClaudeChat $chat): RedirectResponse
    {
        $validated = $this->validatedInput($request, [
            'enabled' => ['boolean'],
            'policy_ack' => ['boolean'],
            'retention_days' => ['required', 'integer', 'min:1', 'max:'.Assistant::RETENTION_LIMIT],
            'daily_limit' => ['required', 'integer', 'min:0', 'max:5000'],
        ], [
            'retention_days.max' => 'At most '.Assistant::RETENTION_LIMIT.' days, which is what the privacy policy promises.',
        ]);

        Cache::forget('assistant.policy_published');

        if ($validated['enabled']) {
            $problem = match (true) {
                ! $chat->configured() => 'The Claude API key is not set, so it cannot answer anybody.',
                ! Assistant::policyAllows() => 'Publish version '.Assistant::settings()['policy_version'].' of the privacy policy first: it says what happens to what visitors type.',
                ! $validated['policy_ack'] => 'Tick that the published privacy policy tells visitors about the assistant.',
                default => null,
            };

            if ($problem) {
                return back()->withErrors(['enabled' => $problem]);
            }
        }

        $before = Assistant::settings();

        Setting::put('assistant.enabled', $validated['enabled'], 'assistant');
        Setting::put('assistant.retention_days', (int) $validated['retention_days'], 'assistant');
        Setting::put('assistant.daily_limit', (int) $validated['daily_limit'], 'assistant');

        $changes = collect(['enabled', 'retention_days', 'daily_limit'])
            ->filter(fn (string $key) => $before[$key] != $validated[$key])
            ->mapWithKeys(fn (string $key) => [$key => ['from' => $before[$key], 'to' => $validated[$key]]])
            ->all();

        if ($changes !== []) {
            $auditor->action('assistant.settings', null, $changes, 'Website assistant');
        }

        return back()->with('success', $validated['enabled'] ? 'Saved. The assistant is on the public site.' : 'Saved. The assistant is off.');
    }

    public function show(AssistantConversation $conversation): Response
    {
        $conversation->load(['messages', 'gaps', 'lead:id,name,reference']);

        return Inertia::render('admin/assistant/Conversation', [
            'conversation' => [
                'id' => $conversation->id,
                'started' => $conversation->created_at->format('j M Y, g:i a'),
                'page' => $conversation->page,
                'outcome' => self::OUTCOMES[$conversation->outcome] ?? null,
                'lead' => $conversation->lead ? ['id' => $conversation->lead->id, 'name' => $conversation->lead->name] : null,
                'tokens' => $conversation->input_tokens + $conversation->output_tokens,
                'deletes' => $conversation->created_at->copy()->addDays(Assistant::settings()['retention_days'])->format('j M Y'),
            ],
            'messages' => $conversation->messages->map(fn (AssistantMessage $message) => [
                'id' => $message->id,
                'role' => $message->role,
                'html' => $message->role === 'assistant' ? Assistant::html($message->body) : null,
                'text' => $message->role === 'visitor' ? $message->body : null,
                'tools' => collect($message->tools ?? [])->map(fn (array $tool) => $tool['name'].(($tool['input'] ?? []) !== [] ? ' '.json_encode($tool['input'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''))->all(),
                'at' => $message->created_at?->format('g:i a'),
            ]),
            'gaps' => $conversation->gaps->pluck('question'),
        ]);
    }
}
