<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One line of a code's payout schedule (charges-payouts-data-model.md):
 * who gets paid what out of the code's charge. Categories: royalty | fee |
 * residual — the residual line absorbs charge − sum(other lines), so the
 * schedule always balances to the charge. Kinds: flat | percent_of_charge
 * (residual ignores kind/amount). Language-scoped lines (e.g. a
 * language_fee) only fire when the scoring event's language matches.
 */
class PayoutTerm extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'effective_from' => 'datetime',
            'effective_until' => 'datetime',
        ];
    }

    public function accessCode(): BelongsTo
    {
        return $this->belongsTo(AccessCode::class);
    }

    public function payee(): BelongsTo
    {
        return $this->belongsTo(Payee::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class);
    }

    /**
     * A term that has produced a real payout is locked against edits —
     * changing it would rewrite amounts already accrued to a stakeholder.
     * End it and add a new line instead.
     */
    public function hasBeenCharged(): bool
    {
        return $this->payouts()->exists();
    }
}
