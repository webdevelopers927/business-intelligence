<?php

use App\Http\Controllers\App\DashboardController;
use App\Http\Controllers\App\DashboardsController;
use App\Http\Controllers\App\DashboardWidgetController;
use App\Http\Controllers\App\DataSourceController;
use App\Http\Controllers\App\ExploreController;
use App\Http\Controllers\App\ImportController;
use App\Http\Controllers\App\MetricController;
use App\Http\Controllers\App\NotificationController;
use App\Http\Controllers\AppEntryController;
use App\Http\Controllers\InvitationAcceptController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\OrganizationSwitchController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Settings\InvitationController;
use App\Http\Controllers\Settings\MemberController;
use App\Http\Controllers\Settings\OrganizationSettingsController;
use Illuminate\Support\Facades\Route;

/*
| Public marketing pages ship in Phase 8; until then the root sends visitors
| to the product entry point.
*/
Route::get('/', fn () => redirect()->route('app'))->name('home');

// Invitations can be opened while signed out (the token is remembered).
Route::get('/invitations/{token}', [InvitationAcceptController::class, 'show'])->name('invitations.show');

Route::middleware('auth')->group(function () {
    Route::get('/app', AppEntryController::class)->name('app');

    Route::get('/onboarding/organization', [OnboardingController::class, 'create'])->name('onboarding.create');
    Route::post('/onboarding/organization', [OnboardingController::class, 'store'])->name('onboarding.store');

    Route::post('/invitations/{token}/accept', [InvitationAcceptController::class, 'store'])->name('invitations.accept');
    Route::post('/organizations/{slug}/switch', OrganizationSwitchController::class)->name('organizations.switch');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile/theme', [ProfileController::class, 'updateTheme'])->name('profile.theme');

    /*
    | Tenant application. ResolveTenant verifies membership (404 otherwise),
    | sets TenantContext, and runs before route-model binding.
    */
    Route::prefix('/app/{organization}')->middleware('tenant')->group(function () {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');
        Route::get('/explore', ExploreController::class)->name('explore');

        // Custom dashboards (widgets resolve through their dashboard)
        Route::get('/dashboards', [DashboardsController::class, 'index'])->name('dashboards.index');
        Route::post('/dashboards', [DashboardsController::class, 'store'])->name('dashboards.store');
        Route::get('/dashboards/{dashboard}', [DashboardsController::class, 'show'])->name('dashboards.show');
        Route::patch('/dashboards/{dashboard}', [DashboardsController::class, 'update'])->name('dashboards.update');
        Route::delete('/dashboards/{dashboard}', [DashboardsController::class, 'destroy'])->name('dashboards.destroy');
        Route::post('/dashboards/{dashboard}/duplicate', [DashboardsController::class, 'duplicate'])->name('dashboards.duplicate');
        Route::put('/dashboards/{dashboard}/layout', [DashboardsController::class, 'layout'])->name('dashboards.layout');
        Route::put('/dashboards/{dashboard}/shares', [DashboardsController::class, 'share'])->name('dashboards.share');
        Route::scopeBindings()->group(function () {
            Route::post('/dashboards/{dashboard}/widgets', [DashboardWidgetController::class, 'store'])->name('dashboards.widgets.store');
            Route::patch('/dashboards/{dashboard}/widgets/{widget}', [DashboardWidgetController::class, 'update'])->name('dashboards.widgets.update');
            Route::delete('/dashboards/{dashboard}/widgets/{widget}', [DashboardWidgetController::class, 'destroy'])->name('dashboards.widgets.destroy');
            Route::get('/dashboards/{dashboard}/widgets/{widget}/data', [DashboardWidgetController::class, 'data'])->name('dashboards.widgets.data');
        });

        // Custom metrics
        Route::get('/metrics', [MetricController::class, 'index'])->name('metrics.index');
        Route::get('/metrics/create', [MetricController::class, 'create'])->name('metrics.create');
        Route::post('/metrics', [MetricController::class, 'store'])->name('metrics.store');
        Route::post('/metrics/preview', [MetricController::class, 'preview'])->name('metrics.preview');
        Route::get('/metrics/{metric}/edit', [MetricController::class, 'edit'])->name('metrics.edit');
        Route::patch('/metrics/{metric}', [MetricController::class, 'update'])->name('metrics.update');
        Route::delete('/metrics/{metric}', [MetricController::class, 'destroy'])->name('metrics.destroy');

        // Data
        Route::get('/sources', [DataSourceController::class, 'index'])->name('sources.index');
        Route::get('/sources/create', [DataSourceController::class, 'create'])->name('sources.create');
        Route::post('/sources', [DataSourceController::class, 'store'])->name('sources.store');
        Route::get('/sources/{source}', [DataSourceController::class, 'show'])->name('sources.show');
        Route::get('/sources/{source}/edit', [DataSourceController::class, 'edit'])->name('sources.edit');
        Route::patch('/sources/{source}', [DataSourceController::class, 'update'])->name('sources.update');
        Route::post('/sources/{source}/test', [DataSourceController::class, 'test'])->name('sources.test');
        Route::post('/sources/{source}/toggle', [DataSourceController::class, 'toggle'])->name('sources.toggle');
        Route::delete('/sources/{source}', [DataSourceController::class, 'destroy'])->name('sources.destroy');

        Route::get('/imports', [ImportController::class, 'index'])->name('imports.index');
        Route::get('/imports/create', [ImportController::class, 'create'])->name('imports.create');
        Route::post('/imports', [ImportController::class, 'store'])->name('imports.store');
        Route::get('/imports/{import}', [ImportController::class, 'show'])->name('imports.show');
        Route::get('/imports/{import}/mapping', [ImportController::class, 'mapping'])->name('imports.mapping');
        Route::post('/imports/{import}/confirm', [ImportController::class, 'confirm'])->name('imports.confirm');
        Route::get('/imports/{import}/failed-rows.csv', [ImportController::class, 'errors'])->name('imports.errors');

        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('/notifications/read', [NotificationController::class, 'markAllRead'])->name('notifications.read');

        Route::prefix('/settings')->name('settings.')->group(function () {
            Route::get('/organization', [OrganizationSettingsController::class, 'edit'])->name('organization.edit');
            Route::patch('/organization', [OrganizationSettingsController::class, 'update'])->name('organization.update');

            Route::get('/members', [MemberController::class, 'index'])->name('members.index');
            Route::patch('/members/{membership}', [MemberController::class, 'update'])->name('members.update')->whereNumber('membership');
            Route::delete('/members/{membership}', [MemberController::class, 'destroy'])->name('members.destroy')->whereNumber('membership');

            Route::post('/invitations', [InvitationController::class, 'store'])->name('invitations.store');
            Route::delete('/invitations/{invitation}', [InvitationController::class, 'destroy'])->name('invitations.destroy');
        });
    });
});
