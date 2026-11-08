<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('type', 16);
            $table->string('description', 500)->nullable();
            $table->string('status', 16)->default('pending');
            // Non-secret configuration only (host, port, database, table, url…).
            $table->json('config')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->text('last_error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status']);
        });

        // Secrets live apart from data_sources so a DataSource can never
        // serialize them by accident. `secret` is Laravel-encrypted JSON.
        Schema::create('data_source_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_source_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('secret');
            $table->timestamp('rotated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('data_source_syncs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('data_source_id')->constrained()->cascadeOnDelete();
            $table->string('trigger', 16)->default('manual');
            $table->string('status', 24);
            $table->unsignedInteger('rows_read')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['data_source_id', 'created_at']);
        });

        Schema::create('imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('data_source_id')->nullable()->constrained()->nullOnDelete();
            $table->string('dataset_type', 16);
            $table->string('original_filename');
            $table->string('disk', 32);
            $table->string('path');
            $table->unsignedBigInteger('size_bytes');
            $table->char('checksum_sha256', 64);
            $table->string('status', 24)->default('draft');
            $table->json('headers');
            $table->json('options')->nullable();
            $table->unsignedInteger('total_rows')->nullable();
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->unsignedInteger('duplicate_rows')->default(0);
            $table->text('failure_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('import_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained()->cascadeOnDelete();
            $table->string('source_column');
            $table->string('target_field', 64);
            $table->timestamps();

            $table->unique(['import_id', 'target_field']);
        });

        Schema::create('import_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('field', 64)->nullable();
            $table->string('code', 32);
            $table->string('message', 500);
            $table->json('raw')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['import_id', 'row_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_errors');
        Schema::dropIfExists('import_mappings');
        Schema::dropIfExists('imports');
        Schema::dropIfExists('data_source_syncs');
        Schema::dropIfExists('data_source_credentials');
        Schema::dropIfExists('data_sources');
    }
};
