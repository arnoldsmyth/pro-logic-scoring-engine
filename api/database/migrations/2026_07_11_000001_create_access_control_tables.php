<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Access control + royalty metering tables (docs/07). A code is an opaque
 * identifier mapping to a ProductCatalog entry; royalty terms hang off the
 * code (many per code); fees are computed per usage event from the terms
 * active at event time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->enum('type', ['training', 'bizdev', 'derivative']);
            $table->string('product_code', 32); // ProductCatalog entry this code scores
            $table->json('allowed_scopes');
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('uses_count')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->string('issued_to')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->string('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('royalty_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('access_code_id')->constrained()->cascadeOnDelete();
            $table->string('recipient');
            $table->enum('kind', ['flat_per_report', 'percentage_of_price', 'tiered', 'subscription']);
            $table->decimal('amount', 10, 4);
            $table->char('currency', 3)->default('USD');
            $table->boolean('active')->default(true);
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_until')->nullable();
            $table->timestamps();
        });

        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('key_hash', 64)->unique(); // sha256 of the bearer token
            $table->string('key_prefix', 12); // display-only fragment
            $table->unsignedInteger('rate_limit_per_minute')->default(60);
            $table->foreignId('default_access_code_id')->nullable()->constrained('access_codes')->nullOnDelete();
            $table->string('webhook_url')->nullable();
            $table->string('webhook_secret')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
        Schema::dropIfExists('royalty_terms');
        Schema::dropIfExists('access_codes');
    }
};
