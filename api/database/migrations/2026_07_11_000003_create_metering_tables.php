<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Usage metering (docs/07: royalty statements must be producible from
 * usage_events alone) + idempotency replay store (docs/05).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_key_id')->constrained()->cascadeOnDelete();
            $table->foreignId('access_code_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code_type', 16)->nullable(); // denormalized: royalty reporting splits on it
            $table->string('product_code', 32);
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->json('scopes');
            $table->json('fees_due'); // one row per royalty term active at event time
            $table->timestamp('created_at');
            $table->index(['access_code_id', 'created_at']);
            $table->index(['code_type', 'created_at']);
        });

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_key_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key');
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('response_status');
            $table->json('response_body');
            $table->timestamp('created_at');
            $table->unique(['api_key_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('usage_events');
    }
};
