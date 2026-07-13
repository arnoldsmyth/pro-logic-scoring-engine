<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Who we pay (prolog-opw.1): royalty recipients, translators, partners —
 * referenced by payout schedule lines. Separate from Client (who pays us);
 * the optional client_id link covers the party that is both.
 */
class Payee extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function payoutTerms(): HasMany
    {
        return $this->hasMany(PayoutTerm::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class);
    }
}
