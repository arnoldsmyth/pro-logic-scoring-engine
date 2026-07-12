<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoyaltyTerm extends Model
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

    /**
     * Whether this term has ever produced a fee (appeared in some
     * usage_event's fees_due). A charged term is locked against amount/
     * recipient/kind/currency/language edits (prolog-jzy: rewriting a term
     * that already billed real usage would corrupt historical royalty
     * statements) — the only path forward is end() + a new term.
     */
    public function hasBeenCharged(): bool
    {
        return UsageEvent::query()
            ->where('access_code_id', $this->access_code_id)
            ->get(['fees_due'])
            ->contains(fn (UsageEvent $e) => collect($e->fees_due)->contains('royalty_term_id', $this->id));
    }
}
