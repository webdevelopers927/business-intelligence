<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anomalies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('metric_key', 64);
            $table->date('business_date');
            $table->decimal('actual', 18, 4);
            $table->decimal('expected', 18, 4);
            $table->decimal('lower_bound', 18, 4);
            $table->decimal('upper_bound', 18, 4);
            $table->decimal('z_score', 8, 3);
            $table->string('severity', 16); // warning | critical
            $table->string('baseline', 16); // weekday | trailing
            $table->text('explanation');
            $table->json('details')->nullable();
            $table->timestamp('detected_at');
            $table->timestamps();

            $table->unique(['organization_id', 'metric_key', 'business_date']);
            $table->index(['organization_id', 'business_date']);
        });

        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 160);
            $table->timestamps();

            $table->index(['organization_id', 'user_id', 'updated_at']);
        });

        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_conversation_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16); // user | assistant
            $table->text('content');
            // Assistant turns: the exact API messages of this exchange (assistant
            // blocks + tool results), replayed verbatim on the next question so the
            // conversation history is append-only.
            $table->json('transcript')->nullable();
            $table->json('evidence')->nullable();
            $table->string('stop_reason', 32)->nullable();
            $table->string('model', 64)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->timestamp('created_at')->nullable();

            $table->index(['ai_conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_conversations');
        Schema::dropIfExists('anomalies');
    }
};
