<?php

namespace Tests\Support;

use App\Domain\Ai\AiUnavailable;
use App\Domain\Ai\LlmClient;
use App\Domain\Ai\LlmResponse;
use RuntimeException;

/** Scripted model: returns queued responses in order and records every request. */
final class FakeLlmClient implements LlmClient
{
    /** @var list<array<string, mixed>> */
    public array $requests = [];

    /** @param list<LlmResponse|AiUnavailable|callable(array<string, mixed>): LlmResponse> $script */
    public function __construct(private array $script = []) {}

    public function create(array $request): LlmResponse
    {
        $this->requests[] = $request;
        $next = array_shift($this->script) ?? throw new RuntimeException('FakeLlmClient ran out of scripted responses.');
        if ($next instanceof AiUnavailable) {
            throw $next;
        }

        return is_callable($next) ? $next($request) : $next;
    }

    /** @param array<string, mixed> $input */
    public static function toolUse(string $id, string $name, array $input, string $thinkingSignature = 'sig'): LlmResponse
    {
        return new LlmResponse(
            content: [
                ['type' => 'thinking', 'thinking' => '', 'signature' => $thinkingSignature],
                ['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input],
            ],
            stopReason: 'tool_use',
            model: 'claude-opus-5-5',
            inputTokens: 100,
            outputTokens: 20,
        );
    }

    public static function answer(string $text, string $stop = 'end_turn'): LlmResponse
    {
        return new LlmResponse(content: $text === '' ? [] : [['type' => 'text', 'text' => $text]], stopReason: $stop, model: 'claude-opus-5-5', inputTokens: 150, outputTokens: 40);
    }
}
