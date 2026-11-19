<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\AssistantConversation;
use App\Services\Assistant\Assistant;
use App\Support\Identifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

/**
 * The assistant's three doors on the public site (§9.2): start a
 * conversation once the notice is agreed to, send a message, send the
 * details card. Each answers 404 while the assistant is off, and every one is
 * limited per address as well as in the route.
 */
class AssistantController extends Controller
{
    /** Conversations one address may start in an hour, and messages it may send in a day. */
    protected const STARTS_PER_HOUR = 6;

    protected const MESSAGES_PER_DAY = 150;

    public function __construct(protected Assistant $assistant) {}

    public function start(Request $request): JsonResponse
    {
        abort_unless($this->assistant->available(), 404);

        $validated = $this->validatedInput($request, [
            'agree' => ['accepted'],
            'page' => ['nullable', 'string', 'max:255'],
        ], [
            'agree.accepted' => 'Agree to the notice to start.',
        ]);

        if ($this->assistant->limitReached()) {
            return $this->closed('I am not taking new conversations today. Please write to the team on the [contact page](/contact): a person reads every message.');
        }

        $key = 'assistant-start:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::STARTS_PER_HOUR)) {
            return $this->closed('That is a lot of conversations from here. Please write to the team on the [contact page](/contact) instead.');
        }

        RateLimiter::hit($key, 3600);
        $page = is_string($validated['page']) && str_starts_with($validated['page'], '/') ? $validated['page'] : null;

        return response()->json(['token' => $this->assistant->start($page)['token']]);
    }

    public function message(Request $request): JsonResponse
    {
        abort_unless($this->assistant->available(), 404);

        $validated = $this->validatedInput($request, [
            'token' => ['required', 'string', 'size:40'],
            'message' => ['required', 'string', 'max:'.Assistant::MAX_MESSAGE_LENGTH],
        ]);

        $conversation = AssistantConversation::findByToken($validated['token']);
        abort_unless($conversation !== null, 404);

        $key = 'assistant-day:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MESSAGES_PER_DAY)) {
            return $this->closed(Assistant::HANDOVER);
        }

        RateLimiter::hit($key, 86400);

        try {
            return response()->json($this->assistant->reply($conversation, $validated['message']));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** The visitor pressed Send on the card: their details, as they left them. */
    public function lead(Request $request): JsonResponse
    {
        abort_unless($this->assistant->available(), 404);

        $request->merge([
            'email' => mb_strtolower(trim((string) $request->input('email'))),
            'mobile' => $request->filled('mobile') ? Identifier::normaliseMobile((string) $request->input('mobile')) : null,
        ]);

        $validated = $this->validatedInput($request, [
            'token' => ['required', 'string', 'size:40'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'mobile' => ['nullable', 'string', 'regex:/^\+\d{10,15}$/'],
            'need' => ['required', 'string', 'max:1000'],
        ], [
            'mobile.regex' => 'Enter a valid mobile number, including the country code.',
        ]);

        $conversation = AssistantConversation::findByToken($validated['token']);
        abort_unless($conversation !== null, 404);

        try {
            $this->assistant->confirmLead($conversation, [
                'name' => $validated['name'],
                'email' => $validated['email'],
                'mobile' => $validated['mobile'],
                'need' => $validated['need'],
            ], $request->ip(), $request->userAgent());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['sent' => true, 'html' => Assistant::html('Sent. The team will reply to you within one working day.')]);
    }

    protected function closed(string $reply): JsonResponse
    {
        return response()->json(['closed' => true, 'reply' => $reply, 'html' => Assistant::html($reply), 'offer' => null]);
    }
}
