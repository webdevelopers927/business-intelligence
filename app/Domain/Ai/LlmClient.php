<?php

namespace App\Domain\Ai;

/**
 * Boundary between the analyst loop and the model provider. Requests and
 * response content use the Messages API wire format (snake_case arrays), so
 * transcripts can be stored and replayed verbatim. Tests bind a fake.
 */
interface LlmClient
{
    /**
     * @param  array{system: list<array<string, mixed>>, tools: list<array<string, mixed>>, messages: list<array<string, mixed>>, tool_choice?: array<string, mixed>}  $request
     *
     * @throws AiUnavailable
     */
    public function create(array $request): LlmResponse;
}
