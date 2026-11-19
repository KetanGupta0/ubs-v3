<?php

namespace App\Services\Ai;

use Anthropic\Client;
use Generator;

/**
 * The Message Batches API, and nothing else of the SDK leaks past this class.
 *
 * A batch is sent, runs on Anthropic's side within a day at half the price,
 * and is read back when it has ended; nothing waits on it, which is what
 * shared hosting needs (no request held open, no long process). The tests
 * stand in for this class, so it stays a thin translation of the SDK.
 */
class ClaudeBatches
{
    public function configured(): bool
    {
        return filled(config('services.anthropic.key'));
    }

    /**
     * Send requests as one batch. Returns the batch's id.
     *
     * @param  array<int, array{customID: string, params: array<string, mixed>}>  $requests
     */
    public function create(array $requests): string
    {
        return $this->client()->messages->batches->create(requests: $requests)->id;
    }

    /** Whether every request in the batch has finished, one way or another. */
    public function ended(string $batchId): bool
    {
        return $this->client()->messages->batches->retrieve($batchId)->processingStatus === 'ended';
    }

    /**
     * Each request's outcome, by its custom id, in whatever order they come.
     *
     * @return Generator<string, array{type: string, text: ?string, stop: ?string, input_tokens: ?int, output_tokens: ?int, error: ?string}>
     */
    public function results(string $batchId): Generator
    {
        foreach ($this->client()->messages->batches->resultsStream($batchId) as $response) {
            $result = $response->result;
            $outcome = ['type' => $result->type, 'text' => null, 'stop' => null, 'input_tokens' => null, 'output_tokens' => null, 'error' => null];

            if ($result->type === 'succeeded') {
                $message = $result->message;
                $text = '';

                foreach ($message->content as $block) {
                    if ($block->type === 'text') {
                        $text .= $block->text;
                    }
                }

                $outcome['text'] = $text;
                $outcome['stop'] = $message->stopReason;
                $outcome['input_tokens'] = $message->usage->inputTokens;
                $outcome['output_tokens'] = $message->usage->outputTokens;
            } elseif ($result->type === 'errored') {
                $outcome['error'] = $result->error->error->message ?? 'The request failed.';
            }

            yield $response->customID => $outcome;
        }
    }

    protected function client(): Client
    {
        return new Client(apiKey: config('services.anthropic.key'));
    }
}
