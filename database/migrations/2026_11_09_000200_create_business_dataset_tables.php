<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Normalized analytics model (docs/DATABASE_DESIGN.md §5).
 * Facts carry business_date (organization timezone) for index-friendly
 * range filters, plus lineage (import_id, data_source_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        $lineage = function (Blueprint $table): void {
            $table->foreignId('import_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('data_source_id')->nullable()->constrained()->nullOnDelete();
        };

        // ── Dimensions ──────────────────────────────────────────────────
        Schema::create('branches', function (Blueprint $table) use ($lineage) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name', 120);
            $table->string('city', 80)->nullable();
            $table->date('opened_on')->nullable();
            $table->boolean('is_active')->default(true);
            $lineage($table);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
        });

        Schema::create('departments', function (Blueprint $table) use ($lineage) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name', 120);
            $lineage($table);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
        });

        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });

        Schema::create('products', function (Blueprint $table) use ($lineage) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_category_id')->constrained()->restrictOnDelete();
            $table->string('sku', 64);
            $table->string('name', 200);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('unit_cost', 15, 2);
            $table->boolean('is_active')->default(true);
            $lineage($table);
            $table->timestamps();

            $table->unique(['organization_id', 'sku']);
            $table->index(['organization_id', 'product_category_id']);
        });

        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('kind', 16)->default('operating');
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });

        // ── Facts ───────────────────────────────────────────────────────
        Schema::create('sales_transactions', function (Blueprint $table) use ($lineage) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('reference', 64);
            $table->dateTime('occurred_at');
            $table->date('business_date');
            $table->string('status', 16)->default('completed');
            $table->string('channel', 16)->default('store');
            $table->string('customer_segment', 32)->nullable();
            $table->unsignedInteger('items_count')->default(0);
            $table->decimal('gross_amount', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('net_amount', 15, 2)->default(0);
            $lineage($table);
            $table->timestamps();

            $table->unique(['organization_id', 'reference']);
            $table->index(['organization_id', 'business_date', 'status']);
            $table->index(['organization_id', 'branch_id', 'business_date']);
        });

        Schema::create('sales_transaction_items', function (Blueprint $table) use ($lineage) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            // Denormalized from the header: product/category aggregates are
            // the hottest queries and avoid a join on date/branch filters.
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->date('business_date');
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('unit_cost', 15, 2);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('net_amount', 15, 2);
            $table->char('natural_key_hash', 40);
            $lineage($table);

            $table->unique(['organization_id', 'natural_key_hash']);
            $table->index(['organization_id', 'business_date', 'product_id']);
            $table->index(['organization_id', 'branch_id', 'business_date']);
            $table->index('sales_transaction_id');
        });

        Schema::create('expenses', function (Blueprint $table) use ($lineage) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('business_date');
            $table->decimal('amount', 15, 2);
            $table->string('reference', 64)->nullable();
            $table->string('vendor', 120)->nullable();
            $table->string('description', 255)->nullable();
            $table->char('natural_key_hash', 40);
            $lineage($table);
            $table->timestamps();

            $table->unique(['organization_id', 'natural_key_hash']);
            $table->index(['organization_id', 'business_date']);
            $table->index(['organization_id', 'expense_category_id', 'business_date']);
        });

        Schema::create('inventory_records', function (Blueprint $table) use ($lineage) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->date('recorded_on');
            $table->decimal('quantity_on_hand', 12, 3);
            $table->decimal('reorder_level', 12, 3)->default(0);
            $table->decimal('unit_cost', 15, 2);
            $lineage($table);
            $table->timestamps();

            $table->unique(['organization_id', 'product_id', 'branch_id', 'recorded_on'], 'inventory_snapshot_unique');
            $table->index(['organization_id', 'recorded_on']);
        });

        Schema::create('employees', function (Blueprint $table) use ($lineage) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('employee_code', 32);
            $table->string('full_name', 120);
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('job_title', 120);
            $table->string('employment_type', 16)->default('full_time');
            $table->date('hired_on');
            $table->date('terminated_on')->nullable();
            $table->decimal('monthly_salary', 15, 2)->nullable();
            $lineage($table);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'employee_code']);
            $table->index(['organization_id', 'department_id']);
            $table->index(['organization_id', 'hired_on']);
        });

        Schema::create('revenue_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->date('month');
            $table->decimal('amount', 15, 2);
            $table->timestamps();

            $table->unique(['organization_id', 'branch_id', 'month']);
        });
    }

    public function down(): void
    {
        foreach (['revenue_targets', 'employees', 'inventory_records', 'expenses', 'sales_transaction_items',
            'sales_transactions', 'expense_categories', 'products', 'product_categories', 'departments', 'branches'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
