<?php

namespace App\Http\Controllers\App;

use App\Domain\Ai\AiAnalyst;
use App\Domain\Ai\AiConfiguration;
use App\Domain\Ai\AiUnavailable;
use App\Domain\Analytics\AnalyticsEngine;
use App\Domain\Audit\AuditLogger;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * AI analyst. Conversations are private to their author within the current
 * organization; another member's (or organization's) conversation is a 404.
 */
final class AssistantController extends Controller
{
    public function __construct(private readonly AiConfiguration $config) {}

    public function index(Request $request, AnalyticsEngine $engine, ?AiConversation $conversation = null): Response
    {
        Gate::authorize(Permission::AiUse->value);
        /** @var User $user */
        $user = $request->user();
        if ($conversation !== null) {
            abort_unless($conversation->user_id === $user->id, 404);
        }

        return Inertia::render('assistant/Index', [
            'status' => $this->config->status(),
            'model' => $this->config->ready() ? $this->config->model() : null,
            'hasData' => $engine->hasData(),
            'canConfigure' => $user->can(Permission::OrgSettingsManage->value),
            'conversations' => AiConversation::query()->where('user_id', $user->id)->latest('updated_at')->limit(30)->get(['id', 'title', 'updated_at'])
                ->map(fn (AiConversation $c) => ['id' => $c->id, 'title' => $c->title, 'updated_at' => $c->updated_at?->toIso8601String()]),
            'conversation' => $conversation === null ? null : [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'messages' => $conversation->messages()->get()->map(fn (AiMessage $m) => $this->present($m)),
            ],
            'suggestions' => [
                'How did revenue last month compare with the month before?',
                'Which branch had the lowest net margin this quarter, and why?',
                'What are the top 5 products by gross profit in the last 30 days?',
                'Were there any unusual days recently? What caused them?',
                'What is the revenue forecast for the next 3 months?',
                'Which expense categories grew the most this year?',
            ],
        ]);
    }

    public function store(Request $request, AiAnalyst $analyst, AuditLogger $audit): JsonResponse
    {
        Gate::authorize(Permission::AiUse->value);
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'message' => ['required', 'string', 'max:'.AiAnalyst::MAX_QUESTION],
            'conversation_id' => ['nullable', 'integer'],
        ]);

        if (! $this->config->ready()) {
            return response()->json(['message' => 'The AI analyst is not configured for this installation.'], 409);
        }

        $conversation = null;
        if (isset($data['conversation_id'])) {
            $conversation = AiConversation::query()->whereKey($data['conversation_id'])->where('user_id', $user->id)->first();
            abort_if($conversation === null, 404);
        }
        $created = $conversation === null;
        $conversation ??= AiConversation::query()->create(['user_id' => $user->id, 'title' => Str::limit(trim($data['message']), 80)]);

        // A tool loop can take a while; don't let PHP's default limit cut it off.
        @set_time_limit($this->config->timeout() * 3);

        try {
            $answer = $analyst->ask($conversation, $data['message']);
        } catch (AiUnavailable $e) {
            if ($created) {
                $conversation->delete();
            }

            return response()->json(['message' => $e->getMessage(), 'retryable' => $e->retryable], $e->status);
        }

        $audit->record('ai.question', $conversation, [
            'tools' => count($answer->evidence ?? []),
            'input_tokens' => $answer->input_tokens,
            'output_tokens' => $answer->output_tokens,
            'model' => $answer->model,
        ]);

        $question = $conversation->messages()->where('role', 'user')->latest('id')->first();

        return response()->json(['data' => [
            'conversation' => ['id' => $conversation->id, 'title' => $conversation->title, 'url' => route('assistant.show', ['conversation' => $conversation->id])],
            'messages' => array_values(array_filter([$question ? $this->present($question) : null, $this->present($answer)])),
        ]]);
    }

    public function destroy(Request $request, AiConversation $conversation): RedirectResponse
    {
        Gate::authorize(Permission::AiUse->value);
        abort_unless($conversation->user_id === $request->user()?->getAuthIdentifier(), 404);

        $conversation->delete();
        $this->toast('Conversation deleted.');

        return redirect()->route('assistant.index');
    }

    /** @return array<string, mixed> */
    private function present(AiMessage $m): array
    {
        return [
            'id' => $m->id,
            'role' => $m->role,
            'content' => $m->content,
            'evidence' => $m->evidence ?? [],
            'stop_reason' => $m->stop_reason,
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }
}
