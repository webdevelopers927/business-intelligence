<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'tenant' => ResolveTenant::class,
        ]);

        // Tenant resolution must happen before implicit route-model binding,
        // so bound models are fetched through the tenant scope (→ 404 across
        // tenants) instead of being loaded unscoped.
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveTenant::class);

        $middleware->redirectGuestsTo('/login');

        // Signed-in users hitting guest-only pages (/login, /register, …) go to
        // /app, which resolves their organization. Without this, Laravel falls
        // back to route('dashboard'), which needs an {organization} parameter.
        $middleware->redirectUsersTo('/app');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
