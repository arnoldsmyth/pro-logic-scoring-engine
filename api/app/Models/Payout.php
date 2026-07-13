<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The stakeholder ledger (charges-payouts-data-model.md): money going out,
 * always tied back to a Charge, categorized royalty | fee | residual with a
 * specific payout_type (pro_d_royalty, tech_fee, language_fee,
 * residual_margin, …). A charge's payouts sum to exactly its amount — the
 * residual line absorbs the balance. Status supports the aging report:
 * accrued → paid (or void).
 */
class Payout extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class);
    }

    public function payoutTerm(): BelongsTo
    {
        return $this->belongsTo(PayoutTerm::class);
    }
}
