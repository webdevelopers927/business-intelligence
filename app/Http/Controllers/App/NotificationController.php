<?php

namespace App\Http\Controllers\App;

use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * In-app inbox for the current organization (plus user-level notifications
 * such as invitations, which belong to no organization).
 */
final class NotificationController extends Controller
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function index(Request $request): JsonResponse
    {
        $notifications = $this->inbox($request)
            ->latest()
            ->limit(30)
            ->get()
            ->map(fn (DatabaseNotification $n) => [
                'id' => $n->id,
                'kind' => $n->data['kind'] ?? 'general',
                'title' => $n->data['title'] ?? '',
                'body' => $n->data['body'] ?? '',
                'url' => $n->data['url'] ?? null,
                'read_at' => $n->read_at?->toIso8601String(),
                'created_at' => $n->created_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $notifications]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $updated = $this->inbox($request)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['data' => ['updated' => $updated]]);
    }

    /** @return MorphMany<DatabaseNotification, User> */
    private function inbox(Request $request): MorphMany
    {
        /** @var User $user */
        $user = $request->user();

        return $user->notifications()->where(fn (Builder $q) => $q
            ->where('organization_id', $this->tenant->id())
            ->orWhereNull('organization_id'));
    }
}
