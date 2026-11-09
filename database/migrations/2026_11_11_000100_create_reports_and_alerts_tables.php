<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('type', 32);
            // {range: {preset, from, to}, filters: {branches, categories}, columns: [], sort: {column, direction}}
            $table->json('parameters');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'type']);
        });

        Schema::create('report_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 32);
            $table->string('name', 160);
            $table->json('parameters');
            $table->string('format', 8);
            $table->string('trigger', 16)->default('manual');
            $table->string('status', 16)->default('queued');
            $table->unsignedInteger('row_count')->nullable();
            $table->string('file_disk', 32)->nullable();
            $table->string('file_path')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
        });

        Schema::create('report_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('frequency', 16);
            $table->unsignedTinyInteger('day_of_week')->nullable();
            $table->unsignedTinyInteger('day_of_month')->nullable();
            $table->string('run_at', 5)->default('07:00');
            $table->string('format', 8)->default('xlsx');
            $table->json('recipients');
            $table->boolean('is_active')->default(true);
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'next_run_at']);
        });

        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('kind', 16);
            $table->string('metric_key', 48)->nullable();
            $table->string('condition', 16)->nullable();
            $table->decimal('threshold', 18, 4)->nullable();
            $table->string('window', 8)->nullable();
            $table->json('filters')->nullable();
            $table->string('frequency', 8)->default('daily');
            $table->json('recipients');
            $table->boolean('is_active')->default(true);
            $table->string('state', 12)->default('ok');
            $table->decimal('last_value', 18, 4)->nullable();
            $table->timestamp('last_evaluated_at')->nullable();
            $table->timestamp('last_triggered_at')->nullable();
            $table->timestamp('next_evaluation_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // Scheduler scan across organizations (inside an explicit bypass).
            $table->index(['is_active', 'next_evaluation_at']);
            $table->index(['organization_id', 'state']);
        });

        Schema::create('alert_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('alert_id')->constrained()->cascadeOnDelete();
            $table->timestamp('evaluated_at');
            $table->string('outcome', 12);
            $table->decimal('observed_value', 18, 4)->nullable();
            $table->decimal('threshold', 18, 4)->nullable();
            $table->boolean('notified')->default(false);
            $table->string('message', 500)->nullable();
            $table->json('details')->nullable();

            $table->index(['alert_id', 'evaluated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_executions');
        Schema::dropIfExists('alerts');
        Schema::dropIfExists('report_schedules');
        Schema::dropIfExists('report_runs');
        Schema::dropIfExists('reports');
    }
};
