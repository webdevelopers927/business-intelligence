<?php

namespace App\Domain\Ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\OverloadedException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;

/**
 * Claude via the official Anthropic PHP SDK (beta namespace, for server-side
 * refusal fallbacks).
 *
 * - Model and effort come from config (default claude-opus-5-5, effort medium;
 *   thinking is adaptive and always on for this model, so it isn't sent).
 * - `fallbacks: "default"` re-runs a policy-declined request on Anthropic's
 *   recommended fallback model inside the same call.
 * - Top-level `cache_control` caches the growing conversation prefix across
 *   the tool loop; the stable system block carries its own breakpoint.
 */
final class AnthropicLlmClient implements LlmClient
{
    public const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    private ?Client $client = null;

    public function __construct(private readonly AiConfiguration $config) {}

    public function create(array $request): LlmResponse
    {
        try {
            $message = $this->client()->beta->messages->create(
                maxTokens: $this->config->maxTokens(),
                messages: $request['messages'],
                model: $this->config->model(),
                cacheControl: ['type' => 'ephemeral'],
                fallbacks: 'default',
                outputConfig: ['effort' => $this->config->effort()],
                system: $request['system'],
                toolChoice: $request['tool_choice'] ?? null,
                tools: $request['tools'],
                betas: [self::FALLBACK_BETA],
            );
        } catch (AuthenticationException|PermissionDeniedException) {
            throw new AiUnavailable('The AI provider rejected the configured API key. An administrator needs to check ANTHROPIC_API_KEY.', 503, false);
        } catch (RateLimitException|OverloadedException) {
            throw new AiUnavailable('The AI analyst is busy right now. Please try again in a minute.', 429);
        } catch (BadRequestException $e) {
            report($e);
            throw new AiUnavailable('The AI provider could not process this conversation. Try starting a new one.', 502, false);
        } catch (APIStatusException $e) {
            report($e);
            throw new AiUnavailable('The AI provider returned an error. Please try again.', 502);
        } catch (APIConnectionException) {
            throw new AiUnavailable('Could not reach the AI provider. Check the connection and try again.', 504);
        }

        /** @var array<string, mixed> $raw wire format, including fields the SDK doesn't model */
        $raw = json_decode((string) json_encode($message), true);

        return new LlmResponse(
            content: array_values($raw['content'] ?? []),
            stopReason: $raw['stop_reason'] ?? null,
            model: (string) ($raw['model'] ?? $this->config->model()),
            inputTokens: (int) ($raw['usage']['input_tokens'] ?? 0) + (int) ($raw['usage']['cache_creation_input_tokens'] ?? 0) + (int) ($raw['usage']['cache_read_input_tokens'] ?? 0),
            outputTokens: (int) ($raw['usage']['output_tokens'] ?? 0),
            cacheReadTokens: (int) ($raw['usage']['cache_read_input_tokens'] ?? 0),
            refusalCategory: $raw['stop_details']['category'] ?? null,
        );
    }

    private function client(): Client
    {
        return $this->client ??= new Client(
            apiKey: (string) $this->config->apiKey(),
            requestOptions: ['timeout' => (float) $this->config->timeout(), 'maxRetries' => 2],
        );
    }
}
