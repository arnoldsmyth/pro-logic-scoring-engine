<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\NormSample;
use App\Models\NormSet;
use App\Scoring\Norms\NormAnalytics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Norms & analytics (docs/08 §5 + docs/06): sets with provenance, sample
 * accumulation vs the 400/scale threshold, drift, impact reports, and the
 * manual promote/retire actions (decided policy: Arnold signs off on the
 * impact report; nothing auto-promotes).
 */
class NormsController extends Controller
{
    public function index(NormAnalytics $analytics): JsonResponse
    {
        $sets = NormSet::query()->withCount('entries')->orderBy('id')->get();

        $populations = NormSample::query()
            ->selectRaw('language, gender')
            ->groupBy('language', 'gender')
            ->get()
            ->map(function ($p) use ($analytics, $sets) {
                $sizes = $analytics->sampleSizes($p->language, $p->gender);
                $active = $sets->first(fn (NormSet $s) => $s->status === 'active'
                    && ($s->language === null || $s->language === $p->language)
                    && $s->gender === $p->gender);

                return [
                    'language' => $p->language,
                    'gender' => $p->gender,
                    'samples_per_scale' => $sizes,
                    'threshold' => NormAnalytics::SAMPLE_THRESHOLD,
                    'eligible' => $sizes !== [] && min($sizes) >= NormAnalytics::SAMPLE_THRESHOLD,
                    'drift_vs_active' => $active !== null ? $this->tryDrift($analytics, $p->language, $p->gender, $active) : null,
                    'drift_baseline' => $active?->slug,
                ];
            });

        return response()->json([
            'sets' => $sets->map(fn (NormSet $s) => [
                'id' => $s->id,
                'slug' => $s->slug,
                'status' => $s->status,
                'language' => $s->language,
                'gender' => $s->gender,
                'provisional' => $s->provisional,
                'description' => $s->description,
                'entries' => $s->entries_count,
                'provenance' => $s->provenance,
                'impact' => $s->impact,
                'activated_at' => $s->activated_at?->toIso8601String(),
                'retired_at' => $s->retired_at?->toIso8601String(),
            ])->all(),
            'populations' => $populations->all(),
        ]);
    }

    public function impact(NormSet $set, NormAnalytics $analytics, Request $request): JsonResponse
    {
        return response()->json($analytics->impactReport($set, (int) $request->input('limit', 200)));
    }

    public function promote(NormSet $set): JsonResponse
    {
        if ($set->status !== 'candidate') {
            return response()->json(['error' => ['code' => 'invalid_status', 'message' => "Only candidates can be promoted; '{$set->slug}' is {$set->status}."]], 422);
        }
        if ($set->impact === null) {
            return response()->json(['error' => ['code' => 'impact_required', 'message' => 'Promotion policy: generate and review the impact report first.']], 422);
        }

        $replaced = $set->replaces();
        $replaced?->update(['status' => 'retired', 'retired_at' => now()]);
        $set->update(['status' => 'active', 'activated_at' => now()]);

        return response()->json(['ok' => true, 'retired' => $replaced?->slug]);
    }

    public function retire(NormSet $set): JsonResponse
    {
        $set->update(['status' => 'retired', 'retired_at' => now()]);

        return response()->json(['ok' => true]);
    }

    private function tryDrift(NormAnalytics $analytics, string $language, ?string $gender, NormSet $against): ?array
    {
        try {
            return $analytics->drift($language, $gender, $against);
        } catch (\Throwable) {
            return null;
        }
    }
}
