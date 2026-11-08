<?php

use App\Domain\Analytics\Query\DateRange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Hand-computed fixture (amounts in PKR):
 *
 *  October 2026                                 net    cogs  qty
 *   T1  Oct 5  LHR completed  P1 ×2 @100          200    120    2
 *                             P2 ×1 @50            50     20    1
 *   T2  Oct 6  KHI completed  P1 ×1 @100 −10 disc  90     60    1
 *   T3  Oct 7  LHR refunded   P1 ×5 @100        (excluded — not completed)
 *   Expenses: Oct 1 LHR 100 · Oct 15 head office 30
 *
 *  → revenue 340, COGS 200, gross 140, opex 130, net 10, orders 2, units 4,
 *    AOV 170, gross margin 41.18 %, net margin 2.94 %
 *
 *  September 2026
 *   T4  Sep 10 LHR completed  P2 ×2 @50           100     40    2
 *   Expenses: Sep 1 LHR 70
 *  → revenue 100, COGS 40, opex 70, net −10
 */
function seedFixture(): array
{
    [, $org] = memberOf();
    $ids = asTenant($org, function () use ($org) {
        $now = now();
        $o = $org->id;
        $branch = fn ($code) => DB::table('branches')->insertGetId(['organization_id' => $o, 'code' => $code, 'name' => $code === 'LHR' ? 'Lahore' : 'Karachi', 'created_at' => $now, 'updated_at' => $now]);
        $lhr = $branch('LHR');
        $khi = $branch('KHI');
        $catX = DB::table('product_categories')->insertGetId(['organization_id' => $o, 'name' => 'Appliances', 'created_at' => $now, 'updated_at' => $now]);
        $catY = DB::table('product_categories')->insertGetId(['organization_id' => $o, 'name' => 'Lighting', 'created_at' => $now, 'updated_at' => $now]);
        $product = fn ($sku, $cat, $price, $cost) => DB::table('products')->insertGetId(['organization_id' => $o, 'product_category_id' => $cat, 'sku' => $sku, 'name' => "Product {$sku}", 'unit_price' => $price, 'unit_cost' => $cost, 'created_at' => $now, 'updated_at' => $now]);
        $p1 = $product('P1', $catX, 100, 60);
        $p2 = $product('P2', $catY, 50, 20);
        $cat = DB::table('expense_categories')->insertGetId(['organization_id' => $o, 'name' => 'Rent', 'created_at' => $now, 'updated_at' => $now]);

        $sale = function (string $ref, string $date, int $branch, string $status, array $lines) use ($o, $now) {
            $net = array_sum(array_map(fn ($l) => $l[1] * $l[2] - ($l[4] ?? 0), $lines));
            $id = DB::table('sales_transactions')->insertGetId(['organization_id' => $o, 'branch_id' => $branch, 'reference' => $ref, 'occurred_at' => "{$date} 07:00:00", 'business_date' => $date, 'status' => $status, 'net_amount' => $net, 'created_at' => $now, 'updated_at' => $now]);
            foreach ($lines as [$product, $qty, $price, $cost, $disc]) {
                DB::table('sales_transaction_items')->insert(['organization_id' => $o, 'sales_transaction_id' => $id, 'product_id' => $product, 'branch_id' => $branch, 'business_date' => $date,
                    'quantity' => $qty, 'unit_price' => $price, 'unit_cost' => $cost, 'discount_amount' => $disc, 'net_amount' => $qty * $price - $disc, 'natural_key_hash' => sha1($ref.$product)]);
            }
        };
        $sale('T1', '2026-10-05', $lhr, 'completed', [[$p1, 2, 100, 60, 0], [$p2, 1, 50, 20, 0]]);
        $sale('T2', '2026-10-06', $khi, 'completed', [[$p1, 1, 100, 60, 10]]);
        $sale('T3', '2026-10-07', $lhr, 'refunded', [[$p1, 5, 100, 60, 0]]);
        $sale('T4', '2026-09-10', $lhr, 'completed', [[$p2, 2, 50, 20, 0]]);

        $expense = fn ($date, $branch, $amount) => DB::table('expenses')->insert(['organization_id' => $o, 'expense_category_id' => $cat, 'branch_id' => $branch, 'business_date' => $date, 'amount' => $amount, 'natural_key_hash' => sha1($date.$branch.$amount), 'created_at' => $now, 'updated_at' => $now]);
        $expense('2026-10-01', $lhr, 100);
        $expense('2026-10-15', null, 30);
        $expense('2026-09-01', $lhr, 70);

        return compact('lhr', 'khi', 'catX', 'catY', 'p1', 'p2');
    });

    return [$org, $ids];
}

function october(): DateRange
{
    return DateRange::custom(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'));
}
