<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Normalized clients + payees (prolog-opw.1, decided 2026-07-12):
 *
 * - `clients` = who pays us (holds API keys and access codes). Replaces the
 *   free-text api_keys.name-as-client and access_codes.issued_to. Carries
 *   stripe_customer_id for the upcoming billing work (opw.2).
 * - `payees` = who we pay via payout schedules (content owners, translators,
 *   partners). Deliberately separate from clients — a payee is often not a
 *   billed client at all. A payee MAY link to a client record (nullable
 *   client_id) when the same real-world party is both.
 * - payout_terms.recipient (free text) → payee_id FK. The payouts LEDGER
 *   keeps its recipient string as an immutable snapshot of the payee's name
 *   at charge time, gaining a payee_id FK for grouping.
 * - api_keys keep their own `name` as a key label (a client can hold
 *   several keys); the owning client is the new client_id FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('billing_email')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->string('stripe_customer_id')->nullable(); // opw.2
            $table->timestamps();
        });

        Schema::create('payees', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('email')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete(); // optional same-party link
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::table('api_keys', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('name')->constrained()->nullOnDelete();
        });
        Schema::table('access_codes', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('issued_to')->constrained()->nullOnDelete();
        });
        Schema::table('payout_terms', function (Blueprint $table) {
            $table->foreignId('payee_id')->nullable()->after('recipient')->constrained()->nullOnDelete();
        });
        Schema::table('payouts', function (Blueprint $table) {
            $table->foreignId('payee_id')->nullable()->after('recipient')->constrained()->nullOnDelete();
        });

        // Backfill: a client per distinct issued_to / key name, a payee per
        // distinct payout-term recipient.
        $now = now();
        foreach (DB::table('access_codes')->whereNotNull('issued_to')->where('issued_to', '!=', '')->distinct()->pluck('issued_to') as $name) {
            $id = DB::table('clients')->insertGetId(['name' => $name, 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('access_codes')->where('issued_to', $name)->update(['client_id' => $id]);
        }
        foreach (DB::table('api_keys')->pluck('name', 'id') as $keyId => $name) {
            $existing = DB::table('clients')->where('name', $name)->value('id');
            $id = $existing ?? DB::table('clients')->insertGetId(['name' => $name, 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('api_keys')->where('id', $keyId)->update(['client_id' => $id]);
        }
        foreach (DB::table('payout_terms')->distinct()->pluck('recipient') as $name) {
            $id = DB::table('payees')->insertGetId(['name' => $name, 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('payout_terms')->where('recipient', $name)->update(['payee_id' => $id]);
            DB::table('payouts')->where('recipient', $name)->update(['payee_id' => $id]);
        }

        // recipient on payout_terms is replaced by the payee relation; the
        // payouts ledger keeps its snapshot string.
        Schema::table('payout_terms', function (Blueprint $table) {
            $table->dropColumn('recipient');
        });
        Schema::table('access_codes', function (Blueprint $table) {
            $table->dropColumn('issued_to');
        });
    }

    public function down(): void
    {
        Schema::table('access_codes', function (Blueprint $table) {
            $table->string('issued_to')->nullable();
            $table->dropConstrainedForeignId('client_id');
        });
        Schema::table('payout_terms', function (Blueprint $table) {
            $table->string('recipient')->default('');
            $table->dropConstrainedForeignId('payee_id');
        });
        Schema::table('payouts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payee_id');
        });
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
        });
        Schema::dropIfExists('payees');
        Schema::dropIfExists('clients');
    }
};
