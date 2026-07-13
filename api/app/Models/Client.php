<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Who pays us (prolog-opw.1): the normalized entity API keys and access
 * codes hang off, replacing free-text name fields. Deliberately separate
 * from Payee (who we pay) — the same real-world party can be both via
 * Payee.client_id. stripe_customer_id arrives with billing (opw.2).
 */
class Client extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    public function accessCodes(): HasMany
    {
        return $this->hasMany(AccessCode::class);
    }

    public function payees(): HasMany
    {
        return $this->hasMany(Payee::class);
    }
}
