<?php

namespace App\Scoring\Norms;

use App\Models\Assessment;
use App\Models\NormSample;
use App\Models\NormSet;
use App\Scoring\Contracts\ScoringEngine;
use App\Scoring\Engine\ResultAssembler;
use RuntimeException;

/**
 * The docs/06 continuous-improvement machinery: sample-size accumulation →
 * candidate derivation → side-by-side impact report → (manual) promotion.
 * Everything here is deterministic data transformation; the human decision
 * point (Arnold reviews the impact report, ≥400/scale threshold — decided
 * 2026-07-11) is enforced in the lifecycle commands, not bypassed here.
 */
class NormAnalytics
{
    /** Decided 2026-07-11: candidate eligibility needs ≥400 samples/scale. */
    public const SAMPLE_THRESHOLD = 400;

    public function __construct(private readonly ScoringEngine $engine) {}

    /**
     * Sample accumulation per scale for a population.
     *
     * @return array<int, int> toolScaleDetailKey → total observations
     */
    public function sampleSizes(string $language, ?string $gender): array
    {
        return NormSample::query()
            ->where('language', $language)
            ->when($gender === null, fn ($q) => $q->whereNull('gender'), fn ($q) => $q->where('gender', $gender))
            ->selectRaw('tool_scale_detail_key, SUM(count) as n')
            ->groupBy('tool_scale_detail_key')
            ->pluck('n', 'tool_scale_detail_key')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * Derive a candidate conversion table from accumulated samples: per
     * scale, normed(raw) = midpoint cumulative proportion — mirrors the 0..1
     * shape of the legacy tables while never emitting exactly 0 or 1 (the
     * "smooth sparse tails" requirement, docs/06).
     *
     * @return array<int, array<string, float>> [scale][raw] → normed
     */
    public function deriveTable(string $language, ?string $gender): array
    {
        $rows = NormSample::query()
            ->where('language', $language)
            ->when($gender === null, fn ($q) => $q->whereNull('gender'), fn ($q) => $q->where('gender', $gender))
            ->orderBy('tool_scale_detail_key')
            ->orderBy('raw')
            ->get(['tool_scale_detail_key', 'raw', 'count']);
        if ($rows->isEmpty()) {
            throw new RuntimeException("No accumulated samples for language={$language} gender=".($gender ?? 'pooled').'.');
        }

        $table = [];
        foreach ($rows->groupBy('tool_scale_detail_key') as $scale => $scaleRows) {
            $total = $scaleRows->sum('count');
            $running = 0;
            foreach ($scaleRows as $r) {
                $midpoint = ($running + (int) $r->count / 2) / $total;
                $table[(int) $scale][(string) (float) $r->raw] = round($midpoint, 6);
                $running += (int) $r->count;
            }
        }

        return $table;
    }

    /**
     * Create a candidate norm set from accumulated samples.
     */
    public function buildCandidate(string $slug, string $language, ?string $gender, bool $force = false): NormSet
    {
        $sizes = $this->sampleSizes($language, $gender);
        $under = array_filter($sizes, fn (int $n) => $n < self::SAMPLE_THRESHOLD);
        if ($sizes === [] || ($under !== [] && ! $force)) {
            throw new RuntimeException(
                'Sample threshold not met ('.self::SAMPLE_THRESHOLD.'/scale required): '
                .($sizes === [] ? 'no samples at all' : 'scales below threshold: '.json_encode($under))
                .'. Use --force to build anyway (set will be marked provisional).'
            );
        }

        $set = NormSet::create([
            'slug' => $slug,
            'status' => 'candidate',
            'language' => $language,
            'gender' => $gender,
            'provisional' => $under !== [],
            'description' => 'Derived from accumulated response distributions (midpoint cumulative proportion).',
            'provenance' => [
                'method' => 'midpoint cumulative proportion over norm_samples',
                'n_per_scale' => $sizes,
                'threshold' => self::SAMPLE_THRESHOLD,
                'forced_below_threshold' => $under !== [] ? array_keys($under) : [],
                'caveat' => 'Respondents are self-selected assessment clients, not a general-population sample.',
                'built_at' => now()->toIso8601String(),
            ],
        ]);

        $entries = [];
        foreach ($this->deriveTable($language, $gender) as $scale => $raws) {
            foreach ($raws as $raw => $normed) {
                $entries[] = ['tool_scale_detail_key' => $scale, 'raw' => (float) $raw, 'normed' => $normed];
            }
        }
        $set->entries()->createMany($entries);

        return $set;
    }

    /**
     * Side-by-side impact report (docs/06): rescore recent assessments under
     * the candidate vs the active set it would replace and publish the % of
     * results whose top-3 codes change. Stored on the candidate set; this is
     * what Arnold reviews before promoting.
     */
    public function impactReport(NormSet $candidate, int $limit = 200): array
    {
        $baseline = $candidate->replaces()
            ?? NormSet::query()->where('slug', $candidate->gender === 'F' ? 'female-legacy' : 'male-legacy')->first()
            ?? throw new RuntimeException('No active set found to compare against.');

        $assessments = Assessment::query()
            ->whereHas('results', fn ($q) => $q->where('norm_set', '!=', 'none'))
            ->with('tools')
            ->latest('id')
            ->limit($limit)
            ->get();

        $compared = 0;
        $changed = 0;
        $dimensionChanges = ['m' => 0, 's' => 0, 'p' => 0];
        foreach ($assessments as $assessment) {
            $tools = $assessment->toolResponses();
            $registration = ['gender' => $assessment->gender, 'language' => $assessment->language];
            try {
                $a = $this->engine->score($registration, $tools, ['mcs', 'pro.person'], $baseline->slug);
                $b = $this->engine->score($registration, $tools, ['mcs', 'pro.person'], $candidate->slug);
            } catch (\Throwable) {
                continue; // incomplete tool sets etc. — not part of the comparison population
            }
            $compared++;

            $delta = false;
            foreach (['m' => 'mcs', 's' => 'mcs', 'p' => 'pro'] as $dim => $section) {
                if ($this->top3($a[$section], $dim) !== $this->top3($b[$section], $dim)) {
                    $dimensionChanges[$dim]++;
                    $delta = true;
                }
            }
            if ($delta) {
                $changed++;
            }
        }

        $report = [
            'baseline' => $baseline->slug,
            'candidate' => $candidate->slug,
            'assessments_compared' => $compared,
            'results_with_top3_changes' => $changed,
            'pct_changed' => $compared > 0 ? round(100 * $changed / $compared, 1) : null,
            'changes_by_dimension' => $dimensionChanges,
            'generated_at' => now()->toIso8601String(),
        ];
        $candidate->update(['impact' => $report]);

        return $report;
    }

    /**
     * Drift of the accumulated empirical distribution vs a set's table: per
     * scale, the max absolute gap between empirical cumulative proportion and
     * the set's normed value at each observed raw (a KS-style statistic).
     *
     * @return array<int, float> toolScaleDetailKey → max abs gap
     */
    public function drift(string $language, ?string $gender, NormSet $against): array
    {
        $empirical = $this->deriveTable($language, $gender);
        $reference = $against->table();

        $drift = [];
        foreach ($empirical as $scale => $raws) {
            $max = 0.0;
            foreach ($raws as $raw => $proportion) {
                $ref = $reference[$scale][$raw] ?? null;
                if ($ref !== null) {
                    $max = max($max, abs($proportion - $ref));
                }
            }
            $drift[$scale] = round($max, 4);
        }

        return $drift;
    }

    /** Top-3 area names for a dimension, from a {area: {dim: rank}} section. */
    private function top3(array $section, string $dim): array
    {
        $ranks = [];
        foreach (ResultAssembler::AREAS as $area) {
            $rank = (int) ($section[$area][$dim] ?? 0);
            if ($rank >= 1 && $rank <= 3) {
                $ranks[$rank] = $area;
            }
        }
        ksort($ranks);

        return array_values($ranks);
    }
}
