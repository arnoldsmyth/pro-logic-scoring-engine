<?php

namespace App\Console\Commands;

use App\Models\NormSet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Registers the two legacy gender norm sets as first-class norm_sets rows,
 * copying PZSDConversionMatrix verbatim (docs/06 launch sets — golden-master
 * fidelity). Idempotent: existing sets are left untouched.
 */
class NormsSeedLegacy extends Command
{
    protected $signature = 'norms:seed-legacy';

    protected $description = 'Create male-legacy / female-legacy norm sets verbatim from PZSDConversionMatrix';

    public function handle(): int
    {
        foreach (['M' => 'male-legacy', 'F' => 'female-legacy'] as $gender => $slug) {
            if (NormSet::query()->where('slug', $slug)->exists()) {
                $this->line("{$slug}: already present, skipping.");

                continue;
            }

            $rows = DB::table('PZSDConversionMatrix')
                ->whereRaw('TRIM(Gender) = ?', [$gender])
                ->get(['ToolScaleDetailKey', 'Raw', 'Normed']);
            if ($rows->isEmpty()) {
                $this->error("PZSDConversionMatrix has no rows for gender {$gender} — run legacy:import first.");

                return self::FAILURE;
            }

            $perScale = $rows->groupBy('ToolScaleDetailKey')->map->count();

            $set = NormSet::create([
                'slug' => $slug,
                'status' => 'active',
                'language' => null, // legacy sets apply to every language
                'gender' => $gender,
                'description' => 'Migrated verbatim from legacy PZSDConversionMatrix (gender '.$gender.').',
                'provenance' => [
                    'source' => 'PZSDConversionMatrix',
                    'method' => 'verbatim legacy migration',
                    'entries_per_scale' => $perScale->all(),
                ],
                'activated_at' => now(),
            ]);

            $set->entries()->createMany($rows->map(fn ($r) => [
                'tool_scale_detail_key' => (int) $r->ToolScaleDetailKey,
                'raw' => (float) $r->Raw,
                'normed' => (float) $r->Normed,
            ])->all());

            $this->info("{$slug}: created with {$rows->count()} entries across ".$perScale->count().' scales.');
        }

        return self::SUCCESS;
    }
}
