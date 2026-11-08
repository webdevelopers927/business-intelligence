<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Custom metrics only — base metrics are defined in code (D-05).
        Schema::create('metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('key', 48);
            $table->string('name', 80);
            $table->string('description', 255)->nullable();
            $table->string('formula', 300);
            $table->json('dependencies');
            $table->string('format', 16);
            $table->unsignedTinyInteger('decimals')->default(1);
            $table->string('polarity', 16)->default('up_good');
            $table->string('visibility', 16)->default('organization');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'key']);
        });

        Schema::create('dashboards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 80);
            $table->string('description', 255)->nullable();
            $table->string('visibility', 16)->default('private');
            $table->json('default_filters')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'visibility']);
            $table->index(['organization_id', 'owner_id']);
        });

        Schema::create('dashboard_widgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dashboard_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->string('title', 80);
            // Presentation config (metric, dimension, range mode, filters,
            // options) — validated by WidgetConfig, not business data.
            $table->json('config');
            $table->unsignedSmallInteger('x')->default(0);
            $table->unsignedSmallInteger('y')->default(0);
            $table->unsignedSmallInteger('w')->default(4);
            $table->unsignedSmallInteger('h')->default(4);
            $table->timestamps();

            $table->index('dashboard_id');
        });

        Schema::create('dashboard_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dashboard_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('permission', 8)->default('view');
            $table->timestamps();

            $table->unique(['dashboard_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_shares');
        Schema::dropIfExists('dashboard_widgets');
        Schema::dropIfExists('dashboards');
        Schema::dropIfExists('metrics');
    }
};
