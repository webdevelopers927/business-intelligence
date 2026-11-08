<?php

use App\Domain\Analytics\AnalyticsCache;
use App\Domain\Imports\ImportProcessor;
use App\Enums\ImportStatus;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\DataSource;
use App\Models\Import;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\SalesTransaction;
use App\Models\SalesTransactionItem;
use App\Models\User;
use App\Notifications\ImportFinished;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

beforeEach(function () {
    Storage::fake('local');
});

/** Seed the dimensions a sales file references. */
function seedCatalog(Organization $org): void
{
    asTenant($org, function () {
        Branch::query()->create(['code' => 'LHR', 'name' => 'Lahore Gulberg', 'city' => 'Lahore']);
        Branch::query()->create(['code' => 'KHI', 'name' => 'Karachi Clifton', 'city' => 'Karachi']);
        $cat = ProductCategory::query()->create(['name' => 'Home Appliances']);
        Product::query()->create(['product_category_id' => $cat->id, 'sku' => 'HA-100', 'name' => 'Air fryer 5L', 'unit_price' => 18500, 'unit_cost' => 12000]);
        Product::query()->create(['product_category_id' => $cat->id, 'sku' => 'HA-200', 'name' => 'Steam iron', 'unit_price' => 6200, 'unit_cost' => 3900]);
    });
}

function csvFile(string $name, array $rows): UploadedFile
{
    $csv = implode("\n", array_map(fn ($r) => implode(',', $r), $rows))."\n";

    return UploadedFile::fake()->createWithContent($name, $csv);
}

/** Upload → (optionally) confirm suggested mapping → job runs synchronously. */
function runImport($test, User $user, Organization $org, UploadedFile $file, string $dataset, ?array $mapping = null, ?string $dateFormat = null): Import
{
    $test->actingAs($user)
        ->post(route('imports.store', ['organization' => $org->slug]), ['file' => $file, 'dataset_type' => $dataset])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $import = asTenant($org, fn () => Import::query()->latest('id')->firstOrFail());

    $page = $test->actingAs($user)->get(route('imports.mapping', ['organization' => $org->slug, 'import' => $import->id]));
    $props = $page->viewData('page')['props'];

    $test->actingAs($user)
        ->post(route('imports.confirm', ['organization' => $org->slug, 'import' => $import->id]), [
            'mapping' => $mapping ?? $props['mapping'],
            'date_format' => $dateFormat ?? $props['dateFormat'],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('imports.show', ['organization' => $org->slug, 'import' => $import->id]));

    return asTenant($org, fn () => $import->fresh());
}

const SALES_HEADER = ['Order No', 'Order Date', 'Branch', 'SKU', 'Qty', 'Unit Price', 'Discount'];

it('imports a valid sales CSV end to end with suggested mapping', function () {
    Notification::fake();
    [$user, $org] = memberOf(Role::Analyst);
    seedCatalog($org);
    $versionBefore = app(AnalyticsCache::class)->version($org->id);

    $import = runImport($this, $user, $org, csvFile('november-sales.csv', [
        SALES_HEADER,
        ['INV-1001', '2026-11-02 14:30', 'LHR', 'HA-100', '2', '18500', '1000'],
        ['INV-1001', '2026-11-02 14:30', 'LHR', 'HA-200', '1', '6200', '0'],
        // Thousands separator inside a quoted field, as spreadsheets export it.
        ['INV-1002', '2026-11-03', 'Karachi Clifton', 'HA-200', '3', '"6,200"', ''],
    ]), 'sales');

    expect($import->status)->toBe(ImportStatus::Completed)
        ->and($import->total_rows)->toBe(3)
        ->and($import->imported_rows)->toBe(3)
        ->and($import->failed_rows)->toBe(0);

    asTenant($org, function () use ($import) {
        $first = SalesTransaction::query()->where('reference', 'INV-1001')->sole();
        // 2 × 18,500 − 1,000 + 6,200 = 42,200
        expect((float) $first->net_amount)->toBe(42200.0)
            ->and((float) $first->discount_amount)->toBe(1000.0)
            ->and((float) $first->gross_amount)->toBe(43200.0)
            ->and($first->items_count)->toBe(2)
            ->and($first->business_date->toDateString())->toBe('2026-11-02')
            // 14:30 Asia/Karachi (UTC+5) is stored as 09:30 UTC.
            ->and($first->occurred_at->format('H:i'))->toBe('09:30')
            ->and($first->import_id)->toBe($import->id);

        $second = SalesTransaction::query()->where('reference', 'INV-1002')->sole();
        expect((float) $second->net_amount)->toBe(18600.0)
            ->and(SalesTransactionItem::query()->sum('quantity'))->toEqual(6)
            // Unit cost snapshot falls back to the product's cost.
            ->and((float) SalesTransactionItem::query()->where('net_amount', 18600)->value('unit_cost'))->toBe(3900.0);
    });

    expect(app(AnalyticsCache::class)->version($org->id))->toBeGreaterThan($versionBefore)
        ->and(AuditLog::query()->where('action', 'import.started')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'import.finished')->value('metadata'))->toMatchArray(['imported' => 3]);

    Notification::assertSentTo($user, ImportFinished::class);
});

it('imports an Excel file and upserts products on re-import', function () {
    [$user, $org] = memberOf(Role::Admin);

    $makeXlsx = function (array $rows): UploadedFile {
        $path = tempnam(sys_get_temp_dir(), 'orbit').'.xlsx';
        $writer = new XlsxWriter;
        $writer->openToFile($path);
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return new UploadedFile($path, 'catalog.xlsx', null, null, true);
    };

    $header = ['Product Code', 'Product Name', 'Category', 'Price', 'Cost'];
    $first = runImport($this, $user, $org, $makeXlsx([$header, ['MA-1', 'USB-C cable 1m', 'Mobile Accessories', 950, 410], ['MA-2', 'Car charger', 'Mobile Accessories', 1850, 900]]), 'products');
    expect($first->status)->toBe(ImportStatus::Completed)->and($first->imported_rows)->toBe(2);

    $second = runImport($this, $user, $org, $makeXlsx([$header, ['MA-1', 'USB-C cable 1m', 'Mobile Accessories', 990, 430], ['LT-1', 'LED bulb 12W', 'Lighting', 450, 210]]), 'products');
    expect($second->status)->toBe(ImportStatus::Completed);

    asTenant($org, function () {
        expect(Product::query()->count())->toBe(3)
            ->and((float) Product::query()->where('sku', 'MA-1')->value('unit_price'))->toBe(990.0)
            ->and(ProductCategory::query()->pluck('name')->sort()->values()->all())->toBe(['Lighting', 'Mobile Accessories']);
    });
});

it('rejects invalid files', function (UploadedFile $file, string $message) {
    [$user, $org] = memberOf(Role::Analyst);

    $this->actingAs($user)
        ->post(route('imports.store', ['organization' => $org->slug]), ['file' => $file, 'dataset_type' => 'sales'])
        ->assertSessionHasErrors(['file' => $message]);

    expect(asTenant($org, fn () => Import::query()->count()))->toBe(0);
})->with([
    'not a spreadsheet' => fn () => [UploadedFile::fake()->create('report.pdf', 10, 'application/pdf'), 'Upload a CSV or Excel (.xlsx) file.'],
    'header only' => fn () => [UploadedFile::fake()->createWithContent('empty.csv', "Order No,Date\n"), 'The file needs a header row and at least one data row.'],
]);

it('enforces the upload size limit', function () {
    config(['orbit.imports.max_upload_kb' => 1]);
    [$user, $org] = memberOf(Role::Analyst);

    $this->actingAs($user)
        ->post(route('imports.store', ['organization' => $org->slug]), [
            'file' => UploadedFile::fake()->create('big.csv', 5, 'text/csv'),
            'dataset_type' => 'sales',
        ])
        ->assertSessionHasErrors('file');
});

it('requires every required field to be mapped before starting', function () {
    [$user, $org] = memberOf(Role::Analyst);
    seedCatalog($org);

    $this->actingAs($user)->post(route('imports.store', ['organization' => $org->slug]), [
        'file' => csvFile('s.csv', [SALES_HEADER, ['INV-1', '2026-11-01', 'LHR', 'HA-100', '1', '100', '0']]),
        'dataset_type' => 'sales',
    ]);
    $import = asTenant($org, fn () => Import::query()->sole());

    $this->actingAs($user)
        ->post(route('imports.confirm', ['organization' => $org->slug, 'import' => $import->id]), [
            'mapping' => ['reference' => 'Order No', 'occurred_at' => 'Order Date', 'branch' => 'Branch', 'sku' => 'SKU', 'quantity' => null, 'unit_price' => 'Not A Column'],
            'date_format' => 'Y-m-d',
        ])
        ->assertSessionHasErrors(['mapping.quantity', 'mapping.unit_price']);

    expect(asTenant($org, fn () => $import->fresh()->status))->toBe(ImportStatus::Draft);
});

it('rejects bad rows individually and reports why (partial failure)', function () {
    [$user, $org] = memberOf(Role::Analyst);
    seedCatalog($org);

    $import = runImport($this, $user, $org, csvFile('mixed.csv', [
        SALES_HEADER,
        ['INV-1', '2026-11-01', 'LHR', 'HA-100', '1', '18500', '0'],   // ok
        ['INV-2', '31/02/2026', 'LHR', 'HA-100', '1', '18500', '0'],  // impossible date
        ['INV-3', '2026-11-01', 'LHR', 'HA-100', 'two', '18500', '0'], // not a number
        ['INV-4', '2026-11-01', 'ISB', 'HA-100', '1', '18500', '0'],   // unknown branch
        ['INV-5', '2026-11-01', 'LHR', 'NOPE-1', '1', '18500', '0'],   // unknown SKU
        ['', '2026-11-01', 'LHR', 'HA-100', '1', '18500', '0'],        // missing reference
        ['INV-6', '2026-11-01', 'LHR', 'HA-200', '1', '100', '500'],   // discount > amount
        ['INV-7', '2026-11-01', 'KHI', 'HA-200', '-2', '6200', '0'],   // negative quantity
    ]), 'sales', dateFormat: 'Y-m-d');

    expect($import->status)->toBe(ImportStatus::CompletedWithErrors)
        ->and($import->imported_rows)->toBe(1)
        ->and($import->failed_rows)->toBe(7);

    $codes = asTenant($org, fn () => $import->errors()->orderBy('row_number')->pluck('code', 'row_number')->all());
    expect($codes)->toBe([
        3 => 'invalid_date',
        4 => 'invalid_number',
        5 => 'unknown_reference',
        6 => 'unknown_reference',
        7 => 'required',
        8 => 'out_of_range',
        9 => 'out_of_range',
    ]);
});

it('downloads failed rows as CSV, neutralizing formulas', function () {
    [$user, $org] = memberOf(Role::Analyst);
    seedCatalog($org);

    $import = runImport($this, $user, $org, csvFile('bad.csv', [
        SALES_HEADER,
        ['INV-1', '2026-11-01', 'LHR', 'HA-100', '1', '18500', '0'],
        ['=HYPERLINK("x")', '2026-11-01', 'XXX', 'HA-100', '1', '18500', '0'],
    ]), 'sales');

    $csv = $this->actingAs($user)
        ->get(route('imports.errors', ['organization' => $org->slug, 'import' => $import->id]))
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8')
        ->streamedContent();

    expect($csv)->toStartWith("\xEF\xBB\xBF")
        ->toContain('Row,Field,"Error code",Message,"Order No"')
        ->toContain('unknown_reference')
        ->toContain("'=HYPERLINK");
});

it('detects duplicates within a file and across imports', function () {
    [$user, $org] = memberOf(Role::Analyst);
    seedCatalog($org);
    $rows = [SALES_HEADER, ['INV-1', '2026-11-01', 'LHR', 'HA-100', '1', '18500', '0'], ['INV-1', '2026-11-01', 'LHR', 'HA-100', '1', '18500', '0']];

    $first = runImport($this, $user, $org, csvFile('a.csv', $rows), 'sales');
    expect($first->imported_rows)->toBe(1)->and($first->duplicate_rows)->toBe(1)
        ->and($first->status)->toBe(ImportStatus::CompletedWithErrors);

    $again = runImport($this, $user, $org, csvFile('a-again.csv', $rows), 'sales');
    expect($again->imported_rows)->toBe(0)
        ->and($again->duplicate_rows)->toBe(2)
        ->and($again->status)->toBe(ImportStatus::Failed);

    expect(asTenant($org, fn () => SalesTransactionItem::query()->count()))->toBe(1);
});

it('is idempotent when a job is retried after partial progress', function () {
    [$user, $org] = memberOf(Role::Analyst);
    seedCatalog($org);

    $import = runImport($this, $user, $org, csvFile('r.csv', [
        SALES_HEADER,
        ['INV-1', '2026-11-01', 'LHR', 'HA-100', '1', '18500', '0'],
        ['INV-2', '2026-11-01', 'LHR', 'HA-200', '1', '6200', '0'],
    ]), 'sales');

    // Simulate a worker that crashed after committing the first row.
    asTenant($org, function () use ($import) {
        $import->update(['status' => ImportStatus::Processing, 'processed_rows' => 1, 'imported_rows' => 1]);
        app(ImportProcessor::class)->process($import->fresh());

        expect(SalesTransactionItem::query()->count())->toBe(2)
            ->and($import->fresh()->processed_rows)->toBe(2);
    });
});

it('auto-detects day-first dates', function () {
    [$user, $org] = memberOf(Role::Analyst);
    seedCatalog($org);

    $this->actingAs($user)->post(route('imports.store', ['organization' => $org->slug]), [
        'file' => csvFile('pk.csv', [SALES_HEADER, ['INV-1', '25/10/2026', 'LHR', 'HA-100', '1', '1', '0'], ['INV-2', '03/11/2026', 'LHR', 'HA-100', '1', '1', '0']]),
        'dataset_type' => 'sales',
    ]);
    $import = asTenant($org, fn () => Import::query()->sole());

    $this->actingAs($user)
        ->get(route('imports.mapping', ['organization' => $org->slug, 'import' => $import->id]))
        ->assertInertia(fn ($page) => $page
            ->component('imports/Mapping')
            ->where('dateFormat', 'd/m/Y')
            ->where('mapping.reference', 'Order No')
            ->where('mapping.occurred_at', 'Order Date')
            ->where('mapping.quantity', 'Qty')
            ->has('sample', 2));
});

describe('access control', function () {
    it('lets analysts import but not viewers', function () {
        [$viewer, $org] = memberOf(Role::Viewer);

        $this->actingAs($viewer)->get(route('imports.create', ['organization' => $org->slug]))->assertForbidden();
        $this->actingAs($viewer)->get(route('imports.index', ['organization' => $org->slug]))->assertForbidden();
        $this->actingAs($viewer)
            ->post(route('imports.store', ['organization' => $org->slug]), ['file' => csvFile('x.csv', [['a'], ['b']]), 'dataset_type' => 'sales'])
            ->assertForbidden();
    });

    it('hides one organization\'s imports from another', function () {
        [$owner, $orgA] = memberOf(Role::Owner);
        seedCatalog($orgA);
        $import = runImport($this, $owner, $orgA, csvFile('a.csv', [SALES_HEADER, ['INV-1', '2026-11-01', 'XXX', 'HA-100', '1', '1', '0'], ['INV-2', '2026-11-01', 'LHR', 'HA-100', '1', '1', '0']]), 'sales');

        [$intruder, $orgB] = memberOf(Role::Owner);
        $params = ['organization' => $orgB->slug, 'import' => $import->id];

        $this->actingAs($intruder)->get(route('imports.show', $params))->assertNotFound();
        $this->actingAs($intruder)->get(route('imports.mapping', $params))->assertNotFound();
        $this->actingAs($intruder)->get(route('imports.errors', $params))->assertNotFound();
        $this->actingAs($intruder)->post(route('imports.confirm', $params), ['mapping' => [], 'date_format' => 'Y-m-d'])->assertNotFound();

        $this->actingAs($intruder)
            ->get(route('imports.index', ['organization' => $orgB->slug]))
            ->assertInertia(fn ($page) => $page->where('imports.meta.total', 0));
    });

    it('refuses to attach an upload to another organization\'s source', function () {
        [$ownerA, $orgA] = memberOf(Role::Owner);
        $foreignSource = asTenant($orgA, fn () => DataSource::query()->create(['name' => 'POS', 'type' => 'csv', 'status' => 'active']));
        [$ownerB, $orgB] = memberOf(Role::Owner);

        $this->actingAs($ownerB)
            ->post(route('imports.store', ['organization' => $orgB->slug]), [
                'file' => csvFile('x.csv', [SALES_HEADER, ['INV-1', '2026-11-01', 'LHR', 'HA-100', '1', '1', '0']]),
                'dataset_type' => 'sales',
                'data_source_id' => $foreignSource->id,
            ])
            ->assertSessionHasErrors('data_source_id');
    });
});
