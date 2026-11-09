<?php

namespace App\Domain\Reports;

use App\Domain\Analytics\AnalyticsEngine;
use App\Domain\Tenancy\TenantContext;
use Generator;

/**
 * A report definition. Aggregated reports compute their (small) row set in
 * memory via rows(); transaction-level reports override count/page/cursor
 * to stay in the database and stream.
 */
abstract class ReportType
{
    public function __construct(
        protected readonly AnalyticsEngine $engine,
        protected readonly TenantContext $tenant,
    ) {}

    abstract public function key(): string;

    abstract public function label(): string;

    abstract public function description(): string;

    /** @return list<ReportColumn> */
    abstract public function columns(): array;

    /**
     * All rows, keyed by column key.
     *
     * @return list<array<string, mixed>>
     */
    abstract protected function rows(ReportParameters $p): array;

    /** @return list<string> */
    public function defaultColumns(): array
    {
        return array_map(fn (ReportColumn $c) => $c->key, $this->columns());
    }

    /** @return array{0: string, 1: 'asc'|'desc'} */
    public function defaultSort(): array
    {
        return [$this->columns()[1]->key ?? $this->columns()[0]->key, 'desc'];
    }

    public function defaultRange(): string
    {
        return 'last_month';
    }

    /** @return list<'branches'|'categories'> */
    public function filters(): array
    {
        return ['branches', 'categories'];
    }

    /** Short note shown under the report (e.g. which filters apply). */
    public function note(): ?string
    {
        return null;
    }

    public function count(ReportParameters $p): int
    {
        return count($this->rows($p));
    }

    /** @return list<array<string, mixed>> */
    public function page(ReportParameters $p, int $page, int $perPage): array
    {
        return array_slice($this->sorted($p), ($page - 1) * $perPage, $perPage);
    }

    /** @return Generator<int, array<string, mixed>> */
    public function cursor(ReportParameters $p): Generator
    {
        yield from $this->sorted($p);
    }

    public function column(string $key): ?ReportColumn
    {
        foreach ($this->columns() as $column) {
            if ($column->key === $key) {
                return $column;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private function sorted(ReportParameters $p): array
    {
        $rows = $this->rows($p);
        $key = $p->sort;
        $dir = $p->direction === 'asc' ? 1 : -1;

        usort($rows, function (array $a, array $b) use ($key, $dir) {
            $x = $a[$key] ?? null;
            $y = $b[$key] ?? null;
            if ($x === $y) {
                return 0;
            }
            if ($x === null) {
                return 1; // nulls last in both directions
            }
            if ($y === null) {
                return -1;
            }

            return (is_string($x) && is_string($y) ? strnatcasecmp($x, $y) : $x <=> $y) * $dir;
        });

        return $rows;
    }
}
