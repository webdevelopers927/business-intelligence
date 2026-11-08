<?php

use App\Domain\DataSources\ConnectionRefused;
use App\Domain\DataSources\HostGuard;
use App\Enums\DataSourceStatus;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\DataSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function mysqlPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'name' => 'Finance warehouse',
        'type' => 'mysql',
        'description' => 'Nightly finance replica',
        'config' => ['host' => 'db.nexora.example', 'port' => 3306, 'database' => 'finance', 'username' => 'orbit_ro', 'table' => 'gl_expenses'],
        'secret' => ['password' => 'S3cret-Pa55'],
    ], $overrides);
}

it('creates a file source that is immediately active', function () {
    [$admin, $org] = memberOf(Role::Admin);

    $this->actingAs($admin)
        ->post(route('sources.store', ['organization' => $org->slug]), ['name' => 'POS exports', 'type' => 'csv'])
        ->assertRedirect();

    $source = asTenant($org, fn () => DataSource::query()->sole());
    expect($source->status)->toBe(DataSourceStatus::Active)
        ->and(AuditLog::query()->where('action', 'data_source.created')->exists())->toBeTrue();
});

it('encrypts credentials and never sends them to the browser', function () {
    [$admin, $org] = memberOf(Role::Admin);

    $this->actingAs($admin)->post(route('sources.store', ['organization' => $org->slug]), mysqlPayload())->assertRedirect();
    $source = asTenant($org, fn () => DataSource::query()->sole());

    expect($source->status)->toBe(DataSourceStatus::Pending);

    $raw = DB::table('data_source_credentials')->value('secret');
    expect($raw)->not->toContain('S3cret-Pa55')
        ->and(DB::table('data_sources')->value('config'))->not->toContain('S3cret-Pa55');

    foreach ([
        route('sources.index', ['organization' => $org->slug]),
        route('sources.show', ['organization' => $org->slug, 'source' => $source->id]),
        route('sources.edit', ['organization' => $org->slug, 'source' => $source->id]),
    ] as $url) {
        $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();
        expect($html)->not->toContain('S3cret-Pa55');
    }

    $this->actingAs($admin)
        ->get(route('sources.show', ['organization' => $org->slug, 'source' => $source->id]))
        ->assertInertia(fn ($page) => $page->where('source.has_credentials', true)->missing('source.secret'));

    expect(json_encode(AuditLog::query()->pluck('metadata')))->not->toContain('S3cret-Pa55');
});

it('keeps the stored password when an edit leaves it blank, and re-requires a test', function () {
    [$admin, $org] = memberOf(Role::Admin);
    $this->actingAs($admin)->post(route('sources.store', ['organization' => $org->slug]), mysqlPayload());
    $source = asTenant($org, fn () => DataSource::query()->sole());
    $source->forceFill(['status' => DataSourceStatus::Active])->save();

    $this->actingAs($admin)
        ->patch(route('sources.update', ['organization' => $org->slug, 'source' => $source->id]), mysqlPayload([
            'config' => ['host' => 'db2.nexora.example'],
            'secret' => ['password' => ''],
        ]))
        ->assertSessionHasNoErrors();

    $source = asTenant($org, fn () => $source->fresh('credential'));
    expect($source->credential->secret['password'])->toBe('S3cret-Pa55')
        ->and($source->config['host'])->toBe('db2.nexora.example')
        ->and($source->status)->toBe(DataSourceStatus::Pending);
});

it('validates connection parameters', function () {
    [$admin, $org] = memberOf(Role::Admin);

    $this->actingAs($admin)
        ->post(route('sources.store', ['organization' => $org->slug]), mysqlPayload([
            'config' => ['host' => 'db; DROP TABLE users', 'port' => 70000, 'database' => 'fin`ance', 'table' => 'x; --'],
            'secret' => ['password' => ''],
        ]))
        ->assertSessionHasErrors(['config.host', 'config.port', 'config.database', 'config.table', 'secret.password']);
});

it('blocks connections to private and metadata addresses', function (string $host) {
    $guard = new HostGuard([]);

    expect(fn () => $guard->resolve($host))->toThrow(ConnectionRefused::class);
})->with(['127.0.0.1', '10.0.0.5', '192.168.1.10', '169.254.169.254', 'localhost', '[::1]']);

it('allows an explicitly allow-listed internal host', function () {
    expect((new HostGuard(['127.0.0.1']))->resolve('127.0.0.1'))->toBe('127.0.0.1');
});

it('records a failed connection test without pretending to connect', function () {
    [$admin, $org] = memberOf(Role::Admin);
    $this->actingAs($admin)->post(route('sources.store', ['organization' => $org->slug]), mysqlPayload(['config' => ['host' => '10.1.2.3']]));
    $source = asTenant($org, fn () => DataSource::query()->sole());

    $this->actingAs($admin)
        ->postJson(route('sources.test', ['organization' => $org->slug, 'source' => $source->id]))
        ->assertOk()
        ->assertJsonPath('data.ok', false)
        ->assertJsonPath('data.source.status', 'error');

    expect(asTenant($org, fn () => $source->fresh()->last_error))->toContain('private or internal');
});

it('tests a REST source against its records path', function () {
    $this->app->bind(HostGuard::class, fn () => new class extends HostGuard
    {
        public function resolve(string $host): string
        {
            return '93.184.216.34';
        }
    });
    Http::fake(['api.nexora.example/*' => Http::response(['data' => [['id' => 1], ['id' => 2]]])]);

    [$admin, $org] = memberOf(Role::Admin);
    $this->actingAs($admin)->post(route('sources.store', ['organization' => $org->slug]), [
        'name' => 'Shopify orders',
        'type' => 'rest',
        'config' => ['url' => 'https://api.nexora.example/orders', 'records_path' => 'data'],
        'secret' => ['token' => 'tok_live_123'],
    ])->assertSessionHasNoErrors();
    $source = asTenant($org, fn () => DataSource::query()->sole());

    $this->actingAs($admin)
        ->postJson(route('sources.test', ['organization' => $org->slug, 'source' => $source->id]))
        ->assertJsonPath('data.ok', true)
        ->assertJsonPath('data.source.status', 'active')
        ->assertJsonMissingPath('data.source.secret');

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer tok_live_123'));
});

it('disables, re-enables and deletes a source', function () {
    [$admin, $org] = memberOf(Role::Admin);
    $source = asTenant($org, fn () => DataSource::query()->create(['name' => 'POS', 'type' => 'csv', 'status' => 'active']));
    $params = ['organization' => $org->slug, 'source' => $source->id];

    $this->actingAs($admin)->post(route('sources.toggle', $params));
    expect(asTenant($org, fn () => $source->fresh()->status))->toBe(DataSourceStatus::Disabled);

    $this->actingAs($admin)->post(route('sources.toggle', $params));
    expect(asTenant($org, fn () => $source->fresh()->status))->toBe(DataSourceStatus::Active);

    $this->actingAs($admin)->delete(route('sources.destroy', $params))->assertRedirect(route('sources.index', ['organization' => $org->slug]));
    expect(asTenant($org, fn () => DataSource::query()->count()))->toBe(0)
        ->and(asTenant($org, fn () => DataSource::withTrashed()->count()))->toBe(1);
});

it('lets analysts view sources but not manage them; viewers see nothing', function () {
    [$analyst, $org] = memberOf(Role::Analyst);
    [$viewer] = memberOf(Role::Viewer, $org);
    $source = asTenant($org, fn () => DataSource::query()->create(['name' => 'POS', 'type' => 'csv', 'status' => 'active']));
    $params = ['organization' => $org->slug, 'source' => $source->id];

    $this->actingAs($analyst)->get(route('sources.index', ['organization' => $org->slug]))->assertOk();
    $this->actingAs($analyst)->get(route('sources.create', ['organization' => $org->slug]))->assertForbidden();
    $this->actingAs($analyst)->post(route('sources.toggle', $params))->assertForbidden();
    $this->actingAs($analyst)->delete(route('sources.destroy', $params))->assertForbidden();

    $this->actingAs($viewer)->get(route('sources.index', ['organization' => $org->slug]))->assertForbidden();
});

it('returns 404 for another organization\'s source', function () {
    [$ownerA, $orgA] = memberOf(Role::Owner);
    $source = asTenant($orgA, fn () => DataSource::query()->create(['name' => 'POS', 'type' => 'csv', 'status' => 'active']));
    [$intruder, $orgB] = memberOf(Role::Owner);
    $params = ['organization' => $orgB->slug, 'source' => $source->id];

    $this->actingAs($intruder)->get(route('sources.show', $params))->assertNotFound();
    $this->actingAs($intruder)->patch(route('sources.update', $params), ['name' => 'pwned'])->assertNotFound();
    $this->actingAs($intruder)->postJson(route('sources.test', $params))->assertNotFound();
    $this->actingAs($intruder)->delete(route('sources.destroy', $params))->assertNotFound();

    expect(asTenant($orgA, fn () => $source->fresh()->name))->toBe('POS');
});
