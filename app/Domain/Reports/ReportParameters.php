<?php

namespace App\Domain\Reports;

use App\Domain\Analytics\Query\DateRange;
use App\Domain\Analytics\Query\Filters;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A validated report request. Ranges are stored as presets where possible
 * ("last_month"), so a saved or scheduled report always covers the period
 * relative to when it runs.
 */
final readonly class ReportParameters
{
    /**
     * @param  list<string>  $columns
     * @param  array{preset: string, from: string|null, to: string|null}  $rangeState
     */
    public function __construct(
        public string $type,
        public DateRange $range,
        public Filters $filters,
        public array $columns,
        public string $sort,
        public string $direction,
        public array $rangeState,
    ) {}

    /**
     * @param  array<string, mixed>  $input  {range: {preset, from?, to?}, filters: {branches?, categories?}, columns?, sort?: {column, direction}}
     */
    public static function from(ReportType $type, array $input, CarbonImmutable $today, int $organizationId): self
    {
        $preset = (string) ($input['range']['preset'] ?? $type->defaultRange());
        $from = $input['range']['from'] ?? null;
        $to = $input['range']['to'] ?? null;

        try {
            $range = $preset === 'custom'
                ? DateRange::custom(CarbonImmutable::parse((string) $from), CarbonImmutable::parse((string) $to))
                : DateRange::fromPreset($preset, $today);
        } catch (\Throwable) {
            throw new InvalidArgumentException('Choose a valid date range.');
        }

        $known = array_map(fn (ReportColumn $c) => $c->key, $type->columns());
        $columns = array_values(array_intersect(array_map('strval', (array) ($input['columns'] ?? [])), $known));
        if ($columns === []) {
            $columns = $type->defaultColumns();
        }

        $sort = (string) ($input['sort']['column'] ?? $type->defaultSort()[0]);
        if (! in_array($sort, $known, true)) {
            $sort = $type->defaultSort()[0];
        }
        $direction = ($input['sort']['direction'] ?? $type->defaultSort()[1]) === 'asc' ? 'asc' : 'desc';

        $ids = function (string $key, string $table) use ($input, $organizationId): array {
            $raw = array_values(array_filter(array_map('intval', (array) ($input['filters'][$key] ?? []))));

            return $raw === [] ? [] : DB::table($table)->where('organization_id', $organizationId)->whereIn('id', $raw)
                ->orderBy('id')->pluck('id')->map(fn ($i) => (int) $i)->all();
        };

        return new self(
            $type->key(),
            $range,
            new Filters(
                branchIds: in_array('branches', $type->filters(), true) ? $ids('branches', 'branches') : [],
                categoryIds: in_array('categories', $type->filters(), true) ? $ids('categories', 'product_categories') : [],
            ),
            $columns,
            $sort,
            $direction,
            ['preset' => $range->preset, 'from' => $preset === 'custom' ? $range->from->toDateString() : null, 'to' => $preset === 'custom' ? $range->to->toDateString() : null],
        );
    }

    /** @return array<string, mixed> Storable form (saved reports, runs). */
    public function toArray(): array
    {
        return [
            'range' => $this->rangeState,
            'filters' => ['branches' => $this->filters->branchIds, 'categories' => $this->filters->categoryIds],
            'columns' => $this->columns,
            'sort' => ['column' => $this->sort, 'direction' => $this->direction],
        ];
    }
}
