<?php

namespace App\Domain\Dashboards;

use App\Domain\Analytics\Metrics\MetricRegistry;
use App\Domain\Analytics\Query\DateRange;
use App\Domain\Tenancy\TenantContext;
use App\Models\Metric;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Validates and normalizes widget configuration (docs/DATABASE_DESIGN.md §6).
 *
 * Each widget type allows certain dimensions, and the chosen metric must
 * support the chosen dimension (net profit can't be split by product
 * category, because expenses aren't attributed to categories).
 */
final class WidgetConfig
{
    /** type => [allowed dimensions (null = no dimension), default w, h, min w, min h] */
    public const TYPES = [
        'kpi' => [[null], 3, 2, 2, 2],
        'line' => [['date'], 6, 4, 3, 3],
        'area' => [['date'], 6, 4, 3, 3],
        'bar' => [['date', 'branch', 'category', 'product', 'channel', 'department', 'expense_category'], 6, 4, 3, 3],
        'pie' => [['branch', 'category', 'channel', 'department', 'expense_category'], 4, 4, 3, 3],
        'donut' => [['branch', 'category', 'channel', 'department', 'expense_category'], 4, 4, 3, 3],
        'table' => [['date', 'branch', 'category', 'product', 'channel', 'department', 'expense_category'], 6, 5, 3, 3],
        'ranked_list' => [['branch', 'category', 'product', 'channel', 'department', 'expense_category'], 4, 5, 3, 3],
    ];

    public const DIMENSIONS = [
        'date' => 'Time',
        'branch' => 'Branch',
        'category' => 'Product category',
        'product' => 'Product',
        'channel' => 'Sales channel',
        'department' => 'Department',
        'expense_category' => 'Expense category',
    ];

    public function __construct(
        private readonly MetricRegistry $metrics,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @param  array<string, mixed>  $input  Raw request input: type, title, metric, dimension, range_mode, range_preset, comparison, limit, branches, categories
     * @return array{type: string, title: string, config: array<string, mixed>}
     *
     * @throws ValidationException
     */
    public function normalize(array $input, User $user): array
    {
        $errors = [];
        $type = (string) ($input['type'] ?? '');

        if (! isset(self::TYPES[$type])) {
            throw ValidationException::withMessages(['type' => 'Choose a widget type.']);
        }
        [$dimensions] = self::TYPES[$type];

        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 80) {
            $errors['title'] = 'Give the widget a title of up to 80 characters.';
        }

        $metric = (string) ($input['metric'] ?? '');
        if (! $this->isUsable($metric, $user)) {
            $errors['metric'] = 'Choose a metric.';
        }

        $dimension = $dimensions === [null] ? null : ($input['dimension'] ?? null);
        if ($dimensions !== [null] && ! in_array($dimension, $dimensions, true)) {
            $errors['dimension'] = 'Choose what to break the metric down by.';
        } elseif ($dimension !== null && ! isset($errors['metric']) && ! $this->metrics->supports($metric, $dimension)) {
            $errors['dimension'] = sprintf('%s can\'t be broken down by %s.', $this->metrics->get($metric)->label, strtolower(self::DIMENSIONS[$dimension]));
        }

        $mode = ($input['range_mode'] ?? 'inherit') === 'fixed' ? 'fixed' : 'inherit';
        $preset = $input['range_preset'] ?? null;
        if ($mode === 'fixed' && (! is_string($preset) || ! isset(DateRange::PRESETS[$preset]) || $preset === 'custom')) {
            $errors['range_preset'] = 'Choose a date range for this widget.';
        }

        $limit = (int) ($input['limit'] ?? 8);
        if ($limit < 3 || $limit > 25) {
            $errors['limit'] = 'Show between 3 and 25 items.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'type' => $type,
            'title' => $title,
            'config' => [
                'metric' => $metric,
                'dimension' => $dimension,
                'range' => ['mode' => $mode, 'preset' => $mode === 'fixed' ? $preset : null],
                'filters' => [
                    'branches' => $this->ownedIds('branches', $input['branches'] ?? []),
                    'categories' => $this->ownedIds('product_categories', $input['categories'] ?? []),
                ],
                'comparison' => in_array($type, ['kpi', 'line', 'area', 'table', 'bar'], true) && (bool) ($input['comparison'] ?? true),
                'limit' => $limit,
            ],
        ];
    }

    /** @return array{w: int, h: int, min_w: int, min_h: int} */
    public static function size(string $type): array
    {
        [, $w, $h, $minW, $minH] = self::TYPES[$type];

        return ['w' => $w, 'h' => $h, 'min_w' => $minW, 'min_h' => $minH];
    }

    private function isUsable(string $metric, User $user): bool
    {
        if (isset($this->metrics->base()[$metric])) {
            return true;
        }

        return isset($this->metrics->custom()[$metric])
            && Metric::query()->usableBy($user)->where('key', $metric)->exists();
    }

    /**
     * Only ids of this organization survive.
     *
     * @return list<int>
     */
    private function ownedIds(string $table, mixed $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', is_array($ids) ? $ids : [])));
        if ($ids === []) {
            return [];
        }

        return DB::table($table)->where('organization_id', $this->tenant->id())->whereIn('id', $ids)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
