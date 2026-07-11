<?php

namespace App\Console\Commands;

use App\Models\NormSample;
use App\Models\NormSet;
use App\Scoring\Norms\NormAnalytics;
use Illuminate\Console\Command;

/**
 * Norm-set lifecycle (docs/06 + decided policy 2026-07-11): candidates are
 * buildable once the ≥400/scale threshold is met; promotion candidate →
 * active is ALWAYS a manual action taken after reviewing the impact report —
 * this command refuses to promote without one.
 */
class NormsLifecycle extends Command
{
    protected $signature = 'norms:lifecycle {action : status|build-candidate|impact|promote|retire}
        {slug? : Norm set slug (required for impact/promote/retire, and names the new set for build-candidate)}
        {--language= : Population language (build-candidate)}
        {--gender= : M, F, or omit for pooled (build-candidate)}
        {--limit=200 : Assessments to rescore for the impact report}
        {--force : Build below the sample threshold (marks the set provisional)}';

    protected $description = 'Norm set lifecycle: status, build-candidate, impact, promote, retire';

    public function handle(NormAnalytics $analytics): int
    {
        return match ($this->argument('action')) {
            'status' => $this->status($analytics),
            'build-candidate' => $this->buildCandidate($analytics),
            'impact' => $this->impact($analytics),
            'promote' => $this->promote(),
            'retire' => $this->retire(),
            default => $this->abort("Unknown action '{$this->argument('action')}'."),
        };
    }

    private function status(NormAnalytics $analytics): int
    {
        $this->table(
            ['Slug', 'Status', 'Language', 'Gender', 'Provisional', 'Entries', 'Activated'],
            NormSet::query()->withCount('entries')->orderBy('id')->get()->map(fn (NormSet $s) => [
                $s->slug, $s->status, $s->language ?? 'all', $s->gender ?? 'pooled',
                $s->provisional ? 'yes' : 'no', $s->entries_count, $s->activated_at?->toDateString() ?? '—',
            ])->all(),
        );

        $populations = NormSample::query()
            ->selectRaw('language, gender')->groupBy('language', 'gender')->get();
        foreach ($populations as $p) {
            $sizes = $analytics->sampleSizes($p->language, $p->gender);
            $this->line("Samples {$p->language}/".($p->gender ?? 'pooled').': '
                .collect($sizes)->map(fn ($n, $scale) => "scale {$scale}: {$n}/".NormAnalytics::SAMPLE_THRESHOLD)->implode(', '));
        }

        return self::SUCCESS;
    }

    private function buildCandidate(NormAnalytics $analytics): int
    {
        $slug = $this->argument('slug') ?? $this->abort('build-candidate needs a slug for the new set.');
        $language = $this->option('language') ?? $this->abort('--language is required.');

        $set = $analytics->buildCandidate($slug, $language, $this->option('gender') ?: null, (bool) $this->option('force'));
        $this->info("Candidate '{$set->slug}' built (".$set->entries()->count().' entries'.($set->provisional ? ', PROVISIONAL — below threshold' : '').').');
        $this->line("Next: php artisan norms:lifecycle impact {$set->slug}");

        return self::SUCCESS;
    }

    private function impact(NormAnalytics $analytics): int
    {
        $set = $this->findSet();
        $report = $analytics->impactReport($set, (int) $this->option('limit'));
        $this->info("Impact vs '{$report['baseline']}': {$report['pct_changed']}% of {$report['assessments_compared']} assessments change top-3 codes.");
        $this->line('By dimension: '.json_encode($report['changes_by_dimension']));
        $this->line('Stored on the set — review before promoting.');

        return self::SUCCESS;
    }

    private function promote(): int
    {
        $set = $this->findSet();
        if ($set->status !== 'candidate') {
            return $this->abort("Only candidate sets can be promoted; '{$set->slug}' is {$set->status}.");
        }
        if ($set->impact === null) {
            return $this->abort('No impact report on this set — run norms:lifecycle impact first (promotion policy: manual sign-off on the impact report).');
        }

        if (! $this->confirm("Impact: {$set->impact['pct_changed']}% of results change top-3 codes vs '{$set->impact['baseline']}'. Promote '{$set->slug}' to active?")) {
            $this->line('Aborted.');

            return self::SUCCESS;
        }

        $replaced = $set->replaces();
        $replaced?->update(['status' => 'retired', 'retired_at' => now()]);
        $set->update(['status' => 'active', 'activated_at' => now()]);
        $this->info("'{$set->slug}' is now active".($replaced ? " (retired '{$replaced->slug}')" : '').'.');

        return self::SUCCESS;
    }

    private function retire(): int
    {
        $set = $this->findSet();
        $set->update(['status' => 'retired', 'retired_at' => now()]);
        $this->info("'{$set->slug}' retired (remains queryable; historical results keep referencing it).");

        return self::SUCCESS;
    }

    private function findSet(): NormSet
    {
        $slug = $this->argument('slug') ?? $this->abort('This action needs a norm set slug.');

        return NormSet::query()->where('slug', $slug)->first() ?? $this->abort("No norm set '{$slug}'.");
    }

    private function abort(string $message): never
    {
        $this->error($message);
        exit(self::FAILURE);
    }
}
