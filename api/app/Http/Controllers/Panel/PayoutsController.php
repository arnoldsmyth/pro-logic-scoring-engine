<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Payout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Payout status transitions + the payout-aging report (prolog-9x0).
 *
 * The payouts ledger is never edited or deleted — a payout's amount,
 * currency, category, etc. are permanent. The only thing that ever changes
 * is status, and only one-way: accrued -> paid or accrued -> void. Every
 * transition stamps who did it (transitioned_by) and when (paid_at /
 * voided_at); void additionally records why (void_reason). Once a payout
 * has left accrued it is frozen — pay()/voidPayout() refuse to
 * re-transition or edit an already-transitioned row (422 guard below).
 *
 * Money is always kept per-currency; settle() and aging() never blend
 * currencies into a single total.
 */
class PayoutsController extends Controller
{
    /** Mark a single accrued payout as paid. */
    public function pay(Request $request, Payout $payout): JsonResponse
    {
        if ($payout->status !== 'accrued') {
            return response()->json(['message' => 'Only accrued payouts can be marked paid.'], 422);
        }

        $payout->update([
            'status' => 'paid',
            'paid_at' => now(),
            'transitioned_by' => $request->user()->id,
        ]);

        return response()->json(['payout' => [
            'id' => $payout->id,
            'status' => $payout->status,
            'paid_at' => $payout->paid_at->toIso8601String(),
        ]]);
    }

    /** Void a single accrued payout with a required reason. */
    public function voidPayout(Request $request, Payout $payout): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        if ($payout->status !== 'accrued') {
            return response()->json(['message' => 'Only accrued payouts can be voided.'], 422);
        }

        $payout->update([
            'status' => 'void',
            'voided_at' => now(),
            'void_reason' => $data['reason'],
            'transitioned_by' => $request->user()->id,
        ]);

        return response()->json(['payout' => [
            'id' => $payout->id,
            'status' => $payout->status,
            'voided_at' => $payout->voided_at->toIso8601String(),
            'void_reason' => $payout->void_reason,
        ]]);
    }

    /**
     * Bulk-settle a payee: every accrued payout of theirs inside the window
     * flips to paid, atomically. Returns the count and per-currency sum of
     * exactly what was just settled (never a running/all-time total).
     */
    public function settle(Request $request): JsonResponse
    {
        $data = $request->validate([
            'payee_id' => ['required', 'exists:payees,id'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
        ]);

        $count = 0;
        $totals = [];

        DB::transaction(function () use ($data, $request, &$count, &$totals) {
            $rows = Payout::query()
                ->where('payee_id', $data['payee_id'])
                ->where('status', 'accrued')
                ->whereBetween('created_at', [$data['from'].' 00:00:00', $data['to'].' 23:59:59'])
                ->lockForUpdate()
                ->get(['id', 'currency', 'amount']);

            $count = $rows->count();
            foreach ($rows as $row) {
                $totals[$row->currency] = round(($totals[$row->currency] ?? 0) + (float) $row->amount, 4);
            }

            if ($count > 0) {
                Payout::query()
                    ->whereIn('id', $rows->pluck('id'))
                    ->update([
                        'status' => 'paid',
                        'paid_at' => now(),
                        'transitioned_by' => $request->user()->id,
                    ]);
            }
        });

        return response()->json(['settled' => $count, 'totals' => $totals]);
    }

    /**
     * Payout-aging report: one row per payee x currency. accrued/paid/void
     * are all-time per-currency sums straight off the ledger; `aging` splits
     * the accrued sum by age (days since created_at, as of now) into
     * 0-30 / 31-60 / 61-90 / 90+ buckets. Done in PHP from a single query —
     * dataset is small, same style as ReportsController. Rows where
     * accrued/paid/void are all zero are dropped.
     */
    public function aging(Request $request): JsonResponse
    {
        $now = now();
        $payouts = Payout::query()->select(['payee_id', 'recipient', 'currency', 'status', 'amount', 'created_at'])->get();

        $rows = [];
        foreach ($payouts as $payout) {
            $key = ($payout->payee_id ?? 0).'|'.$payout->currency;
            $rows[$key] ??= [
                'payee_id' => $payout->payee_id,
                'recipient' => $payout->recipient,
                'currency' => $payout->currency,
                'accrued' => 0.0,
                'paid' => 0.0,
                'void' => 0.0,
                'aging' => ['d0_30' => 0.0, 'd31_60' => 0.0, 'd61_90' => 0.0, 'd90_plus' => 0.0],
            ];

            $amount = (float) $payout->amount;
            $rows[$key][$payout->status] = round($rows[$key][$payout->status] + $amount, 4);

            if ($payout->status === 'accrued') {
                $days = $payout->created_at->diffInDays($now);
                $bucket = match (true) {
                    $days <= 30 => 'd0_30',
                    $days <= 60 => 'd31_60',
                    $days <= 90 => 'd61_90',
                    default => 'd90_plus',
                };
                $rows[$key]['aging'][$bucket] = round($rows[$key]['aging'][$bucket] + $amount, 4);
            }
        }

        $rows = array_values(array_filter(
            $rows,
            fn (array $r) => $r['accrued'] != 0 || $r['paid'] != 0 || $r['void'] != 0
        ));

        usort($rows, fn (array $a, array $b) => [$a['recipient'], $a['currency']] <=> [$b['recipient'], $b['currency']]);

        return response()->json([
            'as_of' => $now->toDateString(),
            'rows' => $rows,
        ]);
    }
}
