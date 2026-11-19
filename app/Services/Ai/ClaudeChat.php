<?php

namespace App\Services\Ai;

use Anthropic\Client;

/**
 * One Messages API request for the visitor assistant, and nothing else of
 * the SDK leaks past this class.
 *
 * Answered whole, never streamed: on shared hosting every open stream holds
 * an entry process, so the page waits a few seconds for each reply instead.
 * The model is the one set for the assistant (X3). A request the model
 * declines is passed to the fallback the API chooses for that kind of
 * refusal, so a visitor rarely meets a dead end. The tests stand in for this
 * class, so it stays a thin translation of the SDK.
 */
class ClaudeChat
{
    public function configured(): bool
    {
        return filled(config('services.anthropic.key'));
    }

    /**
     * @param  array<int, array<string, mixed>>  $system  Text blocks.
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $tools
     * @return array{stop: ?string, content: array<int, mixed>, text: string, tool_uses: array<int, array{id: string, name: string, input: array<string, mixed>}>, input_tokens: int, output_tokens: int}
     */
    public function send(array $system, array $messages, array $tools): array
    {
        $response = $this->client()->beta->messages->create(
            model: config('company.ai.assistant_model'),
            maxTokens: 4096,
            system: $system,
            messages: $messages,
            tools: $tools,
            // A short conversation: little thinking, quick answers.
            outputConfig: ['effort' => 'low'],
            // The instructions and tools are the same for everybody, and a
            // conversation grows by appending, so the start is cached.
            cacheControl: ['type' => 'ephemeral'],
            betas: ['server-side-fallback-2026-07-01'],
            fallbacks: 'default',
        );

        $text = '';
        $uses = [];

        foreach ($response->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            } elseif ($block->type === 'tool_use') {
                $uses[] = ['id' => $block->id, 'name' => $block->name, 'input' => (array) $block->input];
            }
        }

        return [
            'stop' => $response->stopReason,
            // Passed back unchanged within the same answer's tool loop.
            'content' => $response->content,
            'text' => $text,
            'tool_uses' => $uses,
            'input_tokens' => $response->usage->inputTokens,
            'output_tokens' => $response->usage->outputTokens,
        ];
    }

    protected function client(): Client
    {
        return new Client(apiKey: config('services.anthropic.key'));
    }
}
