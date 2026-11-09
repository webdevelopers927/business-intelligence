<?php

namespace App\Domain\Ai;

final class LlmResponse
{
    /**
     * @param  list<array<string, mixed>>  $content  wire-format content blocks, exactly as returned
     */
    public function __construct(
        public readonly array $content,
        public readonly ?string $stopReason,
        public readonly string $model,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly int $cacheReadTokens = 0,
        public readonly ?string $refusalCategory = null,
    ) {}

    /** @return list<array<string, mixed>> */
    public function toolUses(): array
    {
        return array_values(array_filter($this->content, fn ($b) => ($b['type'] ?? null) === 'tool_use'));
    }

    public function text(): string
    {
        $parts = array_map(fn ($b) => (string) ($b['text'] ?? ''), array_filter($this->content, fn ($b) => ($b['type'] ?? null) === 'text'));

        return trim(implode("\n\n", array_filter($parts, fn ($t) => trim($t) !== '')));
    }
}
