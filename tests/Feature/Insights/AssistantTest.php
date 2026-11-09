<?php

use App\Domain\Ai\AiUnavailable;
use App\Domain\Ai\AnalystTools;
use App\Domain\Ai\LlmClient;
use App\Enums\Role;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FakeLlmClient;

/*
 * The model is replaced by a scripted fake: these tests check ORBIT's side of
 * the contract — tool validation, tenant scoping, evidence, transcript replay
 * and failure handling — without network calls.
 */

function assistantFixture(Role $role = Role::Analyst): array
{
    [$org, $ids] = seedFixture();
    $user = User::factory()->create();
    Membership::query()->create(['organization_id' => $org->id, 'user_id' => $user->id, 'role' => $role, 'joined_at' => now()]);

    return [$user, $org, $ids];
}

function fakeModel(array $script): FakeLlmClient
{
    $fake = new FakeLlmClient($script);
    app()->instance(LlmClient::class, $fake);

    return $fake;
}

beforeEach(fn () => config(['orbit.ai.api_key' => 'test-key']));

it('shows a configuration state and refuses questions without an API key', function () {
    config(['orbit.ai.api_key' => null]);
    [$user, $org] = assistantFixture();
    $fake = fakeModel([]);

    $this->actingAs($user)->get(route('assistant.index', ['organization' => $org->slug]))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('assistant/Index')->where('status', 'unconfigured')->where('model', null));

    $this->actingAs($user)->postJson(route('assistant.messages', ['organization' => $org->slug]), ['message' => 'Revenue?'])->assertStatus(409);
    expect($fake->requests)->toBe([])->and(asTenant($org, fn () => AiConversation::query()->count()))->toBe(0);
});

it('answers through tools and records evidence computed from real data', function () {
    [$user, $org] = assistantFixture();
    $fake = fakeModel([
        FakeLlmClient::toolUse('toolu_1', 'get_metrics', ['metrics' => ['revenue', 'orders'], 'from' => '2026-10-01', 'to' => '2026-10-31']),
        FakeLlmClient::answer('October revenue was **Rs 340** from 2 orders.'),
    ]);

    $res = $this->actingAs($user)->postJson(route('assistant.messages', ['organization' => $org->slug]), ['message' => 'What was revenue in October?'])->assertOk();

    // The tool result sent back to the model carries the fixture's real totals.
    $toolTurn = $fake->requests[1]['messages'][2];
    expect($toolTurn['role'])->toBe('user')
        ->and($toolTurn['content'][0]['tool_use_id'])->toBe('toolu_1')
        ->and($toolTurn['content'][0]['is_error'])->toBeFalse();
    $result = json_decode($toolTurn['content'][0]['content'], true);
    expect(collect($result['values'])->pluck('value', 'metric')->all())->toEqual(['revenue' => 340, 'orders' => 2]);

    $answer = $res->json('data.messages.1');
    expect($answer['content'])->toContain('Rs 340')
        ->and($answer['evidence'][0]['tool'])->toBe('get_metrics')
        ->and($answer['evidence'][0]['rows'][0]['value'])->toEqual(340)
        ->and($answer['evidence'][0]['estimate'])->toBeFalse();

    // Tools and the stable system block are sent with a cache breakpoint.
    expect($fake->requests[0]['system'][0]['cache_control'])->toBe(['type' => 'ephemeral'])
        ->and(array_column($fake->requests[0]['tools'], 'name'))->toBe(['get_metrics', 'compare_periods', 'breakdown', 'trend', 'list_anomalies', 'forecast']);
    $this->assertDatabaseHas('audit_logs', ['organization_id' => $org->id, 'action' => 'ai.question']);
});

it('replays earlier exchanges verbatim, including thinking blocks', function () {
    [$user, $org] = assistantFixture();
    $fake = fakeModel([
        FakeLlmClient::toolUse('toolu_1', 'get_metrics', ['metrics' => ['revenue'], 'from' => '2026-10-01', 'to' => '2026-10-31'], 'signature-A'),
        FakeLlmClient::answer('Rs 340.'),
        FakeLlmClient::answer('Karachi made Rs 90 of it.'),
    ]);
    $url = route('assistant.messages', ['organization' => $org->slug]);

    $id = $this->actingAs($user)->postJson($url, ['message' => 'October revenue?'])->json('data.conversation.id');
    $this->actingAs($user)->postJson($url, ['message' => 'And Karachi?', 'conversation_id' => $id])->assertOk();

    $replayed = $fake->requests[2]['messages'];
    expect(array_column($replayed, 'role'))->toBe(['user', 'assistant', 'user', 'assistant', 'user'])
        ->and($replayed[0]['content'])->toBe('October revenue?')
        ->and($replayed[1]['content'][0])->toBe(['type' => 'thinking', 'thinking' => '', 'signature' => 'signature-A'])
        ->and($replayed[2]['content'][0]['tool_use_id'])->toBe('toolu_1')
        ->and($replayed[3]['content'])->toBe([['type' => 'text', 'text' => 'Rs 340.']])
        ->and($replayed[4]['content'])->toBe('And Karachi?');
});

it('rejects invalid tool arguments and other organizations\' ids as tool errors', function () {
    [, $org, $ids] = assistantFixture();
    [$otherOrg] = seedFixture();
    $foreignBranch = DB::table('branches')->where('organization_id', $otherOrg->id)->value('id');

    $results = asTenant($org, fn () => [
        app(AnalystTools::class)->run('get_metrics', ['metrics' => ['password'], 'from' => '2026-10-01', 'to' => '2026-10-31']),
        app(AnalystTools::class)->run('get_metrics', ['metrics' => ['revenue'], 'from' => '2026-10-31', 'to' => '2026-10-01']),
        app(AnalystTools::class)->run('get_metrics', ['metrics' => ['revenue'], 'from' => '2026-02-30', 'to' => '2026-10-01']),
        app(AnalystTools::class)->run('get_metrics', ['metrics' => ['revenue'], 'from' => '2026-10-01', 'to' => '2026-10-31', 'branch_ids' => [$foreignBranch]]),
        app(AnalystTools::class)->run('breakdown', ['metric' => 'operating_expenses', 'dimension' => 'product', 'from' => '2026-10-01', 'to' => '2026-10-31']),
        app(AnalystTools::class)->run('run_sql', ['query' => 'select * from users']),
        app(AnalystTools::class)->run('get_metrics', ['metrics' => ['revenue'], 'from' => '2026-10-01', 'to' => '2026-10-31', 'branch_ids' => [$ids['khi']]]),
    ]);

    expect(array_column($results, 'ok'))->toBe([false, false, false, false, false, false, true])
        ->and($results[0]['result']['error'])->toContain('Unknown metric')
        ->and($results[3]['result']['error'])->toContain('Unknown branch_ids')
        ->and($results[5]['result']['error'])->toContain('Unknown tool')
        ->and($results[6]['result']['values'][0]['value'])->toBe(90.0);
});

it('labels forecasts as estimates in the evidence', function () {
    [, $org] = assistantFixture();

    $run = asTenant($org, fn () => app(AnalystTools::class)->run('forecast', ['metric' => 'revenue', 'horizon' => 3]));

    expect($run['ok'])->toBeTrue()->and($run['result']['estimate'])->toBeTrue()->and($run['evidence']['estimate'])->toBeTrue();
});

it('stops calling tools after the round limit and forces an answer', function () {
    config(['orbit.ai.max_tool_rounds' => 2]);
    [$user, $org] = assistantFixture();
    $loop = FakeLlmClient::toolUse('toolu_x', 'get_metrics', ['metrics' => ['revenue'], 'from' => '2026-10-01', 'to' => '2026-10-31']);
    $fake = fakeModel([$loop, $loop, FakeLlmClient::answer('Here is what I found.')]);

    $this->actingAs($user)->postJson(route('assistant.messages', ['organization' => $org->slug]), ['message' => 'Loop?'])->assertOk();

    expect($fake->requests)->toHaveCount(3)
        ->and($fake->requests[0])->not->toHaveKey('tool_choice')
        ->and($fake->requests[2]['tool_choice'])->toBe(['type' => 'none']);
});

it('keeps refused or cut-off exchanges out of the replayed history', function () {
    [$user, $org] = assistantFixture();
    $fake = fakeModel([FakeLlmClient::answer('', 'refusal'), FakeLlmClient::answer('Fine.')]);
    $url = route('assistant.messages', ['organization' => $org->slug]);

    $first = $this->actingAs($user)->postJson($url, ['message' => 'Something odd'])->assertOk();
    expect($first->json('data.messages.1.content'))->toContain('can’t help');

    $this->actingAs($user)->postJson($url, ['message' => 'Revenue?', 'conversation_id' => $first->json('data.conversation.id')])->assertOk();
    expect(array_column($fake->requests[1]['messages'], 'role'))->toBe(['user'])
        ->and(asTenant($org, fn () => AiMessage::query()->where('role', 'assistant')->whereNull('transcript')->count()))->toBe(1);
});

it('reports provider failures without storing a half conversation', function () {
    [$user, $org] = assistantFixture();
    fakeModel([new AiUnavailable('The AI analyst is busy right now. Please try again in a minute.', 429)]);

    $this->actingAs($user)->postJson(route('assistant.messages', ['organization' => $org->slug]), ['message' => 'Revenue?'])
        ->assertStatus(429)->assertJsonPath('message', 'The AI analyst is busy right now. Please try again in a minute.');

    expect(asTenant($org, fn () => AiConversation::query()->count()))->toBe(0);
});

it('keeps conversations private to their author and organization', function () {
    [$user, $org] = assistantFixture();
    [$colleague] = memberOf(Role::Admin, $org);
    [$outsider, $otherOrg] = memberOf();
    fakeModel([FakeLlmClient::answer('Hello.')]);

    $id = $this->actingAs($user)->postJson(route('assistant.messages', ['organization' => $org->slug]), ['message' => 'Hi'])->json('data.conversation.id');

    $this->actingAs($colleague)->get(route('assistant.show', ['organization' => $org->slug, 'conversation' => $id]))->assertNotFound();
    $this->actingAs($colleague)->postJson(route('assistant.messages', ['organization' => $org->slug]), ['message' => 'Hijack', 'conversation_id' => $id])->assertNotFound();
    $this->actingAs($outsider)->get(route('assistant.show', ['organization' => $otherOrg->slug, 'conversation' => $id]))->assertNotFound();
    $this->actingAs($user)->get(route('assistant.show', ['organization' => $org->slug, 'conversation' => $id]))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->has('conversation.messages', 2));
});

it('limits questions per user per hour', function () {
    config(['orbit.ai.hourly_limit' => 1]);
    [$user, $org] = assistantFixture();
    fakeModel([FakeLlmClient::answer('One.')]);
    $url = route('assistant.messages', ['organization' => $org->slug]);

    $this->actingAs($user)->postJson($url, ['message' => 'First'])->assertOk();
    $this->actingAs($user)->postJson($url, ['message' => 'Second'])->assertStatus(429)->assertJsonPath('message', fn ($m) => str_contains($m, 'hourly limit'));
});
