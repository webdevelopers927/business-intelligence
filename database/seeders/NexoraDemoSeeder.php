<?php

namespace Database\Seeders;

use App\Domain\Analytics\AnalyticsCache;
use App\Domain\Tenancy\TenantContext;
use App\Enums\Industry;
use App\Enums\OrganizationSize;
use App\Enums\Role;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\NexoraCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Nexora Group — a believable, internally consistent demo organization.
 *
 * One deterministic pass generates ~18 months of sales up to today, and every
 * other dataset is derived from the same tallies: expenses scale with branch
 * revenue, salaries come from the employee roster, inventory follows demand,
 * targets are set against actuals. Nothing on a dashboard can disagree.
 *
 * Built-in stories (useful for insights and anomaly detection later):
 *  - Eid ul Fitr and Eid ul Adha shopping peaks, closures on Eid days;
 *  - 11.11 and "White Friday" sales with heavier discounting and online share;
 *  - Summer surge in fans/coolers and electricity bills; heaters in winter;
 *  - ~0.6% monthly price inflation and ~1.2% monthly volume growth;
 *  - Islamabad branch partially closed for renovation (days 8–21, two months
 *    ago): an order dip plus a one-off maintenance expense.
 *
 * All people and companies are fictional. Demo logins use the .test domain.
 */
class NexoraDemoSeeder extends Seeder
{
    public const SLUG = 'nexora';

    public const PASSWORD = 'password';

    private const MONTHS_OF_HISTORY = 18;

    private const TIMEZONE = 'Asia/Karachi';

    private int $orgId;

    private CarbonImmutable $today;

    private CarbonImmutable $start;

    /** @var array<string, int> */
    private array $branchIds = [];

    /** @var array<string, int> */
    private array $departmentIds = [];

    /** @var list<array{id: int, sku: string, name: string, category: string, price: int, cost: int, seasonal: string|null}> */
    private array $products = [];

    /** @var list<float> cumulative popularity weights, aligned with */
    private array $cumulative = [];

    /** @var array<string, float> "BRANCH|Y-m" => completed revenue */
    private array $revenue = [];

    /** @var array<string, float> "productId|branchId|Y-m" => units sold */
    private array $demand = [];

    /** @var list<array{id: int, branch: string|null, dept: string, hired: CarbonImmutable, terminated: CarbonImmutable|null, salary: int}> */
    private array $staff = [];

    private int $posSourceId;

    private int $hrSourceId;

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The Nexora demo seeder must not run in production.');
        }

        if (Organization::withTrashed()->where('slug', self::SLUG)->exists()) {
            $this->command?->warn('Nexora Group already exists. Run `php artisan migrate:fresh --seed` to rebuild it.');

            return;
        }

        mt_srand(20261109);
        $this->today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        $this->start = $this->today->subMonthsNoOverflow(self::MONTHS_OF_HISTORY)->startOfMonth();

        $organization = $this->createOrganizationAndUsers();
        $this->orgId = $organization->id;

        app(TenantContext::class)->runAs($organization, function () {
            DB::transaction(function () {
                $this->step('Branches, departments and sources', fn () => $this->seedDimensions());
                $this->step('Products', fn () => $this->seedProducts());
                $this->step('Employees', fn () => $this->seedEmployees());
                $this->step('Sales transactions', fn () => $this->seedSales());
                $this->step('Expenses', fn () => $this->seedExpenses());
                $this->step('Inventory snapshots', fn () => $this->seedInventory());
                $this->step('Revenue targets', fn () => $this->seedTargets());
            });
        });

        app(AnalyticsCache::class)->bump($this->orgId);

        $this->call(NexoraDashboardsSeeder::class);
    }

    private function step(string $label, callable $callback): void
    {
        $started = microtime(true);
        $callback();
        $this->command?->info(sprintf('  %-36s %6.1fs', $label, microtime(true) - $started));
    }

    // ── Organization & users ────────────────────────────────────────────

    private function createOrganizationAndUsers(): Organization
    {
        $organization = Organization::query()->create([
            'name' => 'Nexora Group',
            'slug' => self::SLUG,
            'industry' => Industry::Distribution,
            'size' => OrganizationSize::Medium,
            'timezone' => self::TIMEZONE,
            'currency' => 'PKR',
            'fiscal_year_start_month' => 7,
            'onboarded_at' => now(),
        ]);

        $people = [
            [Role::Owner, 'owner@nexora.test', 'Ayesha Rahman'],
            [Role::Admin, 'admin@nexora.test', 'Bilal Qureshi'],
            [Role::Analyst, 'analyst@nexora.test', 'Sana Iqbal'],
            [Role::Viewer, 'viewer@nexora.test', 'Omar Farooq'],
        ];

        foreach ($people as [$role, $email, $name]) {
            $user = User::query()->firstOrCreate(['email' => $email], [
                'name' => $name,
                'password' => self::PASSWORD,
                'email_verified_at' => now(),
            ]);
            $user->forceFill(['current_organization_id' => $organization->id])->save();

            Membership::query()->create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'role' => $role,
                'joined_at' => $this->today->subMonths(self::MONTHS_OF_HISTORY),
            ]);
        }

        return $organization;
    }

    // ── Dimensions ──────────────────────────────────────────────────────

    private function seedDimensions(): void
    {
        $now = now();

        foreach (NexoraCatalog::BRANCHES as $code => [$name, $city, $opened]) {
            $this->branchIds[$code] = (int) DB::table('branches')->insertGetId([
                'organization_id' => $this->orgId, 'code' => $code, 'name' => $name, 'city' => $city,
                'opened_on' => $opened, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        foreach (NexoraCatalog::DEPARTMENTS as $code => $name) {
            $this->departmentIds[$code] = (int) DB::table('departments')->insertGetId([
                'organization_id' => $this->orgId, 'code' => $code, 'name' => $name, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $this->posSourceId = (int) DB::table('data_sources')->insertGetId([
            'organization_id' => $this->orgId, 'name' => 'Nexora POS exports', 'type' => 'csv',
            'description' => 'Daily line-level sales exports from the point-of-sale system in all three branches.',
            'status' => 'active', 'last_synced_at' => $now, 'created_at' => $this->start, 'updated_at' => $now,
        ]);

        $this->hrSourceId = (int) DB::table('data_sources')->insertGetId([
            'organization_id' => $this->orgId, 'name' => 'HR roster workbook', 'type' => 'xlsx',
            'description' => 'Monthly headcount export maintained by HR.',
            'status' => 'active', 'last_synced_at' => $now, 'created_at' => $this->start, 'updated_at' => $now,
        ]);
    }

    private function seedProducts(): void
    {
        $now = now();
        $seasonal = [
            'fan' => 'summer', 'cooler' => 'summer', 'heater' => 'winter', 'solar' => 'summer', 'emergency light' => 'summer',
        ];

        foreach (NexoraCatalog::PRODUCTS as $category => [$prefix, $margin, $weight, $items]) {
            $categoryId = (int) DB::table('product_categories')->insertGetId([
                'organization_id' => $this->orgId, 'name' => $category, 'created_at' => $now, 'updated_at' => $now,
            ]);

            foreach ($items as $i => [$name, $price]) {
                $cost = (int) (round($price * (1 - $margin) * $this->between(0.95, 1.05) / 10) * 10);
                $sku = sprintf('%s-%03d', $prefix, ($i + 1) * 10);
                $season = null;
                foreach ($seasonal as $needle => $s) {
                    if (stripos($name, $needle) !== false) {
                        $season = $s;
                    }
                }

                $id = (int) DB::table('products')->insertGetId([
                    'organization_id' => $this->orgId, 'product_category_id' => $categoryId, 'sku' => $sku, 'name' => $name,
                    'unit_price' => $price, 'unit_cost' => $cost, 'is_active' => true,
                    'data_source_id' => $this->posSourceId, 'created_at' => $now, 'updated_at' => $now,
                ]);

                $this->products[] = ['id' => $id, 'sku' => $sku, 'name' => $name, 'category' => $category, 'price' => $price, 'cost' => $cost, 'seasonal' => $season];
                // Within a category, earlier items sell more (long-tail popularity).
                $this->cumulative[] = (end($this->cumulative) ?: 0) + $weight / (1 + $i * 0.18);
            }
        }
    }

    private function seedEmployees(): void
    {
        $now = now();
        $rows = [];
        $n = 0;

        foreach (NexoraCatalog::STAFFING as [$dept, $branch, $title, $count, [$min, $max]]) {
            $opened = $branch ? CarbonImmutable::parse(NexoraCatalog::BRANCHES[$branch][2]) : CarbonImmutable::parse('2017-01-15');

            for ($k = 0; $k < $count; $k++) {
                $n++;
                // ~20% of staff join during the demo window, so headcount grows.
                $hired = $this->chance(0.2)
                    ? $this->start->addDays(mt_rand(10, max(11, (int) $this->start->diffInDays($this->today) - 20)))
                    : $opened->addDays(mt_rand(0, max(1, (int) $opened->diffInDays($this->start) - 1)));
                $terminated = $this->chance(0.09) && $hired->lt($this->today->subMonths(4))
                    ? CarbonImmutable::createFromTimestamp(mt_rand(max($hired->addMonths(2)->timestamp, $this->start->timestamp), $this->today->subDays(10)->timestamp), self::TIMEZONE)->startOfDay()
                    : null;
                $salary = (int) (round(mt_rand($min, $max) / 500) * 500);

                $first = NexoraCatalog::FIRST_NAMES[($n * 7) % count(NexoraCatalog::FIRST_NAMES)];
                $last = NexoraCatalog::LAST_NAMES[($n * 5 + intdiv($n, 24)) % count(NexoraCatalog::LAST_NAMES)];

                $rows[] = [
                    'organization_id' => $this->orgId,
                    'employee_code' => sprintf('NX-%04d', $n),
                    'full_name' => "{$first} {$last}",
                    'department_id' => $this->departmentIds[$dept],
                    'branch_id' => $branch ? $this->branchIds[$branch] : null,
                    'job_title' => $title,
                    'employment_type' => $title === 'Logistics Associate' && $this->chance(0.3) ? 'contract' : 'full_time',
                    'hired_on' => $hired->toDateString(),
                    'terminated_on' => $terminated?->toDateString(),
                    'monthly_salary' => $salary,
                    'data_source_id' => $this->hrSourceId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $this->staff[] = ['id' => $n, 'branch' => $branch, 'dept' => $dept, 'hired' => $hired, 'terminated' => $terminated, 'salary' => $salary];
            }
        }

        DB::table('employees')->insert($rows);
    }

    // ── Sales ───────────────────────────────────────────────────────────

    private function seedSales(): void
    {
        $nextHeaderId = (int) DB::table('sales_transactions')->max('id') + 1;
        $headers = [];
        $items = [];
        $now = now();
        $seq = 0;

        for ($day = $this->start; $day->lte($this->today); $day = $day->addDay()) {
            $season = $this->seasonality($day);
            $promo = $this->isPromo($day);
            $monthIndex = ($day->year - $this->start->year) * 12 + $day->month - $this->start->month;
            $monthsAgo = self::MONTHS_OF_HISTORY - $monthIndex;
            $growth = 1 + 0.012 * $monthIndex;
            $weekday = match ($day->dayOfWeekIso) {
                6 => 1.22, 7 => 1.3, 5 => 0.92, default => 1.0
            };

            foreach (NexoraCatalog::BRANCHES as $code => [, , , $volume]) {
                $orders = 22 * $volume * $weekday * $season * $growth * $this->between(0.85, 1.15) * $this->renovation($code, $day);
                if ($day->equalTo($this->today)) {
                    $orders *= 0.55; // today is still in progress
                }

                for ($o = 0, $count = (int) round($orders); $o < $count; $o++) {
                    $seq++;
                    $id = $nextHeaderId++;
                    $reference = sprintf('NX-%s-%s-%04d', $code, $day->format('ymd'), $o + 1);
                    $channel = $this->pickChannel($promo);
                    $status = $this->pickStatus($day);
                    $local = $day->setTime(mt_rand(10, 21), mt_rand(0, 59), mt_rand(0, 59));
                    $lineCount = $this->weighted([1 => 45, 2 => 30, 3 => 17, 4 => 8]);

                    $gross = $discountTotal = $net = 0.0;
                    $used = [];

                    for ($l = 0; $l < $lineCount; $l++) {
                        $p = $this->pickProduct($day, $used);
                        $used[$p['id']] = true;

                        $qty = $p['price'] < 3000 ? mt_rand(1, 3) : ($this->chance(0.08) ? 2 : 1);
                        if ($channel === 'wholesale') {
                            $qty *= $p['price'] < 3000 ? mt_rand(6, 20) : mt_rand(2, 5);
                        }

                        $unitPrice = round($p['price'] / (1 + 0.006 * $monthsAgo) / 10) * 10;
                        $unitCost = round($p['cost'] / (1 + 0.005 * $monthsAgo) / 10) * 10;
                        $lineGross = $qty * $unitPrice;
                        $discount = $this->chance($promo ? 0.6 : 0.18) ? round($lineGross * mt_rand(5, $promo ? 20 : 10) / 100 / 10) * 10 : 0;
                        if ($channel === 'wholesale') {
                            $discount = max($discount, round($lineGross * 0.08 / 10) * 10);
                        }
                        $lineNet = $lineGross - $discount;

                        $gross += $lineGross;
                        $discountTotal += $discount;
                        $net += $lineNet;

                        $items[] = [
                            'organization_id' => $this->orgId,
                            'sales_transaction_id' => $id,
                            'product_id' => $p['id'],
                            'branch_id' => $this->branchIds[$code],
                            'business_date' => $day->toDateString(),
                            'quantity' => $qty,
                            'unit_price' => $unitPrice,
                            'unit_cost' => $unitCost,
                            'discount_amount' => $discount,
                            'net_amount' => $lineNet,
                            'natural_key_hash' => sha1(mb_strtolower($reference."\x1f".$p['sku'])),
                            'data_source_id' => $this->posSourceId,
                        ];

                        if ($status === 'completed') {
                            $dk = $p['id'].'|'.$this->branchIds[$code].'|'.$day->format('Y-m');
                            $this->demand[$dk] = ($this->demand[$dk] ?? 0) + $qty;
                        }
                    }

                    if ($status === 'completed') {
                        $rk = $code.'|'.$day->format('Y-m');
                        $this->revenue[$rk] = ($this->revenue[$rk] ?? 0) + $net;
                    }

                    $headers[] = [
                        'id' => $id,
                        'organization_id' => $this->orgId,
                        'branch_id' => $this->branchIds[$code],
                        'reference' => $reference,
                        'occurred_at' => $local->utc()->format('Y-m-d H:i:s'),
                        'business_date' => $day->toDateString(),
                        'status' => $status,
                        'channel' => $channel,
                        'customer_segment' => $channel === 'wholesale' ? 'business' : ($this->chance(0.35) ? 'returning' : 'new'),
                        'items_count' => $lineCount,
                        'gross_amount' => $gross,
                        'discount_amount' => $discountTotal,
                        'net_amount' => $net,
                        'data_source_id' => $this->posSourceId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (count($headers) >= 400) {
                        $this->flushSales($headers, $items);
                    }
                }
            }
        }

        $this->flushSales($headers, $items);
        $this->command?->line(sprintf('    %s orders generated', number_format($seq)));
    }

    /**
     * @param  list<array<string, mixed>>  $headers
     * @param  list<array<string, mixed>>  $items
     */
    private function flushSales(array &$headers, array &$items): void
    {
        if ($headers !== []) {
            DB::table('sales_transactions')->insert($headers);
        }
        foreach (array_chunk($items, 400) as $batch) {
            DB::table('sales_transaction_items')->insert($batch);
        }
        $headers = [];
        $items = [];
    }

    private function seasonality(CarbonImmutable $day): float
    {
        $eidUlFitr = ['2025-03-31', '2026-03-20', '2027-03-10'];
        $eidUlAdha = ['2025-06-07', '2026-05-27', '2027-05-17'];

        foreach ($eidUlFitr as $eid) {
            $diff = (int) $day->diffInDays(CarbonImmutable::parse($eid, self::TIMEZONE), false);
            if ($diff >= -2 && $diff <= 0) {
                return 0.45; // Eid holidays: most stores closed or quiet
            }
            if ($diff > 0 && $diff <= 14) {
                return 1.55; // Chand raat run-up
            }
            if ($diff > 14 && $diff <= 30) {
                return 1.15; // Ramadan
            }
        }

        foreach ($eidUlAdha as $eid) {
            $diff = (int) $day->diffInDays(CarbonImmutable::parse($eid, self::TIMEZONE), false);
            if ($diff >= -2 && $diff <= 0) {
                return 0.5;
            }
            if ($diff > 0 && $diff <= 10) {
                return 1.2;
            }
        }

        return match (true) {
            $day->month === 11 && $day->day >= 9 && $day->day <= 12 => 1.7,  // 11.11
            $day->month === 11 && $day->day >= 24 => 1.3,                     // White Friday week
            $day->month === 8 && $day->day >= 10 && $day->day <= 14 => 1.2,   // Independence Day sale
            $day->month === 12 => 1.1,
            in_array($day->month, [6, 7, 8], true) => 1.05,
            default => 1.0,
        };
    }

    private function isPromo(CarbonImmutable $day): bool
    {
        return $day->month === 11 && (($day->day >= 9 && $day->day <= 12) || $day->day >= 24);
    }

    /** Islamabad renovation two months ago: limited trading on days 8–21. */
    private function renovation(string $branch, CarbonImmutable $day): float
    {
        $month = $this->today->subMonthsNoOverflow(2);

        return $branch === 'ISB' && $day->year === $month->year && $day->month === $month->month && $day->day >= 8 && $day->day <= 21
            ? 0.3
            : 1.0;
    }

    private function pickChannel(bool $promo): string
    {
        $r = $this->random();

        return $promo
            ? ($r < 0.52 ? 'store' : ($r < 0.94 ? 'online' : 'wholesale'))
            : ($r < 0.70 ? 'store' : ($r < 0.92 ? 'online' : 'wholesale'));
    }

    private function pickStatus(CarbonImmutable $day): string
    {
        $r = $this->random();
        $recent = $day->gte($this->today->subDays(2));

        return match (true) {
            $r < 0.94 => 'completed',
            $r < 0.97 => 'refunded',
            $r < 0.99 => 'cancelled',
            default => $recent ? 'pending' : 'completed',
        };
    }

    /**
     * Popularity-weighted product, with seasonal items mostly skipped out of season.
     *
     * @param  array<int, true>  $exclude
     * @return array{id: int, sku: string, name: string, category: string, price: int, cost: int, seasonal: string|null}
     */
    private function pickProduct(CarbonImmutable $day, array $exclude): array
    {
        $summer = in_array($day->month, [4, 5, 6, 7, 8, 9], true);
        $winter = in_array($day->month, [11, 12, 1, 2], true);

        for ($attempt = 0; $attempt < 10; $attempt++) { // bounded retries for exclusions
            $target = $this->random() * end($this->cumulative);
            [$lo, $hi] = [0, count($this->cumulative) - 1];
            while ($lo < $hi) {
                $mid = intdiv($lo + $hi, 2);
                $this->cumulative[$mid] < $target ? $lo = $mid + 1 : $hi = $mid;
            }
            $p = $this->products[$lo];

            $offSeason = ($p['seasonal'] === 'summer' && ! $summer) || ($p['seasonal'] === 'winter' && ! $winter);
            if (isset($exclude[$p['id']]) || ($offSeason && $this->chance(0.85))) {
                continue;
            }

            return $p;
        }

        return $this->products[mt_rand(0, count($this->products) - 1)];
    }

    // ── Expenses ────────────────────────────────────────────────────────

    private function seedExpenses(): void
    {
        $rows = [];
        $now = now();
        $categories = [];
        foreach (['Rent', 'Salaries & Wages', 'Utilities', 'Marketing & Advertising', 'Logistics & Freight', 'Maintenance & Repairs',
            'Security Services', 'IT & Software', 'Bank & Payment Charges', 'Office & Admin'] as $name) {
            $categories[$name] = (int) DB::table('expense_categories')->insertGetId([
                'organization_id' => $this->orgId, 'name' => $name, 'kind' => 'operating', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $add = function (CarbonImmutable $date, string $category, float $amount, ?string $branch, ?string $dept, string $vendor, string $description) use (&$rows, $categories, $now) {
            if ($date->gt($this->today) || $amount <= 0) {
                return;
            }
            $reference = sprintf('EXP-%s-%s-%03d', $branch ?? 'HO', $date->format('ym'), count($rows) + 1);
            $rows[] = [
                'organization_id' => $this->orgId,
                'expense_category_id' => $categories[$category],
                'branch_id' => $branch ? $this->branchIds[$branch] : null,
                'department_id' => $dept ? $this->departmentIds[$dept] : null,
                'business_date' => $date->toDateString(),
                'amount' => round($amount, -2),
                'reference' => $reference,
                'vendor' => $vendor,
                'description' => $description,
                'natural_key_hash' => sha1(mb_strtolower('ref'."\x1f".$reference)),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        };

        $rent = ['KHI' => 650000, 'LHR' => 520000, 'ISB' => 480000];
        $power = ['KHI' => 210000, 'LHR' => 175000, 'ISB' => 140000];
        $renovationMonth = $this->today->subMonthsNoOverflow(2)->format('Y-m');

        for ($month = $this->start; $month->lte($this->today); $month = $month->addMonthNoOverflow()) {
            $ym = $month->format('Y-m');
            $monthEnd = $month->endOfMonth()->startOfDay();
            $summer = in_array($month->month, [5, 6, 7, 8, 9], true);
            $winter = in_array($month->month, [12, 1], true);

            foreach (array_keys(NexoraCatalog::BRANCHES) as $code) {
                $revenue = $this->revenue["{$code}|{$ym}"] ?? 0;
                $label = NexoraCatalog::BRANCHES[$code][0];

                $add($month, 'Rent', $rent[$code] * (1 + 0.1 * intdiv((int) $this->start->diffInMonths($month), 12)), $code, 'OPS', 'Metro Estates (Pvt) Ltd', "Monthly rent — {$label}");
                $add($month->addDays(2), 'Security Services', 85000, $code, 'OPS', 'Shield Guard Services', 'Guarding contract');
                $add($month->addDays(11), 'Utilities', $power[$code] * ($summer ? 1.65 : ($winter ? 1.2 : 1.0)) * $this->between(0.9, 1.1), $code, 'OPS', 'City Power Distribution', 'Electricity and gas');
                $add($month->addDays(4), 'Marketing & Advertising', $revenue * 0.012 * $this->between(0.8, 1.2), $code, 'MKT', 'AdReach Media', 'Local store promotion');
                $add($month->addDays(24), 'Logistics & Freight', $revenue * 0.022 * $this->between(0.9, 1.1), $code, 'WHS', 'SwiftCargo Logistics', 'Inbound freight and deliveries');
                $add($monthEnd, 'Bank & Payment Charges', $revenue * 0.008, $code, 'FIN', 'Merchant acquiring', 'Card and wallet processing fees');

                for ($m = 0, $n = mt_rand(1, 2); $m < $n; $m++) {
                    $add($month->addDays(mt_rand(5, 25)), 'Maintenance & Repairs', mt_rand(15, 95) * 1000, $code, 'OPS', 'FixRight Maintenance', 'Repairs and upkeep');
                }

                if ($code === 'ISB' && $ym === $renovationMonth) {
                    $add($month->addDays(7), 'Maintenance & Repairs', 2800000, $code, 'OPS', 'FixRight Maintenance', 'Store renovation — flooring, lighting and fixtures');
                }
            }

            // Salaries: one posting per department per branch (head office: no branch).
            $payroll = [];
            foreach ($this->staff as $s) {
                if ($s['hired']->lte($monthEnd) && ($s['terminated'] === null || $s['terminated']->gte($month))) {
                    $key = ($s['branch'] ?? '').'|'.$s['dept'];
                    $payroll[$key] = ($payroll[$key] ?? 0) + $s['salary'];
                }
            }
            foreach ($payroll as $key => $total) {
                [$branch, $dept] = explode('|', $key);
                $add($monthEnd, 'Salaries & Wages', $total, $branch ?: null, $dept, 'Payroll', 'Monthly payroll — '.NexoraCatalog::DEPARTMENTS[$dept]);
            }

            // Head-office costs.
            $add($month->addDays(1), 'Marketing & Advertising', 250000 * $this->between(0.9, 1.1), null, 'MKT', 'AdReach Media', 'Always-on digital advertising');
            $add($month->addDays(3), 'IT & Software', 165000, null, 'OPS', 'CloudDesk Software', 'POS, ERP and productivity licences');
            $add($month->addDays(14), 'Office & Admin', mt_rand(45, 80) * 1000, null, 'HRA', 'Office supplies', 'Head office supplies and services');
            if ($month->month === 11) {
                $add($month->addDays(2), 'Marketing & Advertising', 1200000, null, 'MKT', 'AdReach Media', '11.11 and White Friday campaign');
            }
        }

        // Pre-Eid campaigns.
        foreach (['2025-03-31', '2025-06-07', '2026-03-20', '2026-05-27'] as $eid) {
            $add(CarbonImmutable::parse($eid, self::TIMEZONE)->subDays(18), 'Marketing & Advertising', 900000, null, 'MKT', 'AdReach Media', 'Eid campaign');
        }

        foreach (array_chunk($rows, 400) as $batch) {
            DB::table('expenses')->insert(array_map(fn ($r) => [...$r, 'data_source_id' => null], $batch));
        }
    }

    // ── Inventory ───────────────────────────────────────────────────────

    private function seedInventory(): void
    {
        $rows = [];
        $now = now();
        $snapshots = [];
        for ($d = $this->start; $d->lte($this->today); $d = $d->addMonthNoOverflow()) {
            $snapshots[] = $d;
        }
        if (! end($snapshots)->equalTo($this->today)) {
            $snapshots[] = $this->today;
        }

        foreach ($snapshots as $i => $date) {
            $monthsAgo = (int) $date->diffInMonths($this->today);
            foreach ($this->products as $p) {
                foreach ($this->branchIds as $branchId) {
                    // Stock covers next month's demand with noise; ~7% of lines run low.
                    $nextMonth = $date->addMonthNoOverflow()->format('Y-m');
                    $demand = $this->demand["{$p['id']}|{$branchId}|{$nextMonth}"] ?? $this->demand["{$p['id']}|{$branchId}|{$date->format('Y-m')}"] ?? 1;
                    $reorder = max(2, (int) round($demand * 0.4));
                    $qty = $this->chance(0.07) ? mt_rand(0, max(0, $reorder - 1)) : (int) round($demand * $this->between(0.7, 1.8)) + $reorder;

                    $rows[] = [
                        'organization_id' => $this->orgId,
                        'product_id' => $p['id'],
                        'branch_id' => $branchId,
                        'recorded_on' => $date->toDateString(),
                        'quantity_on_hand' => $qty,
                        'reorder_level' => $reorder,
                        'unit_cost' => round($p['cost'] / (1 + 0.005 * $monthsAgo) / 10) * 10,
                        'data_source_id' => $this->posSourceId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            if (count($rows) >= 1000 || $i === count($snapshots) - 1) {
                foreach (array_chunk($rows, 400) as $batch) {
                    DB::table('inventory_records')->insert($batch);
                }
                $rows = [];
            }
        }
    }

    // ── Targets ─────────────────────────────────────────────────────────

    private function seedTargets(): void
    {
        $rows = [];
        $now = now();
        $current = $this->today->format('Y-m');

        for ($month = $this->start; $month->lte($this->today); $month = $month->addMonthNoOverflow()) {
            $ym = $month->format('Y-m');
            $orgTotal = 0;

            foreach (array_keys(NexoraCatalog::BRANCHES) as $code) {
                if ($ym === $current) {
                    // Current month: set from the trailing three months, plus 5%.
                    $base = 0;
                    for ($k = 1; $k <= 3; $k++) {
                        $base += $this->revenue[$code.'|'.$month->subMonthsNoOverflow($k)->format('Y-m')] ?? 0;
                    }
                    $target = $base / 3 * 1.05;
                } else {
                    // Past months: targets were set beforehand, so actuals land on both sides.
                    $target = ($this->revenue["{$code}|{$ym}"] ?? 0) * $this->between(0.93, 1.1);
                }

                $target = round($target, -5);
                $orgTotal += $target;
                $rows[] = ['organization_id' => $this->orgId, 'branch_id' => $this->branchIds[$code], 'month' => $month->toDateString(), 'amount' => $target, 'created_at' => $now, 'updated_at' => $now];
            }

            $rows[] = ['organization_id' => $this->orgId, 'branch_id' => null, 'month' => $month->toDateString(), 'amount' => $orgTotal, 'created_at' => $now, 'updated_at' => $now];
        }

        DB::table('revenue_targets')->insert($rows);
    }

    // ── Deterministic randomness ────────────────────────────────────────

    private function random(): float
    {
        return mt_rand() / mt_getrandmax();
    }

    private function between(float $min, float $max): float
    {
        return $min + $this->random() * ($max - $min);
    }

    private function chance(float $p): bool
    {
        return $this->random() < $p;
    }

    /** @param array<int, int> $weights value => weight */
    private function weighted(array $weights): int
    {
        $r = mt_rand(1, array_sum($weights));
        foreach ($weights as $value => $weight) {
            if (($r -= $weight) <= 0) {
                return $value;
            }
        }

        return array_key_first($weights);
    }
}
