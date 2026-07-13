<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\AccessCode;
use App\Models\Charge;
use App\Models\PayoutTerm;
use App\Scoring\Engine\ProductCatalog;
use App\Scoring\Scopes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Access codes, charges & payouts (docs/07 + charges-payouts-data-model.md).
 * Order type (training | complimentary | lead | sale) is a reporting
 * dimension, never a gate — every usage logs a Charge, and payouts split
 * real charges among stakeholders. Statements report from the charges and
 * payouts ledgers, never recomputed. Lead→sale conversion is inferred at
 * reporting time from shared external_order_ids.
 */
class CodesController extends Controller
{
    private const ORDER_TYPES = 'in:training,complimentary,lead,sale';

    private const PAYOUT_CATEGORIES = 'in:royalty,fee,residual';

    private const PAYOUT_KINDS = 'in:flat,percent_of_charge';

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'codes' => AccessCode::query()
                ->with('client:id,name')
                ->withCount('usageEvents')
                ->when($request->query('q'), fn ($q, $term) => $q->where(fn ($w) => $w
                    ->where('name', 'like', "%{$term}%")
                    ->orWhere('code', 'like', "%{$term}%")
                    ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$term}%"))))
                ->when($request->query('order_type'), fn ($q, $t) => $q->where('order_type', $t))
                ->when($request->query('status') === 'active', fn ($q) => $q->where('active', true))
                ->when($request->query('status') === 'revoked', fn ($q) => $q->where('active', false))
                ->orderByDesc('id')
                ->get()
                ->map(fn (AccessCode $c) => $this->summarize($c))
                ->all(),
        ]);
    }

    /** Full detail for the per-code manage page: metadata, payout schedule, usage summary. */
    public function show(AccessCode $code): JsonResponse
    {
        $code->load('payoutTerms.payee', 'client');
        $recentCharges = $code->charges()->with('payouts')->latest('created_at')->limit(10)->get();

        return response()->json([
            ...$this->summarize($code),
            'payout_terms' => $code->payoutTerms->map(fn (PayoutTerm $t) => $this->summarizeTerm($t))->all(),
            'recent_charges' => $recentCharges->map(fn (Charge $c) => [
                'id' => $c->id,
                'external_order_id' => $c->external_order_id,
                'amount' => (string) $c->amount,
                'currency' => $c->currency,
                'is_repeat' => $c->original_charge_id !== null,
                'payout_count' => $c->payouts->count(),
                'created_at' => $c->created_at->toIso8601String(),
            ])->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'order_type' => ['required', self::ORDER_TYPES],
            'charge_amount' => ['numeric', 'min:0'],
            'charge_currency' => ['string', 'size:3'],
            'product_code' => ['required', 'string'],
            'allowed_scopes' => ['required', 'array', 'min:1'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'expires_at' => ['nullable', 'date'],
            'client_id' => ['nullable', 'exists:clients,id'],
            'notes' => ['nullable', 'string'],
            'count' => ['integer', 'min:1', 'max:500'], // bulk generation
        ]);

        if (! isset(ProductCatalog::PRODUCTS[$data['product_code']])) {
            return response()->json(['error' => ['code' => 'unknown_product', 'message' => "Unknown product '{$data['product_code']}'."]], 422);
        }
        [, $unknown] = Scopes::expand($data['allowed_scopes']);
        if ($unknown !== []) {
            return response()->json(['error' => ['code' => 'unknown_scope', 'message' => 'Unknown scope(s): '.implode(', ', $unknown).'.']], 422);
        }

        $codes = [];
        for ($i = 0; $i < ($data['count'] ?? 1); $i++) {
            $codes[] = AccessCode::create([
                'code' => AccessCode::generateCode(),
                'name' => $data['name'].(($data['count'] ?? 1) > 1 ? ' #'.($i + 1) : ''),
                'order_type' => $data['order_type'],
                'charge_amount' => $data['charge_amount'] ?? 0,
                'charge_currency' => $data['charge_currency'] ?? 'USD',
                'product_code' => $data['product_code'],
                'allowed_scopes' => $data['allowed_scopes'],
                'max_uses' => $data['max_uses'] ?? null,
                'expires_at' => $data['expires_at'] ?? null,
                'client_id' => $data['client_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->email,
            ])->code;
        }

        return response()->json(['codes' => $codes], 201);
    }

    public function update(Request $request, AccessCode $code): JsonResponse
    {
        $data = $request->validate([
            'name' => ['string', 'max:255'],
            'active' => ['boolean'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'expires_at' => ['nullable', 'date'],
            'client_id' => ['nullable', 'exists:clients,id'],
            'notes' => ['nullable', 'string'],
            'order_type' => [self::ORDER_TYPES],
            'allowed_scopes' => ['array', 'min:1'],
            'charge_amount' => ['numeric', 'min:0'],
            'charge_currency' => ['string', 'size:3'],
        ]);

        if ((isset($data['order_type']) || isset($data['allowed_scopes'])) && $code->scopeAndTypeLocked()) {
            return response()->json(['error' => [
                'code' => 'locked',
                'message' => "This code has scored {$code->uses_count} time(s) — order type and allowed_scopes are locked to keep historical usage and conversion reporting accurate. Issue a new code instead.",
            ]], 422);
        }
        if (isset($data['allowed_scopes'])) {
            [, $unknown] = Scopes::expand($data['allowed_scopes']);
            if ($unknown !== []) {
                return response()->json(['error' => ['code' => 'unknown_scope', 'message' => 'Unknown scope(s): '.implode(', ', $unknown).'.']], 422);
            }
        }

        $code->update($data);

        return response()->json($this->summarize($code));
    }

    /** Add a payout-schedule line (end lines, never delete history). */
    public function addTerm(Request $request, AccessCode $code): JsonResponse
    {
        $data = $request->validate([
            'payee_id' => ['required', 'exists:payees,id'],
            'category' => ['required', self::PAYOUT_CATEGORIES],
            'payout_type' => ['nullable', 'string', 'max:32'],
            'kind' => ['required_unless:category,residual', self::PAYOUT_KINDS],
            'amount' => ['required_unless:category,residual', 'numeric', 'min:0'],
            'currency' => ['string', 'size:3'],
            'language' => ['nullable', 'in:en,fr,pt'],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date'],
        ]);

        if ($data['category'] === 'residual' && $code->payoutTerms()->where('active', true)->where('category', 'residual')->exists()) {
            return response()->json(['error' => ['code' => 'residual_exists', 'message' => 'This code already has an active residual line — end it first. Exactly one line can absorb the balance.']], 422);
        }

        $term = $code->payoutTerms()->create([
            ...$data,
            'kind' => $data['kind'] ?? 'flat',
            'amount' => $data['amount'] ?? 0,
            'currency' => $data['currency'] ?? $code->charge_currency,
        ]);

        return response()->json($this->summarizeTerm($term), 201);
    }

    /**
     * Edit an unused line outright (typo fixes etc). Once a line has
     * produced a real payout, this refuses — end it and add a new one
     * (editing it would rewrite amounts already accrued to a stakeholder).
     */
    public function updateTerm(Request $request, PayoutTerm $term): JsonResponse
    {
        if ($term->hasBeenCharged()) {
            return response()->json(['error' => [
                'code' => 'locked',
                'message' => 'This line has already accrued at least one payout — editing it would rewrite historical statements. End it and add a new line instead.',
            ]], 422);
        }

        $data = $request->validate([
            'payee_id' => ['exists:payees,id'],
            'category' => [self::PAYOUT_CATEGORIES],
            'payout_type' => ['nullable', 'string', 'max:32'],
            'kind' => [self::PAYOUT_KINDS],
            'amount' => ['numeric', 'min:0'],
            'currency' => ['string', 'size:3'],
            'language' => ['nullable', 'in:en,fr,pt'],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date'],
        ]);
        $term->update($data);

        return response()->json($this->summarizeTerm($term));
    }

    public function endTerm(PayoutTerm $term): JsonResponse
    {
        $term->update(['active' => false, 'effective_until' => now()]);

        return response()->json($this->summarizeTerm($term));
    }

    /**
     * Charges & payouts statement, straight from the ledgers. Reports by
     * order type (independent of whether values happen to be zero), by
     * recipient, by code — plus lead→sale conversion inferred from
     * external_order_ids carrying both a lead and a later sale charge.
     */
    public function statement(Request $request): JsonResponse
    {
        [$from, $to, $charges] = $this->statementCharges($request);

        $byOrderType = [];
        $byRecipient = [];
        $byCode = [];
        $repeatCharges = 0;
        foreach ($charges as $charge) {
            $type = $charge->order_type;
            $byOrderType[$type]['usages'] = ($byOrderType[$type]['usages'] ?? 0) + 1;
            $byOrderType[$type]['charged'][$charge->currency] = round(($byOrderType[$type]['charged'][$charge->currency] ?? 0) + (float) $charge->amount, 4);
            if ($charge->original_charge_id !== null) {
                $repeatCharges++;
            }

            $codeLabel = $charge->accessCode?->name ?? $charge->accessCode?->code ?? '(deleted)';
            foreach ($charge->payouts as $payout) {
                $byRecipient[$payout->recipient][$payout->currency] = round(($byRecipient[$payout->recipient][$payout->currency] ?? 0) + (float) $payout->amount, 4);
                $byCode[$codeLabel][$payout->currency] = round(($byCode[$codeLabel][$payout->currency] ?? 0) + (float) $payout->amount, 4);
            }
        }

        // Conversion inference: external_order_ids with both a lead charge
        // and a later sale charge (all-time, not just the window — a sale in
        // this window converts a lead from any earlier period).
        $saleOrders = Charge::query()->where('order_type', 'sale')->whereNotNull('external_order_id')->distinct()->pluck('external_order_id');
        $leadOrders = Charge::query()->where('order_type', 'lead')->whereNotNull('external_order_id')->distinct()->pluck('external_order_id');
        $converted = $leadOrders->intersect($saleOrders);

        return response()->json([
            'period' => ['from' => $from, 'to' => $to],
            'charges' => $charges->count(),
            'repeat_charges' => $repeatCharges,
            'by_order_type' => $byOrderType,
            'payouts_by_recipient' => $byRecipient,
            'payouts_by_code' => $byCode,
            'conversion' => [
                'leads' => $leadOrders->count(),
                'converted' => $converted->count(),
                'rate' => $leadOrders->count() > 0 ? round(100 * $converted->count() / $leadOrders->count(), 1) : null,
            ],
        ]);
    }

    /** CSV export: one row per payout line, plus rows for zero-payout charges. */
    public function statementCsv(Request $request): StreamedResponse
    {
        [, , $charges] = $this->statementCharges($request);

        return response()->streamDownload(function () use ($charges) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['date', 'code_name', 'access_code', 'order_type', 'external_order_id', 'charge', 'charge_currency', 'repeat_of', 'recipient', 'category', 'payout_type', 'payout_amount', 'payout_currency', 'language', 'status']);
            foreach ($charges as $charge) {
                $base = [
                    $charge->created_at->toDateString(),
                    $charge->accessCode?->name ?? '',
                    $charge->accessCode?->code ?? '(deleted)',
                    $charge->order_type,
                    $charge->external_order_id ?? '',
                    (string) $charge->amount,
                    $charge->currency,
                    $charge->original_charge_id ?? '',
                ];
                if ($charge->payouts->isEmpty()) {
                    fputcsv($out, [...$base, '', '', '', '', '', '', '']);

                    continue;
                }
                foreach ($charge->payouts as $payout) {
                    fputcsv($out, [...$base, $payout->recipient, $payout->category, $payout->payout_type ?? '', (string) $payout->amount, $payout->currency, $payout->language ?? 'all', $payout->status]);
                }
            }
            fclose($out);
        }, 'charges-payouts-statement.csv', ['Content-Type' => 'text/csv']);
    }

    /** @return array{0: string, 1: string, 2: Collection<int, Charge>} */
    private function statementCharges(Request $request): array
    {
        $from = $request->query('from', now()->startOfMonth()->toDateString());
        $to = $request->query('to', now()->toDateString());

        $charges = Charge::query()
            ->with(['accessCode', 'payouts'])
            ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when($request->query('order_type'), fn ($q, $t) => $q->where('order_type', $t))
            ->orderBy('created_at')
            ->get();

        return [$from, $to, $charges];
    }

    /** @return array<string, mixed> */
    private function summarize(AccessCode $c): array
    {
        return [
            'code' => $c->code,
            'name' => $c->name,
            'order_type' => $c->order_type,
            'charge_amount' => (string) $c->charge_amount,
            'charge_currency' => $c->charge_currency,
            'product_code' => $c->product_code,
            'allowed_scopes' => $c->allowed_scopes,
            'max_uses' => $c->max_uses,
            'uses_count' => $c->uses_count,
            'expires_at' => $c->expires_at?->toIso8601String(),
            'client_id' => $c->client_id,
            'client' => $c->relationLoaded('client') ? $c->client?->name : $c->client()->value('name'),
            'notes' => $c->notes,
            'active' => $c->active,
            'usage_events' => $c->usage_events_count ?? $c->usageEvents()->count(),
            'scope_and_type_locked' => $c->scopeAndTypeLocked(),
            'payout_terms_count' => $c->relationLoaded('payoutTerms') ? $c->payoutTerms->count() : $c->payoutTerms()->count(),
            'active_payout_terms_count' => $c->relationLoaded('payoutTerms') ? $c->payoutTerms->where('active', true)->count() : $c->payoutTerms()->where('active', true)->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function summarizeTerm(PayoutTerm $t): array
    {
        return [
            'id' => $t->id,
            'payee_id' => $t->payee_id,
            'recipient' => $t->relationLoaded('payee') ? ($t->payee?->name ?? '(unknown)') : ($t->payee()->value('name') ?? '(unknown)'),
            'category' => $t->category,
            'payout_type' => $t->payout_type,
            'kind' => $t->kind,
            'amount' => (string) $t->amount,
            'currency' => $t->currency,
            'language' => $t->language,
            'active' => $t->active,
            'effective_from' => $t->effective_from?->toIso8601String(),
            'effective_until' => $t->effective_until?->toIso8601String(),
            'locked' => $t->hasBeenCharged(),
        ];
    }
}
