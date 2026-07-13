<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Charges & payouts model (development-assets/charges-payouts-data-model.md,
 * agreed 2026-07-12):
 *
 * - `order_type` (training|complimentary|lead|sale) replaces the old `type`
 *   tag. Order types never gate the mechanism — every usage logs a Charge;
 *   training/complimentary/lead simply resolve to $0 today.
 * - A code carries its charge (what the client owes per order) and a payout
 *   schedule (renamed from royalty_terms): category royalty|fee|residual,
 *   payout_type (pro_d_royalty, tech_fee, language_fee, residual_margin, …).
 *   Payouts must sum to the charge — the residual term absorbs the balance.
 * - `charges` is the billable-event ledger: one row per code usage. A repeat
 *   usage of the same order+order_type logs a $0 charge referencing the
 *   original — royalty due exactly once per order, structurally.
 * - `payouts` is the stakeholder ledger (status accrued|paid|void), one row
 *   per payout line per real charge. Statements report from these two
 *   tables, never recomputed.
 * - Lead→sale conversion is never stored; reporting infers it from an
 *   external_order_id having both a lead and a later sale charge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('access_codes', function (Blueprint $table) {
            $table->string('order_type', 16)->default('training')->after('name');
            $table->decimal('charge_amount', 12, 4)->default(0)->after('order_type');
            $table->char('charge_currency', 3)->default('USD')->after('charge_amount');
        });
        // Old `type` → order_type: bizdev was complimentary partner
        // enablement; historical 'derivative' labeled commercial derivative-
        // product usage (the product axis lives on product_code now) → sale.
        DB::table('access_codes')->where('type', 'training')->update(['order_type' => 'training']);
        DB::table('access_codes')->where('type', 'bizdev')->update(['order_type' => 'complimentary']);
        DB::table('access_codes')->where('type', 'derivative')->update(['order_type' => 'sale']);
        Schema::table('access_codes', function (Blueprint $table) {
            $table->dropColumn('type');
        });

        Schema::rename('royalty_terms', 'payout_terms');
        Schema::table('payout_terms', function (Blueprint $table) {
            $table->string('category', 16)->default('royalty')->after('recipient'); // royalty | fee | residual
            $table->string('payout_type', 32)->nullable()->after('category'); // pro_d_royalty, tech_fee, language_fee, residual_margin, …
        });
        // Old kinds collapse to flat | percent_of_charge; the residual is a
        // category now, not a kind. flat_on_conversion's once-only behavior
        // is superseded by per-order charge dedup.
        DB::table('payout_terms')->whereIn('kind', ['flat_per_report', 'flat_on_conversion', 'tiered', 'subscription'])->update(['kind' => 'flat']);
        DB::table('payout_terms')->where('kind', 'percentage_of_price')->update(['kind' => 'percent_of_charge']);

        Schema::create('charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usage_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('access_code_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('api_key_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->string('external_order_id')->nullable(); // client's order key; conversion joins on this
            $table->string('order_type', 16);
            $table->string('product_code', 32);
            $table->decimal('amount', 12, 4);
            $table->char('currency', 3);
            $table->foreignId('original_charge_id')->nullable()->constrained('charges')->nullOnDelete(); // set on $0 repeats
            $table->timestamp('created_at');
            $table->index(['api_key_id', 'external_order_id']);
            $table->index(['order_type', 'created_at']);
        });

        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('charge_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payout_term_id')->nullable()->constrained('payout_terms')->nullOnDelete();
            $table->string('recipient');
            $table->string('category', 16); // royalty | fee | residual
            $table->string('payout_type', 32)->nullable();
            $table->decimal('amount', 12, 4);
            $table->char('currency', 3);
            $table->string('language', 8)->nullable();
            $table->string('status', 16)->default('accrued'); // accrued | paid | void
            $table->timestamp('created_at');
            $table->index(['recipient', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payouts');
        Schema::dropIfExists('charges');
        Schema::table('payout_terms', function (Blueprint $table) {
            $table->dropColumn(['category', 'payout_type']);
        });
        Schema::rename('payout_terms', 'royalty_terms');
        Schema::table('access_codes', function (Blueprint $table) {
            $table->string('type', 16)->default('training');
            $table->dropColumn(['order_type', 'charge_amount', 'charge_currency']);
        });
    }
};
