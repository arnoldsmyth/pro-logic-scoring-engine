<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time remediation: logins previously always set remember:true
 * (AuthController), issuing long-lived remember-me cookies regardless of
 * SESSION_LIFETIME. Clearing stored tokens invalidates any already-issued
 * cookies immediately so the idle-timeout fix actually takes effect.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->update(['remember_token' => null]);
    }

    public function down(): void
    {
        // Irreversible by design — old tokens are gone.
    }
};
