<?php

namespace App\Http\Support;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Consistent pagination payload for tables (docs/ROUTES.md response shape):
 * { data: [...], meta: { current_page, last_page, per_page, total, from, to } }.
 */
final class Paginated
{
    /**
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @param  class-string<JsonResource>  $resource
     * @return array{data: array<int, mixed>, meta: array<string, int|null>}
     */
    public static function of(LengthAwarePaginator $paginator, string $resource, Request $request): array
    {
        return [
            'data' => $resource::collection($paginator->items())->resolve($request),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }

    public static function perPage(Request $request, int $default = 25): int
    {
        $value = $request->integer('per_page', $default);

        return in_array($value, [25, 50, 100], true) ? $value : $default;
    }
}
