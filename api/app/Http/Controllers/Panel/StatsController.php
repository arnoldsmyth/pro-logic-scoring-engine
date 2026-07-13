<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\ScoredResult;
use App\Models\UsageEvent;
use App\Models\WebhookDelivery;
use Illuminate\Http\JsonResponse;

/**
 * Dashboard numbers (docs/08 §1). Aggregated in PHP over a recent window —
 * honest to what the system records today (usage_events meter successful
 * scoring calls; there is no request log yet, so no latency/error series).
 */
class StatsController extends Controller
{
    public function index(): JsonResponse
    {
        $since = now()->subDays(14)->startOfDay();
        $events = UsageEvent::query()->where('created_at', '>=', $since)->get(['scopes', 'code_type', 'created_at']);

        $byDay = [];
        for ($d = 13; $d >= 0; $d--) {
            $byDay[now()->subDays($d)->toDateString()] = 0;
        }
        $byScope = [];
        $byCodeType = [];
        foreach ($events as $e) {
            $day = $e->created_at->toDateString();
            if (isset($byDay[$day])) {
                $byDay[$day]++;
            }
            foreach ($e->scopes as $scope) {
                $byScope[$scope] = ($byScope[$scope] ?? 0) + 1;
            }
            $byCodeType[$e->code_type] = ($byCodeType[$e->code_type] ?? 0) + 1; // code_type column carries order_type
        }
        arsort($byScope);

        return response()->json([
            'scoring_calls_by_day' => $byDay,
            'scores_by_scope' => $byScope,
            'scores_by_order_type' => $byCodeType,
            'totals' => [
                'assessments' => Assessment::count(),
                'scored_results' => ScoredResult::count(),
                'usage_events' => UsageEvent::count(),
                'webhook_failures' => WebhookDelivery::query()->where('status', 'failed')->count(),
            ],
        ]);
    }
}
