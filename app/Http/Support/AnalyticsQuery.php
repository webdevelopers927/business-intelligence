<?php

namespace App\Http\Support;

use App\Domain\Analytics\Query\DateRange;
use App\Domain\Analytics\Query\Filters;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Reads the shared analytics URL state (?range=&from=&to=&branches=&categories=)
 * into a DateRange and Filters. Invalid values fall back to defaults rather
 * than erroring — a stale bookmark should still open the dashboard.
 */
final class AnalyticsQuery
{
    public function __construct(
        public readonly DateRange $range,
        public readonly Filters $filters,
        public readonly CarbonImmutable $today,
    ) {}

    public static function fromRequest(Request $request, TenantContext $tenant): self
    {
        $today = CarbonImmutable::now($tenant->organization()->timezone)->startOfDay();
        $preset = (string) $request->query('range', DateRange::DEFAULT_PRESET);

        try {
            $range = $preset === 'custom'
                ? DateRange::custom(
                    CarbonImmutable::createFromFormat('!Y-m-d', (string) $request->query('from')) ?: throw new InvalidArgumentException,
                    CarbonImmutable::createFromFormat('!Y-m-d', (string) $request->query('to')) ?: throw new InvalidArgumentException,
                )
                : DateRange::fromPreset($preset, $today);
        } catch (\Throwable) {
            $range = DateRange::fromPreset(DateRange::DEFAULT_PRESET, $today);
        }

        return new self($range, new Filters(
            branchIds: self::ids($request, 'branches', 'branches', $tenant),
            categoryIds: self::ids($request, 'categories', 'product_categories', $tenant),
        ), $today);
    }

    /**
     * Comma-separated ids, kept only if they belong to the organization.
     *
     * @return list<int>
     */
    private static function ids(Request $request, string $param, string $table, TenantContext $tenant): array
    {
        $raw = array_filter(array_map('intval', explode(',', (string) $request->query($param, ''))));
        if ($raw === []) {
            return [];
        }

        return DB::table($table)
            ->where('organization_id', $tenant->id())
            ->whereIn('id', $raw)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** @return array{range: string, from: string, to: string, branches: list<int>, categories: list<int>} */
    public function state(): array
    {
        return [
            'range' => $this->range->preset,
            'from' => $this->range->from->toDateString(),
            'to' => $this->range->to->toDateString(),
            'branches' => $this->filters->branchIds,
            'categories' => $this->filters->categoryIds,
        ];
    }

    /** @return array{presets: list<array{value: string, label: string}>, branches: list<array{value: string, label: string}>, categories: list<array{value: string, label: string}>} */
    public static function options(TenantContext $tenant): array
    {
        $list = fn (string $table) => DB::table($table)->where('organization_id', $tenant->id())->orderBy('name')->get(['id', 'name'])
            ->map(fn ($r) => ['value' => (string) $r->id, 'label' => $r->name])->all();

        return [
            'presets' => array_map(fn ($k, $v) => ['value' => $k, 'label' => $v], array_keys(DateRange::PRESETS), DateRange::PRESETS),
            'branches' => $list('branches'),
            'categories' => $list('product_categories'),
        ];
    }
}
