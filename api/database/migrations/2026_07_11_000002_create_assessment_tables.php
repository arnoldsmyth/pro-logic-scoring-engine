<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assessment intake + scored results (docs/05). Tools arrive incrementally
 * (one row per tool, replaced on resubmit); every scored result records the
 * norm set used (docs/06 reproducibility contract). Strings-format bodies
 * are rendered on demand from stored inputs — never persisted twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessments', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('api_key_id')->constrained()->cascadeOnDelete();
            $table->string('external_id')->nullable();
            $table->string('firstname');
            $table->string('middlename')->nullable();
            $table->string('lastname');
            $table->string('email');
            $table->string('language', 8);
            $table->char('gender', 1)->nullable(); // M / F; norms fall back to pooled when absent
            $table->date('dob')->nullable(); // never used in scoring
            $table->timestamps();
            $table->index(['api_key_id', 'external_id']);
        });

        Schema::create('assessment_tools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->string('tool', 32);
            $table->json('responses'); // {q: a} exactly as submitted, post-validation
            $table->timestamp('submitted_at');
            $table->unique(['assessment_id', 'tool']);
        });

        Schema::create('scored_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->json('scopes');
            $table->string('norm_set', 64); // docs/06: recorded forever, nothing silently rescores
            $table->string('product_code', 32);
            $table->foreignId('access_code_id')->nullable()->constrained()->nullOnDelete();
            $table->string('language', 8);
            $table->json('results'); // keys-format body filtered to scopes
            $table->json('audit')->nullable(); // docs/03 audit trace, when requested
            $table->timestamp('scored_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scored_results');
        Schema::dropIfExists('assessment_tools');
        Schema::dropIfExists('assessments');
    }
};
