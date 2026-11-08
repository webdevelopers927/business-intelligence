<?php

namespace App\Domain\Analytics;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Per-organization cache versioning (docs/ARCHITECTURE.md §7.3).
 * Analytics cache keys embed the organization's version; bumping it after an
 * import makes every older key unreachable. Works on drivers without tags.
 */
final class AnalyticsCache
{
    public const TTL_SECONDS = 3600;

    public const TTL_TODAY_SECONDS = 300;

    public function version(int $organizationId): int
    {
        return (int) Cache::get($this->versionKey($organizationId), 1);
    }

    public function bump(int $organizationId): void
    {
        $key = $this->versionKey($organizationId);
        Cache::add($key, 1);
        Cache::increment($key);
    }

    public function key(int $organizationId, string $fingerprint): string
    {
        return "analytics:{$organizationId}:v{$this->version($organizationId)}:".sha1($fingerprint);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function remember(int $organizationId, string $fingerprint, bool $includesToday, Closure $callback): mixed
    {
        return Cache::remember(
            $this->key($organizationId, $fingerprint),
            $includesToday ? self::TTL_TODAY_SECONDS : self::TTL_SECONDS,
            $callback,
        );
    }

    private function versionKey(int $organizationId): string
    {
        return "analytics:{$organizationId}:version";
    }
}
