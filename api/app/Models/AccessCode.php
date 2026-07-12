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

    /** Route binding uses the opaque code string, not the numeric id. */
    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /**
     * Once a code has been used to score anything, its `type` and
     * `allowed_scopes` are locked (prolog-jzy: editing either would
     * retroactively misrepresent what earlier scoring calls were actually
     * permitted to do). Metadata fields (name, issued_to, notes, max_uses,
     * expires_at, active) stay editable regardless — issue a new code
     * instead of relaxing this.
     */
    public function scopeAndTypeLocked(): bool
    {
        return $this->uses_count > 0;
    }

    public function isUsable(): bool
    {
        return $this->active
            && ($this->expires_at === null || $this->expires_at->isFuture())
            && ($this->max_uses === null || $this->uses_count < $this->max_uses);
    }

    /**
     * Royalty terms in force for this scoring event — one fees_due row per
     * matching term (docs/07: a code can pay more than one party, evaluated
     * independently). Royalty behavior is driven entirely by these rows;
     * the code's `type` is a descriptive label (decided 2026-07-11).
     *
     * - Language-scoped terms (term.language non-null) only fire when the
     *   event's language matches — e.g. a translator fee on fr reports.
     * - `flat_on_conversion` terms fire the FIRST time this person (same
     *   external_id per key, email fallback) is charged under this term,
     *   then never again — the pay-once-per-order conversion royalty.
     *
     * @return list<array{royalty_term_id: int, recipient: string, kind: string, amount: string, currency: string, language: ?string}>
     */
    public function feesDueNow(?string $language = null, ?Assessment $assessment = null): array
    {
        $terms = $this->royaltyTerms()
            ->where('active', true)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', now()))
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', now()))
            ->when($language !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('language')->orWhere('language', $language)))
            ->get();

        $conversionKinds = $terms->where('kind', 'flat_on_conversion');
        $alreadyCharged = $conversionKinds->isNotEmpty() && $assessment !== null
            ? $this->termsAlreadyChargedForPerson($assessment, $conversionKinds->pluck('id')->all())
            : [];

        return $terms
            ->reject(fn (RoyaltyTerm $t) => $t->kind === 'flat_on_conversion' && in_array($t->id, $alreadyCharged, true))
            ->map(fn (RoyaltyTerm $t) => [
                'royalty_term_id' => $t->id,
                'recipient' => $t->recipient,
                'kind' => $t->kind,
                'amount' => (string) $t->amount,
                'currency' => $t->currency,
                'language' => $t->language,
            ])
            ->values()
            ->all();
    }

    /**
     * Which of the given term ids have already produced a fee for this
     * person, across every assessment linked to them (charged exactly once
     * per person — docs/07 conversion royalty).
     *
     * @param  list<int>  $termIds
     * @return list<int>
     */
    private function termsAlreadyChargedForPerson(Assessment $assessment, array $termIds): array
    {
        $assessmentIds = $assessment->samePersonQuery()->pluck('id');

        $charged = [];
        UsageEvent::query()
            ->whereIn('assessment_id', $assessmentIds)
            ->get(['fees_due'])
            ->each(function (UsageEvent $event) use (&$charged, $termIds) {
                foreach ($event->fees_due as $fee) {
                    if (in_array((int) ($fee['royalty_term_id'] ?? 0), $termIds, true)) {
                        $charged[] = (int) $fee['royalty_term_id'];
                    }
                }
            });

        return array_values(array_unique($charged));
    }
}
