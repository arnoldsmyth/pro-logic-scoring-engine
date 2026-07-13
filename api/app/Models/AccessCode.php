<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AccessCode extends Model
{
    protected $guarded = [];

    protected $attributes = [
        'order_type' => 'training',
        'charge_amount' => 0,
        'charge_currency' => 'USD',
    ];

    protected function casts(): array
    {
        return [
            'allowed_scopes' => 'array',
            'active' => 'boolean',
            'expires_at' => 'datetime',
            'charge_amount' => 'decimal:4',
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

    public function usageEvents(): HasMany
    {
        return $this->hasMany(UsageEvent::class);
    }

    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class);
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
     * Once a code has been used to score anything, its `order_type` and
     * `allowed_scopes` are locked (prolog-jzy: editing either would
     * retroactively misrepresent what earlier scoring calls were actually
     * permitted to do, and order_type drives conversion reporting).
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
     * Record the Charge (+ Payouts) for one usage of this code
     * (charges-payouts-data-model.md). Every usage logs a charge:
     *
     * - The FIRST usage for a given order (external_order_id when the caller
     *   supplies one, otherwise this assessment) + order_type charges the
     *   code's configured amount and splits it into payouts.
     * - Repeats log a $0 charge referencing the original — a royalty is due
     *   exactly once per order, structurally, with the trail showing why.
     * - Payout lines: language-scoped lines fire only on matching-language
     *   events; flat lines pay their amount; percent_of_charge lines pay
     *   amount% of the charge; the residual line absorbs the remainder so
     *   the schedule always balances to the charge (a negative residual is
     *   recorded as-is — a visible misconfiguration, never hidden).
     * - A $0 charge produces no payout rows (nothing to split).
     */
    public function recordCharge(UsageEvent $event, Assessment $assessment, ?string $language): Charge
    {
        $orderKey = $assessment->external_id !== null && $assessment->external_id !== ''
            ? $assessment->external_id
            : null;

        $original = Charge::query()
            ->where('api_key_id', $event->api_key_id)
            ->where('order_type', $this->order_type)
            ->whereNull('original_charge_id')
            ->where('amount', '>', 0)
            ->when(
                $orderKey !== null,
                fn ($q) => $q->where('external_order_id', $orderKey),
                fn ($q) => $q->where('assessment_id', $assessment->id),
            )
            ->first();

        $amount = $original === null ? (float) $this->charge_amount : 0.0;

        $charge = Charge::create([
            'usage_event_id' => $event->id,
            'access_code_id' => $this->id,
            'api_key_id' => $event->api_key_id,
            'assessment_id' => $assessment->id,
            'external_order_id' => $orderKey,
            'order_type' => $this->order_type,
            'product_code' => $this->product_code,
            'amount' => $amount,
            'currency' => $this->charge_currency,
            'original_charge_id' => $original?->id,
            'created_at' => now(),
        ]);

        if ($amount > 0) {
            foreach ($this->computePayouts($amount, $language) as $line) {
                $charge->payouts()->create([...$line, 'status' => 'accrued', 'created_at' => now()]);
            }
        }

        return $charge;
    }

    /**
     * Split a charge amount across this code's active payout schedule.
     *
     * @return list<array{payout_term_id: int, recipient: string, category: string, payout_type: ?string, amount: float, currency: string, language: ?string}>
     */
    public function computePayouts(float $chargeAmount, ?string $language): array
    {
        $terms = $this->payoutTerms()
            ->where('active', true)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', now()))
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', now()))
            ->when($language !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('language')->orWhere('language', $language)))
            ->with('payee')
            ->get();

        $lines = [];
        $allocated = 0.0;
        foreach ($terms->where('category', '!=', 'residual') as $term) {
            $amount = $term->kind === 'percent_of_charge'
                ? round($chargeAmount * (float) $term->amount / 100, 4)
                : (float) $term->amount;
            $allocated += $amount;
            $lines[] = $this->line($term, $amount);
        }
        foreach ($terms->where('category', 'residual') as $term) {
            // Balance catch-all: whatever the itemized lines left over.
            $lines[] = $this->line($term, round($chargeAmount - $allocated, 4));
        }

        return $lines;
    }

    /**
     * recipient is a snapshot of the payee's name at charge time — the
     * ledger stays readable even if the payee record is renamed later.
     *
     * @return array{payout_term_id: int, payee_id: ?int, recipient: string, category: string, payout_type: ?string, amount: float, currency: string, language: ?string}
     */
    private function line(PayoutTerm $term, float $amount): array
    {
        return [
            'payout_term_id' => $term->id,
            'payee_id' => $term->payee_id,
            'recipient' => $term->payee?->name ?? '(unknown payee)',
            'category' => $term->category,
            'payout_type' => $term->payout_type,
            'amount' => $amount,
            'currency' => $term->currency,
            'language' => $term->language,
        ];
    }
}
