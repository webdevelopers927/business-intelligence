<?php

namespace App\Providers;

use App\Domain\Ai\AnthropicLlmClient;
use App\Domain\Ai\LlmClient;
use App\Domain\Analytics\Metrics\MetricRegistry;
use App\Domain\Audit\AuditLogger;
use App\Domain\DataSources\HostGuard;
use App\Domain\Tenancy\TenantContext;
use App\Enums\Permission;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped: a fresh context per request and per queued job.
        $this->app->scoped(TenantContext::class);
        // One registry per request/job so custom-metric changes are seen by
        // every consumer (engine, services) at once.
        $this->app->scoped(MetricRegistry::class);
        $this->app->bind(HostGuard::class, fn () => HostGuard::fromConfig());
        $this->app->bind(LlmClient::class, AnthropicLlmClient::class);
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        $this->registerPermissionGates();
        $this->registerAuthAuditing();

        // AI questions cost money: cap them per user per hour.
        RateLimiter::for('ai', fn (Request $request) => Limit::perHour((int) config('orbit.ai.hourly_limit', 30))
            ->by('ai:'.($request->user()?->getAuthIdentifier() ?? $request->ip()))
            ->response(fn () => response()->json(['message' => 'You have reached the hourly limit for AI questions. Please try again later.'], 429)));
    }

    /**
     * Each Permission becomes a Gate answered by the acting member's role in
     * the current organization. Outside a tenant context every gate denies.
     */
    private function registerPermissionGates(): void
    {
        foreach (Permission::cases() as $permission) {
            Gate::define($permission->value, function (User $user) use ($permission): bool {
                $role = app(TenantContext::class)->role();

                return $role !== null && $role->can($permission);
            });
        }
    }

    private function registerAuthAuditing(): void
    {
        Event::listen(Login::class, function (Login $event): void {
            if ($event->user instanceof User) {
                app(AuditLogger::class)->record(
                    'auth.login',
                    $event->user,
                    organizationId: $event->user->landingOrganization()?->id,
                    userId: $event->user->id,
                );
            }
        });

        Event::listen(Failed::class, function (Failed $event): void {
            app(AuditLogger::class)->record('auth.login_failed', metadata: [
                'email' => $event->credentials['email'] ?? null,
            ]);
        });

        Event::listen(PasswordReset::class, function (PasswordReset $event): void {
            if ($event->user instanceof User) {
                app(AuditLogger::class)->record('auth.password_reset', $event->user, userId: $event->user->id);
            }
        });
    }
}
