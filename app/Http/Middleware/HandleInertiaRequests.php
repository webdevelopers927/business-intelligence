<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use App\Enums\Permission;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Props shared with every page. Shapes are mirrored in
     * resources/js/types/inertia.d.ts (SharedProps).
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $tenant = app(TenantContext::class);
        /** @var User|null $user */
        $user = $request->user();

        return [
            ...parent::share($request),
            'app' => ['name' => config('app.name')],
            'auth' => [
                'user' => $user === null ? null : [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'theme' => $user->theme->value,
                ],
                'organizations' => fn () => $user?->organizations()
                    ->orderBy('name')
                    ->get(['organizations.id', 'organizations.name', 'organizations.slug'])
                    ->map(fn ($org) => [
                        'id' => $org->id,
                        'name' => $org->name,
                        'slug' => $org->slug,
                        'role' => $org->membership->role->value,
                    ])->all() ?? [],
            ],
            'tenant' => fn () => $tenant->has() ? [
                'id' => $tenant->id(),
                'name' => $tenant->organization()->name,
                'slug' => $tenant->organization()->slug,
                'currency' => $tenant->organization()->currency,
                'timezone' => $tenant->organization()->timezone,
                'role' => $tenant->role()?->value,
                'role_label' => $tenant->role()?->label(),
                'permissions' => array_map(
                    fn (Permission $p) => $p->value,
                    $tenant->role()?->permissions() ?? [],
                ),
                'unread_notifications' => $user?->unreadNotifications()
                    ->where(fn ($q) => $q->where('organization_id', $tenant->id())->orWhereNull('organization_id'))
                    ->count() ?? 0,
            ] : null,
            'flash' => fn () => [
                'toast' => $request->session()->get('toast'),
            ],
            'ziggy' => fn () => [
                ...(new Ziggy)->toArray(),
                'location' => $request->url(),
            ],
        ];
    }
}
