<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versioned norm sets (docs/06): norms are data, never code. A set is a
 * complete raw→normed conversion table for the PZSD scales plus provenance;
 * scored results reference the set they used forever. norm_samples is the
 * anonymized accumulation layer that feeds candidate-set derivation —
 * aggregate counts only, no PII, no per-respondent rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('norm_sets', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique(); // what results record + the norms= param accepts
            $table->string('status', 16)->default('candidate'); // candidate | active | retired
            $table->string('language', 8)->nullable(); // null = all languages (legacy sets)
            $table->char('gender', 1)->nullable(); // M | F | null = pooled
            $table->boolean('provisional')->default(false); // stand-in outside its population; flags results
            $table->text('description')->nullable();
            $table->json('provenance'); // n per scale, composition, source range, created_from
            $table->json('impact')->nullable(); // side-by-side report vs the set it would replace
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
        });

        Schema::create('norm_set_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('norm_set_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('tool_scale_detail_key');
            $table->decimal('raw', 12, 6);
            $table->decimal('normed', 12, 6);
            $table->unique(['norm_set_id', 'tool_scale_detail_key', 'raw'], 'norm_entries_unique');
        });

        Schema::create('norm_samples', function (Blueprint $table) {
            $table->id();
            $table->string('language', 8);
            $table->char('gender', 1)->nullable(); // null = respondent gave no gender
            $table->unsignedInteger('tool_scale_detail_key');
            $table->decimal('raw', 12, 6);
            $table->unsignedInteger('count')->default(0);
            $table->unique(['language', 'gender', 'tool_scale_detail_key', 'raw'], 'norm_samples_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('norm_samples');
        Schema::dropIfExists('norm_set_entries');
        Schema::dropIfExists('norm_sets');
    }
};
