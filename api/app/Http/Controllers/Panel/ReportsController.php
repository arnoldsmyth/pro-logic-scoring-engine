<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\Charge;
use App\Models\ScoredResult;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Read-only reporting surface (auth group, no admin gate). The Royalty
 * Statement is a payee-centric view of the charges + payouts ledgers,
 * reported straight from those tables — never from Stripe and never
 * recomputed from payout terms. Money is always kept per-currency; totals
 * are never blended across currencies. Lead→sale conversion is inferred at
 * reporting time from external_order_ids carrying both a lead and a later
 * sale charge (same inference as CodesController::statement()).
 *
 * Derivative-product exclusion (include_derivative + is_derivative +
 * derivative_usages) is intentionally NOT implemented: the current schema
 * carries no unambiguous "derivative" signal. The historical `type`
 * enum value 'derivative' was collapsed into order_type='sale' and dropped
 * (see 2026_07_13_000001_charges_and_payouts.php), the product axis now
 * lives on product_code, and there is no derivative payout_type or catalog
 * flag. Deferred pending a real product-catalog signal.
 */
class ReportsController extends Controller
{
    private const GROUP_BY = ['payee', 'client', 'code', 'order_type'];

    private const STATUSES = ['accrued', 'paid', 'void'];

    private const VOLUME_GROUP_BY = ['language', 'gender', 'client', 'scope'];

    public function royalties(Request $request): JsonResponse
    {
        [$from, $to, $lines, $chargeCount, $repeatCount] = $this->collect($request);
        $groupBy = $this->groupBy($request);

        $totals = ['accrued' => [], 'paid' => [], 'void' => []];
        $groups = [];

        foreach ($lines as $line) {
            [$key, $label] = $this->groupKey($groupBy, $line);
            $groups[$key] ??= ['key' => $key, 'label' => $label, 'accrued' => [], 'paid' => [], 'void' => [], 'lines' => []];

            $this->addMoney($totals[$line['status']], $line['currency'], $line['amount']);
            $this->addMoney($groups[$key][$line['status']], $line['currency'], $line['amount']);
            $groups[$key]['lines'][] = $this->publicLine($line);
        }

        return response()->json([
            'period' => ['from' => $from, 'to' => $to],
            'group_by' => $groupBy,
            'totals' => [
                'accrued' => $totals['accrued'],
                'paid' => $totals['paid'],
                'void' => $totals['void'],
                'net_owed' => $this->netOwed($totals['accrued'], $totals['paid']),
                'charges' => $chargeCount,
                'repeat_charges' => $repeatCount,
            ],
            'conversion' => $this->conversion(),
            'groups' => array_values(array_map(fn ($g) => [
                'key' => $g['key'],
                'label' => $g['label'],
                'totals' => [
                    'accrued' => $g['accrued'],
                    'paid' => $g['paid'],
                    'void' => $g['void'],
                    'net_owed' => $this->netOwed($g['accrued'], $g['paid']),
                    'lines' => count($g['lines']),
                ],
                'lines' => $g['lines'],
            ], $groups)),
        ]);
    }

    /** CSV export: one row per payout line, same filters as the JSON report. */
    public function royaltiesCsv(Request $request): StreamedResponse
    {
        [, , $lines] = $this->collect($request);

        return response()->streamDownload(function () use ($lines) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['payout_id', 'payee_id', 'recipient', 'category', 'payout_type', 'amount', 'currency', 'language', 'status', 'charge_id', 'original_charge_id', 'product_code', 'external_order_id', 'order_type', 'charge_amount', 'charge_date']);
            foreach ($lines as $line) {
                fputcsv($out, [
                    $line['payout_id'],
                    $line['payee_id'] ?? '',
                    $line['recipient'],
                    $line['category'],
                    $line['payout_type'] ?? '',
                    (string) $line['amount'],
                    $line['currency'],
                    $line['language'] ?? '',
                    $line['status'],
                    $line['charge_id'],
                    $line['original_charge_id'] ?? '',
                    $line['product_code'],
                    $line['external_order_id'] ?? '',
                    $line['order_type'],
                    (string) $line['charge_amount'],
                    $line['charge_date'],
                ]);
            }
            fclose($out);
        }, 'royalty-statement.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * Assessment-volume report: how many assessments were created and how
     * many results were scored, over a day-by-day series plus a slice
     * breakdown by language/gender/client/scope. Aggregated in PHP over
     * eager-loaded queries — the dataset is small, matching house style.
     *
     * Creation and scoring are counted on their own natural timestamps
     * (assessments.created_at / scored_results.scored_at); slice dimensions
     * (language, gender, client) always come from the owning assessment, so
     * a scored result attributes to its assessment's language/gender/client
     * even if the assessment itself was created outside the window.
     */
    public function volume(Request $request): JsonResponse
    {
        [$from, $to] = $this->volumeWindow($request);
        $groupBy = $this->volumeGroupBy($request);

        $assessments = Assessment::query()
            ->with('apiKey.client')
            ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->get();

        $results = ScoredResult::query()
            ->with('assessment.apiKey.client')
            ->whereBetween('scored_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->get();

        return response()->json([
            'period' => ['from' => $from, 'to' => $to],
            'group_by' => $groupBy,
            'totals' => [
                'created' => $assessments->count(),
                'scored' => $results->count(),
            ],
            'series' => $this->volumeSeries($from, $to, $assessments, $results),
            'slices' => $this->volumeSlices($groupBy, $assessments, $results),
        ]);
    }

    /**
     * Pull the filtered charges and flatten to payout lines. Charge-level
     * filters (date window, order_type, code) shape which charges count; the
     * charge/repeat counts reflect those and ignore the payout-line filters
     * (payee_id, language, status), per spec. Payout-line filters only decide
     * which lines are aggregated into the money totals and returned.
     *
     * @return array{0: string, 1: string, 2: list<array<string, mixed>>, 3: int, 4: int}
     */
    private function collect(Request $request): array
    {
        $from = $request->query('from', now()->startOfMonth()->toDateString());
        $to = $request->query('to', now()->toDateString());

        $charges = Charge::query()
            ->with(['accessCode.client', 'payouts.payee'])
            ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when($request->query('order_type'), fn ($q, $t) => $q->where('order_type', $t))
            ->when($request->query('code'), fn ($q, $c) => $q->whereRelation('accessCode', 'code', $c))
            ->orderBy('created_at')
            ->get();

        $payeeId = $request->query('payee_id');
        $language = $request->query('language');
        $statuses = $this->statuses($request);

        $lines = [];
        $chargeCount = 0;
        $repeatCount = 0;
        foreach ($charges as $charge) {
            $chargeCount++;
            if ($charge->original_charge_id !== null) {
                $repeatCount++;
            }

            $clientId = $charge->accessCode?->client_id;
            $clientName = $charge->accessCode?->client?->name ?? '(no client)';
            $codeId = $charge->access_code_id;
            $codeLabel = $charge->accessCode?->name ?? $charge->accessCode?->code ?? '(deleted)';

            foreach ($charge->payouts as $payout) {
                if ($payeeId !== null && (string) $payout->payee_id !== (string) $payeeId) {
                    continue;
                }
                if ($language !== null && $payout->language !== $language) {
                    continue;
                }
                if ($statuses !== null && ! in_array($payout->status, $statuses, true)) {
                    continue;
                }

                $lines[] = [
                    'payout_id' => $payout->id,
                    'payee_id' => $payout->payee_id,
                    'recipient' => $payout->recipient,
                    'category' => $payout->category,
                    'payout_type' => $payout->payout_type,
                    'language' => $payout->language,
                    'amount' => (float) $payout->amount,
                    'currency' => $payout->currency,
                    'status' => $payout->status,
                    'charge_id' => $charge->id,
                    'original_charge_id' => $charge->original_charge_id,
                    'product_code' => $charge->product_code,
                    'external_order_id' => $charge->external_order_id,
                    'order_type' => $charge->order_type,
                    'charge_amount' => (float) $charge->amount,
                    'charge_date' => $charge->created_at->toDateString(),
                    // grouping dimensions (not part of the public line shape)
                    '_client_id' => $clientId,
                    '_client_name' => $clientName,
                    '_code_id' => $codeId,
                    '_code_label' => $codeLabel,
                ];
            }
        }

        return [$from, $to, $lines, $chargeCount, $repeatCount];
    }

    /** The public JSON line shape (strips internal grouping dimensions). */
    private function publicLine(array $line): array
    {
        return [
            'payout_id' => $line['payout_id'],
            'recipient' => $line['recipient'],
            'category' => $line['category'],
            'payout_type' => $line['payout_type'],
            'language' => $line['language'],
            'amount' => $line['amount'],
            'currency' => $line['currency'],
            'status' => $line['status'],
            'charge_id' => $line['charge_id'],
            'original_charge_id' => $line['original_charge_id'],
            'product_code' => $line['product_code'],
            'external_order_id' => $line['external_order_id'],
            'order_type' => $line['order_type'],
            'charge_amount' => $line['charge_amount'],
            'charge_date' => $line['charge_date'],
        ];
    }

    /** @return array{0: string, 1: string} [key, label] */
    private function groupKey(string $groupBy, array $line): array
    {
        return match ($groupBy) {
            'client' => ['client:'.($line['_client_id'] ?? '0'), $line['_client_name']],
            'code' => ['code:'.($line['_code_id'] ?? '0'), $line['_code_label']],
            'order_type' => ['order_type:'.$line['order_type'], $line['order_type']],
            default => ['payee:'.($line['payee_id'] ?? '0'), $line['recipient'] ?: '(unknown payee)'],
        };
    }

    private function groupBy(Request $request): string
    {
        $groupBy = $request->query('group_by', 'payee');

        return in_array($groupBy, self::GROUP_BY, true) ? $groupBy : 'payee';
    }

    /** @return list<string>|null null = no status filter (all statuses) */
    private function statuses(Request $request): ?array
    {
        $raw = $request->query('status');
        if ($raw === null || $raw === '') {
            return null;
        }
        $requested = array_filter(array_map('trim', explode(',', $raw)));
        $valid = array_values(array_intersect($requested, self::STATUSES));

        return $valid === [] ? null : $valid;
    }

    private function addMoney(array &$map, string $currency, float $amount): void
    {
        $map[$currency] = round(($map[$currency] ?? 0) + $amount, 4);
    }

    /** net_owed = accrued − paid, per currency. */
    private function netOwed(array $accrued, array $paid): array
    {
        $net = [];
        foreach (array_unique([...array_keys($accrued), ...array_keys($paid)]) as $currency) {
            $net[$currency] = round(($accrued[$currency] ?? 0) - ($paid[$currency] ?? 0), 4);
        }

        return $net;
    }

    /**
     * Lead→sale conversion inferred all-time from external_order_ids carrying
     * both a lead and a later sale charge (verbatim from CodesController).
     *
     * @return array{leads: int, converted: int, rate: float|null}
     */
    private function conversion(): array
    {
        $saleOrders = Charge::query()->where('order_type', 'sale')->whereNotNull('external_order_id')->distinct()->pluck('external_order_id');
        $leadOrders = Charge::query()->where('order_type', 'lead')->whereNotNull('external_order_id')->distinct()->pluck('external_order_id');
        $converted = $leadOrders->intersect($saleOrders);

        return [
            'leads' => $leadOrders->count(),
            'converted' => $converted->count(),
            'rate' => $leadOrders->count() > 0 ? round(100 * $converted->count() / $leadOrders->count(), 1) : null,
        ];
    }

    /** @return array{0: string, 1: string} [from, to], defaulting to [start of this month, today] */
    private function volumeWindow(Request $request): array
    {
        $from = $request->query('from', now()->startOfMonth()->toDateString());
        $to = $request->query('to', now()->toDateString());

        return [$from, $to];
    }

    private function volumeGroupBy(Request $request): string
    {
        $groupBy = $request->query('group_by', 'language');

        return in_array($groupBy, self::VOLUME_GROUP_BY, true) ? $groupBy : 'language';
    }

    /**
     * One entry per calendar day across [$from, $to] inclusive, zero-filled.
     * Always a straight daily total, independent of group_by.
     *
     * @param  Collection<int, Assessment>  $assessments
     * @param  Collection<int, ScoredResult>  $results
     * @return list<array{date: string, created: int, scored: int}>
     */
    private function volumeSeries(string $from, string $to, Collection $assessments, Collection $results): array
    {
        $createdByDay = [];
        foreach ($assessments as $assessment) {
            $day = $assessment->created_at->toDateString();
            $createdByDay[$day] = ($createdByDay[$day] ?? 0) + 1;
        }

        $scoredByDay = [];
        foreach ($results as $result) {
            $day = $result->scored_at->toDateString();
            $scoredByDay[$day] = ($scoredByDay[$day] ?? 0) + 1;
        }

        $series = [];
        $cursor = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->startOfDay();
        while ($cursor->lte($end)) {
            $day = $cursor->toDateString();
            $series[] = [
                'date' => $day,
                'created' => $createdByDay[$day] ?? 0,
                'scored' => $scoredByDay[$day] ?? 0,
            ];
            $cursor = $cursor->addDay();
        }

        return $series;
    }

    /**
     * Slice breakdown for the requested group_by. Scope is scored-results
     * only (creation has no scope, so 'created' is always 0 there); the
     * other dimensions merge assessment-created and result-scored counts
     * under the same key, keyed off the owning assessment's attributes.
     *
     * @param  Collection<int, Assessment>  $assessments
     * @param  Collection<int, ScoredResult>  $results
     * @return list<array{key: string, label: string, created: int, scored: int}>
     */
    private function volumeSlices(string $groupBy, Collection $assessments, Collection $results): array
    {
        if ($groupBy === 'scope') {
            return $this->volumeScopeSlices($results);
        }

        $slices = [];
        foreach ($assessments as $assessment) {
            [$key, $label] = $this->volumeSliceKey($groupBy, $assessment);
            $slices[$key] ??= ['key' => $key, 'label' => $label, 'created' => 0, 'scored' => 0];
            $slices[$key]['created']++;
        }
        foreach ($results as $result) {
            $assessment = $result->assessment;
            if ($assessment === null) {
                continue;
            }
            [$key, $label] = $this->volumeSliceKey($groupBy, $assessment);
            $slices[$key] ??= ['key' => $key, 'label' => $label, 'created' => 0, 'scored' => 0];
            $slices[$key]['scored']++;
        }

        $values = array_values($slices);
        if ($groupBy === 'client') {
            usort($values, fn ($a, $b) => $b['created'] <=> $a['created']);
        } else {
            usort($values, fn ($a, $b) => $a['key'] <=> $b['key']);
        }

        return $values;
    }

    /** @return array{0: string, 1: string} [key, label] */
    private function volumeSliceKey(string $groupBy, Assessment $assessment): array
    {
        return match ($groupBy) {
            'gender' => [$assessment->gender ?? 'unspecified', $assessment->gender ?? 'unspecified'],
            'client' => [
                (string) ($assessment->apiKey?->client_id ?? '0'),
                $assessment->apiKey?->client?->name ?? '(no client)',
            ],
            default => [$assessment->language, $assessment->language],
        };
    }

    /**
     * Scope slices count a result once per scope it carries — a result
     * scored with scopes ["mcs", "insights"] counts toward both. There is
     * no creation-side concept of scope, so 'created' is always 0.
     *
     * @param  Collection<int, ScoredResult>  $results
     * @return list<array{key: string, label: string, created: int, scored: int}>
     */
    private function volumeScopeSlices(Collection $results): array
    {
        $slices = [];
        foreach ($results as $result) {
            foreach (($result->scopes ?? []) as $scope) {
                $slices[$scope] ??= ['key' => $scope, 'label' => $scope, 'created' => 0, 'scored' => 0];
                $slices[$scope]['scored']++;
            }
        }

        $values = array_values($slices);
        usort($values, fn ($a, $b) => $a['key'] <=> $b['key']);

        return $values;
    }
}
