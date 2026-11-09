<?php

namespace App\Domain\Insights;

use App\Domain\Analytics\Metrics\MetricDefinition;
use Illuminate\Support\Facades\DB;

/**
 * When a metric's data begins. Periods before that are "no data", not zero —
 * otherwise a metric whose source was imported later (e.g. sales starting two
 * months after expenses) gets fake zero months that distort baselines and trends.
 */
final class Coverage
{
    private const FACT_TABLES = ['sales' => 'sales_transactions', 'expenses' => 'expenses'];

    /** Latest of the metric's facts' first dates (all facts must exist), or null. */
    public static function start(int $organizationId, MetricDefinition $metric): ?string
    {
        $starts = [];
        foreach ($metric->facts as $fact) {
            $first = DB::table(self::FACT_TABLES[$fact])->where('organization_id', $organizationId)->min('business_date');
            if ($first === null) {
                return null;
            }
            $starts[] = substr((string) $first, 0, 10);
        }

        return $starts === [] ? null : max($starts);
    }
}
