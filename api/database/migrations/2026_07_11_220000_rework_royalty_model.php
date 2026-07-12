<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Royalty-model rework (prolog-4iu, decided 2026-07-11):
 * - Codes get a free-text display name for royalty reporting (the `code`
 *   value itself stays opaque/unguessable).
 * - Royalty terms can be scoped to a language (translator fees): null
 *   language = applies to every language.
 * - New term kind `flat_on_conversion`: due the first time a person is
 *   scored under the code carrying it, then never again for that person
 *   (free lead-gen -> paid conversion, charged exactly once).
 * - `type` becomes a descriptive label only — royalty behavior is driven
 *   entirely by the presence of active royalty_terms (docs/07).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('access_codes', function (Blueprint $table) {
            $table->string('name')->nullable()->after('code');
        });

        Schema::table('royalty_terms', function (Blueprint $table) {
            $table->string('language', 8)->nullable()->after('currency'); // null = all languages
        });

        // Widen the kind column to plain string: the enum was a design-time
        // list, and 'flat_on_conversion' joins it. Validation lives in the
        // controllers/commands, keeping future kinds a data change.
        Schema::table('royalty_terms', function (Blueprint $table) {
            $table->string('kind', 32)->change();
        });
    }

    public function down(): void
    {
        Schema::table('royalty_terms', function (Blueprint $table) {
            $table->dropColumn('language');
        });
        Schema::table('access_codes', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
