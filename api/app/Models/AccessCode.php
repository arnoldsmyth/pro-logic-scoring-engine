<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AccessCode extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'allowed_scopes' => 'array',
            'active' => 'boolean',
            'expires_at' => 'datetime',
        ];
    }

    public function royaltyTerms(): HasMany
    {
        return $this->hasMany(RoyaltyTerm::class);
    }

    public function usageEvents(): HasMany
    {
        return $this->hasMany(UsageEvent::class);
    }

    /** Unguessable opaque code — never a product brand name (docs/07). */
    public static function generateCode(): string
    {
        return 'ac_'.Str::random(24);
    }

    public function isUsable(): bool
    {
        return $this->active
            && ($this->expires_at === null || $this->expires_at->isFuture())
            && ($this->max_uses === null || $this->uses_count < $this->max_uses);
    }

    /**
     * Royalty terms in force right now — one fees_due row per term
     * (docs/07: a code can pay more than one party, evaluated independently).
     *
     * @return list<array{royalty_term_id: int, recipient: string, kind: string, amount: string, currency: string}>
     */
    public function feesDueNow(): array
    {
        return $this->royaltyTerms()
            ->where('active', true)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', now()))
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', now()))
            ->get()
            ->map(fn (RoyaltyTerm $t) => [
                'royalty_term_id' => $t->id,
                'recipient' => $t->recipient,
                'kind' => $t->kind,
                'amount' => (string) $t->amount,
                'currency' => $t->currency,
            ])
            ->all();
    }
}
