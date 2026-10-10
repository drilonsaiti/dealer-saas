<?php

namespace App\Domain\Ai\Support;

use App\Domain\Ai\Models\AiRequest;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * The gate to the language model: only when the server has a key and the dealer switched AI
 * on, within the monthly limit; every request logged (purpose, tokens, outcome).
 */
class Assistant
{
    public function __construct(
        private readonly TextGenerator $generator,
        private readonly TenantContext $context,
    ) {}

    public function available(): bool
    {
        $tenant = $this->context->tenant();

        return filled(config('dealer.ai.api_key')) && $tenant !== null && (bool) $tenant->setting('ai.enabled', false);
    }

    public function ask(string $purpose, string $system, string $prompt, ?Model $subject = null, int $maxTokens = 1500): string
    {
        if (! $this->available()) {
            throw new AiException(__('AI assistance is switched off (company profile).'));
        }

        $used = AiRequest::query()->where('created_at', '>=', now()->startOfMonth())->count();

        if ($used >= (int) config('dealer.ai.monthly_requests', 500)) {
            throw new AiException(__('The monthly limit of :count AI requests is reached.', ['count' => (int) config('dealer.ai.monthly_requests', 500)]));
        }

        $started = hrtime(true);
        $log = fn (string $status, ?AiResult $result = null, ?string $error = null) => AiRequest::query()->create([
            'user_id' => auth()->id(),
            'purpose' => $purpose,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'model' => $this->generator->model(),
            'input_tokens' => $result->inputTokens ?? 0,
            'output_tokens' => $result->outputTokens ?? 0,
            'status' => $status,
            'error' => $error,
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
        ]);

        try {
            $result = $this->generator->generate($system, $prompt, $maxTokens);
        } catch (AiException $e) {
            $log('error', null, $e->getMessage());

            throw $e;
        } catch (Throwable $e) {
            report($e);
            $log('error', null, $e->getMessage());

            throw new AiException(__('The AI suggestion failed. Please try again.'));
        }

        $log('ok', $result);

        if ($result->text === '') {
            throw new AiException(__('The AI gave no answer. Please try again.'));
        }

        return $result->text;
    }
}
