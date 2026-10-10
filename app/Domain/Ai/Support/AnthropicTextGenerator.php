<?php

namespace App\Domain\Ai\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Claude through the Anthropic Messages API.
 */
class AnthropicTextGenerator implements TextGenerator
{
    public function model(): string
    {
        return (string) config('dealer.ai.model');
    }

    public function generate(string $system, string $prompt, int $maxTokens = 1500): AiResult
    {
        $key = config('dealer.ai.api_key');

        if (! is_string($key) || $key === '') {
            throw new AiException(__('AI is not set up on this server.'));
        }

        try {
            $response = Http::timeout((int) config('dealer.ai.timeout', 60))
                ->withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])
                ->acceptJson()
                ->post(rtrim((string) config('dealer.ai.base_url'), '/').'/v1/messages', [
                    'model' => $this->model(),
                    'max_tokens' => $maxTokens,
                    'system' => $system,
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                ]);
        } catch (ConnectionException $e) {
            throw new AiException(__('The AI service cannot be reached: :reason', ['reason' => $e->getMessage()]));
        }

        if (! $response->successful()) {
            throw new AiException(match (true) {
                $response->status() === 429 => __('The AI service is busy; try again in a minute.'),
                $response->status() >= 500 => __('The AI service has a problem (:code); try again later.', ['code' => $response->status()]),
                default => __('The AI service refused the request (:code): :detail', ['code' => $response->status(), 'detail' => (string) ($response->json('error.message') ?? mb_substr($response->body(), 0, 200))]),
            });
        }

        $text = collect((array) $response->json('content'))
            ->filter(fn ($block): bool => is_array($block) && ($block['type'] ?? null) === 'text')
            ->map(fn (array $block): string => (string) ($block['text'] ?? ''))
            ->implode("\n");

        return new AiResult(trim($text), (int) $response->json('usage.input_tokens', 0), (int) $response->json('usage.output_tokens', 0));
    }
}
