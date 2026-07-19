<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payout status transitions (prolog-9x0): the payouts ledger is never
 * edited or deleted — status moves one-way accrued -> paid | void, and we
 * record when + who + why. paid_at/voided_at/void_reason/transitioned_by
 * are write-once: PayoutsController only ever sets them from the accrued
 * state (see the 422 guard in pay()/voidPayout()), never overwrites them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            $table->timestamp('paid_at')->nullable()->after('status');
            $table->timestamp('voided_at')->nullable()->after('paid_at');
            $table->string('void_reason', 500)->nullable()->after('voided_at');
            $table->foreignId('transitioned_by')->nullable()->after('void_reason')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('transitioned_by');
            $table->dropColumn(['paid_at', 'voided_at', 'void_reason']);
        });
    }
};
