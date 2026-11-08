<?php

namespace Database\Seeders;

use App\Domain\Metrics\Formula\Formula;
use App\Domain\Tenancy\TenantContext;
use App\Models\Dashboard;
use App\Models\Metric;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Example custom metrics and a shared "Branch operations" dashboard for the
 * Nexora demo. Idempotent: skips anything that already exists, so it can be
 * run on an existing database (`php artisan db:seed --class=NexoraDashboardsSeeder`).
 */
class NexoraDashboardsSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo seeders must not run in production.');
        }

        $org = Organization::query()->where('slug', NexoraDemoSeeder::SLUG)->first();
        if ($org === null) {
            $this->command?->warn('Nexora Group not found; run NexoraDemoSeeder first.');

            return;
        }

        $owner = User::query()->where('email', 'owner@nexora.test')->firstOrFail();
        $analyst = User::query()->where('email', 'analyst@nexora.test')->firstOrFail();

        app(TenantContext::class)->runAs($org, function () use ($owner, $analyst) {
            $this->metrics($analyst);
            $this->dashboard($owner);
        });
    }

    private function metrics(User $author): void
    {
        $definitions = [
            ['profit_per_order', 'Profit per order', 'Net profit earned per completed order.', 'net_profit / orders', 'currency', 0, 'up_good'],
            ['cogs_share', 'COGS share of revenue', 'Cost of goods sold as a percentage of revenue.', 'cogs / revenue * 100', 'percent', 1, 'up_bad'],
            ['units_per_order', 'Units per order', 'Average number of units in a completed order.', 'units_sold / orders', 'number', 2, 'up_good'],
        ];

        foreach ($definitions as [$key, $name, $description, $formula, $format, $decimals, $polarity]) {
            if (Metric::withTrashed()->where('key', $key)->exists()) {
                continue;
            }
            $parsed = Formula::parse($formula);
            Metric::query()->create([
                'key' => $key,
                'name' => $name,
                'description' => $description,
                'formula' => $parsed->normalized(),
                'dependencies' => $parsed->identifiers(),
                'format' => $format,
                'decimals' => $decimals,
                'polarity' => $polarity,
                'visibility' => 'organization',
                'created_by' => $author->id,
            ]);
        }
    }

    private function dashboard(User $owner): void
    {
        if (Dashboard::query()->where('name', 'Branch operations')->exists()) {
            return;
        }

        $dashboard = Dashboard::query()->create([
            'owner_id' => $owner->id,
            'name' => 'Branch operations',
            'description' => 'Weekly operating review: sales, profitability and cost structure by branch.',
            'visibility' => 'organization',
        ]);

        $widget = fn (string $type, string $title, string $metric, ?string $dimension, array $pos, array $extra = []) => $dashboard->widgets()->create([
            'type' => $type,
            'title' => $title,
            'config' => [
                'metric' => $metric,
                'dimension' => $dimension,
                'range' => ['mode' => $extra['preset'] ?? null ? 'fixed' : 'inherit', 'preset' => $extra['preset'] ?? null],
                'filters' => ['branches' => [], 'categories' => []],
                'comparison' => $extra['comparison'] ?? true,
                'limit' => $extra['limit'] ?? 8,
            ],
            'x' => $pos[0], 'y' => $pos[1], 'w' => $pos[2], 'h' => $pos[3],
        ]);

        // Row 1: headline KPIs (3 columns each).
        $widget('kpi', 'Revenue', 'revenue', null, [0, 0, 3, 2]);
        $widget('kpi', 'Net profit', 'net_profit', null, [3, 0, 3, 2]);
        $widget('kpi', 'Profit per order', 'profit_per_order', null, [6, 0, 3, 2]);
        $widget('kpi', 'Units per order', 'units_per_order', null, [9, 0, 3, 2]);
        // Row 2: trend + channel mix.
        $widget('area', 'Daily revenue', 'revenue', 'date', [0, 2, 8, 4]);
        $widget('donut', 'Revenue by channel', 'revenue', 'channel', [8, 2, 4, 4], ['comparison' => false]);
        // Row 3: branch profitability + top products.
        $widget('bar', 'Net profit by branch', 'net_profit', 'branch', [0, 6, 6, 4]);
        $widget('ranked_list', 'Top products by revenue', 'revenue', 'product', [6, 6, 6, 5], ['limit' => 8, 'comparison' => false]);
        // Row 4: cost structure (fixed window so seasonality is visible).
        $widget('table', 'Expenses by category', 'operating_expenses', 'expense_category', [0, 10, 6, 5], ['limit' => 10]);
        $widget('line', 'COGS share, this year', 'cogs_share', 'date', [6, 11, 6, 4], ['preset' => 'this_year', 'comparison' => false]);
    }
}
