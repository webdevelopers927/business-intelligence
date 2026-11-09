<?php

namespace App\Domain\Ai;

/**
 * Whether the AI analyst can run. Without a key the module shows a
 * configuration state — never canned or simulated answers (AI-3).
 */
final class AiConfiguration
{
    public function status(): string
    {
        return $this->apiKey() ? 'ready' : 'unconfigured';
    }

    public function ready(): bool
    {
        return $this->status() === 'ready';
    }

    public function apiKey(): ?string
    {
        $key = config('orbit.ai.api_key');

        return is_string($key) && trim($key) !== '' ? trim($key) : null;
    }

    public function model(): string
    {
        return (string) config('orbit.ai.model', 'claude-opus-5-5');
    }

    public function effort(): string
    {
        $effort = (string) config('orbit.ai.effort', 'medium');

        return in_array($effort, ['low', 'medium', 'high', 'xhigh', 'max'], true) ? $effort : 'medium';
    }

    public function maxTokens(): int
    {
        return max(1024, (int) config('orbit.ai.max_tokens', 16000));
    }

    public function maxToolRounds(): int
    {
        return max(1, min(20, (int) config('orbit.ai.max_tool_rounds', 8)));
    }

    public function timeout(): int
    {
        return max(10, (int) config('orbit.ai.timeout', 120));
    }
}
