<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\AccessCode;
use App\Models\RoyaltyTerm;
use App\Models\UsageEvent;
use App\Scoring\Engine\ProductCatalog;
use App\Scoring\Scopes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Access codes & royalties (docs/08 §3 + docs/07). Royalty statements are
 * produced from usage_events alone — no billing-provider dependency — and
 * derivative usage is visible but excluded from royalty totals.
 */
class CodesController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'codes' => AccessCode::query()->with('royaltyTerms')->withCount('usageEvents')->orderBy('id')->get()->map(fn (AccessCode $c) => [
                'id' => $c->id,
                'code' => $c->code,
                'name' => $c->name,
                'type' => $c->type,
                'product_code' => $c->product_code,
                'allowed_scopes' => $c->allowed_scopes,
                'max_uses' => $c->max_uses,
                'uses_count' => $c->uses_count,
                'expires_at' => $c->expires_at?->toIso8601String(),
                'issued_to' => $c->issued_to,
                'notes' => $c->notes,
                'active' => $c->active,
                'usage_events' => $c->usage_events_count,
                'royalty_terms' => $c->royaltyTerms->map(fn (RoyaltyTerm $t) => [
                    'id' => $t->id,
                    'recipient' => $t->recipient,
                    'kind' => $t->kind,
                    'amount' => (string) $t->amount,
                    'currency' => $t->currency,
                    'language' => $t->language,
                    'active' => $t->active,
                    'effective_from' => $t->effective_from?->toIso8601String(),
                    'effective_until' => $t->effective_until?->toIso8601String(),
                ])->all(),
            ])->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:training,bizdev,derivative'],
            'product_code' => ['required', 'string'],
            'allowed_scopes' => ['required', 'array', 'min:1'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'expires_at' => ['nullable', 'date'],
            'issued_to' => ['nullable', 'string', 'max:255'],
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
                'type' => $data['type'],
                'product_code' => $data['product_code'],
                'allowed_scopes' => $data['allowed_scopes'],
                'max_uses' => $data['max_uses'] ?? null,
                'expires_at' => $data['expires_at'] ?? null,
                'issued_to' => $data['issued_to'] ?? null,
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
            'issued_to' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);
        $code->update($data);

        return response()->json(['ok' => true]);
    }

    /** Add a royalty term (docs/07: end terms, never delete history). */
    public function addTerm(Request $request, AccessCode $code): JsonResponse
    {
        $data = $request->validate([
            'recipient' => ['required', 'string', 'max:255'],
            'kind' => ['required', 'in:flat_per_report,percentage_of_price,tiered,subscription,flat_on_conversion'],
            'amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['string', 'size:3'],
            'language' => ['nullable', 'in:en,fr,pt'],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date'],
        ]);
        $term = $code->royaltyTerms()->create([...$data, 'currency' => $data['currency'] ?? 'USD']);

        return response()->json(['id' => $term->id], 201);
    }

    public function endTerm(RoyaltyTerm $term): JsonResponse
    {
        $term->update(['active' => false, 'effective_until' => now()]);

        return response()->json(['ok' => true]);
    }

    /**
     * Royalty statement per recipient/period from usage_events alone
     * (docs/07). Royalty behavior is terms-driven (decided 2026-07-11):
     * events whose fees_due is empty owe nothing — whatever their code
     * type label says. Totals also break down by code name so statements
     * report per product/partner batch.
     */
    public function statement(Request $request): JsonResponse
    {
        [$from, $to, $events] = $this->statementEvents($request);

        $byRecipient = [];
        $byCode = [];
        $noFeeEvents = 0;
        foreach ($events as $e) {
            if ($e->fees_due === []) {
                $noFeeEvents++;

                continue;
            }
            $codeLabel = $e->accessCode?->name ?? $e->accessCode?->code ?? '(deleted)';
            foreach ($e->fees_due as $fee) {
                $r = $fee['recipient'];
                $cur = $fee['currency'];
                $byRecipient[$r][$cur] = round(($byRecipient[$r][$cur] ?? 0) + (float) $fee['amount'], 4);
                $byCode[$codeLabel][$cur] = round(($byCode[$codeLabel][$cur] ?? 0) + (float) $fee['amount'], 4);
            }
        }

        return response()->json([
            'period' => ['from' => $from, 'to' => $to],
            'events' => $events->count(),
            'no_fee_events' => $noFeeEvents,
            'totals_by_recipient' => $byRecipient,
            'totals_by_code' => $byCode,
        ]);
    }

    /** CSV export of the same statement, one row per usage-event fee line. */
    public function statementCsv(Request $request): StreamedResponse
    {
        [, , $events] = $this->statementEvents($request);

        return response()->streamDownload(function () use ($events) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['date', 'code_name', 'access_code', 'code_type', 'scopes', 'recipient', 'kind', 'amount', 'currency', 'language', 'royalty_due']);
            foreach ($events as $e) {
                $codeName = $e->accessCode?->name ?? '';
                $codeStr = $e->accessCode?->code ?? '(deleted)';
                foreach ($e->fees_due as $fee) {
                    fputcsv($out, [
                        $e->created_at->toDateString(),
                        $codeName,
                        $codeStr,
                        $e->code_type,
                        implode(' ', $e->scopes),
                        $fee['recipient'],
                        $fee['kind'],
                        $fee['amount'],
                        $fee['currency'],
                        $fee['language'] ?? 'all',
                        'yes',
                    ]);
                }
                if ($e->fees_due === []) {
                    fputcsv($out, [$e->created_at->toDateString(), $codeName, $codeStr, $e->code_type, implode(' ', $e->scopes), '', '', '', '', '', 'no fees']);
                }
            }
            fclose($out);
        }, 'royalty-statement.csv', ['Content-Type' => 'text/csv']);
    }

    /** @return array{0: string, 1: string, 2: Collection<int, UsageEvent>} */
    private function statementEvents(Request $request): array
    {
        $from = $request->query('from', now()->startOfMonth()->toDateString());
        $to = $request->query('to', now()->toDateString());

        $events = UsageEvent::query()
            ->with('accessCode')
            ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when($request->query('code_type'), fn ($q, $t) => $q->where('code_type', $t))
            ->orderBy('created_at')
            ->get();

        return [$from, $to, $events];
    }
}
