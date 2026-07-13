<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The billable-event ledger (charges-payouts-data-model.md): one row per
 * code usage, whatever the amount — training/complimentary/lead usages log
 * $0 charges because that's today's business reality, not a mechanism rule.
 * A repeat usage of the same order + order_type logs a $0 charge pointing
 * at the original via original_charge_id — that structure is what makes a
 * royalty due exactly once per order. Lead→sale conversion is never stored:
 * reporting infers it from an external_order_id carrying both a lead charge
 * and a later sale charge.
 */
class Charge extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function usageEvent(): BelongsTo
    {
        return $this->belongsTo(UsageEvent::class);
    }

    public function accessCode(): BelongsTo
    {
        return $this->belongsTo(AccessCode::class);
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function original(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_charge_id');
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class);
    }
}
