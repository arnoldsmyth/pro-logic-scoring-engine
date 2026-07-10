<?php

namespace Tests\Feature;

use App\Scoring\Contracts\ScoringEngine;
use App\Scoring\GoldenMaster\GoldenRepository;
use App\Scoring\GoldenMaster\ResultDiff;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * PHPUnit face of the golden-master gate (docs/10): both result formats for
 * every golden must reproduce exactly. Runs against the dev database (where
 * `legacy:import` loads the config) and skips when the goldens directory is
 * absent — they contain PII and never enter git, so hosted CI won't have
 * them. `php artisan goldens:verify` gives the detailed per-field report.
 */
#[Group('goldens')]
class GoldenMasterTest extends TestCase
{
    public function test_all_golden_masters_reproduce_legacy_results(): void
    {
        $goldens = new GoldenRepository;
        if (! $goldens->available()) {
            $this->markTestSkipped("Goldens not present at {$goldens->path()} (expected off-repo).");
        }

        // The in-memory test DB has no legacy config; point at the dev
        // database legacy:import populated (the engine never writes).
        $devDb = base_path('database/database.sqlite');
        if (is_file($devDb)) {
            config(['database.connections.sqlite.database' => $devDb]);
            DB::purge('sqlite');
        }
        if (! Schema::hasTable('ToolRule')) {
            $this->markTestSkipped('Legacy config not imported — run `php artisan legacy:import` first.');
        }

        $sessions = $goldens->all();
        $this->assertNotEmpty($sessions, 'Goldens directory exists but contains no sessions.');

        $engine = $this->app->make(ScoringEngine::class);
        $failures = [];
        foreach ($sessions as $session) {
            $registration = $session->registration();
            $normSet = strtoupper($registration['gender'] ?? '') === 'F' ? 'female-legacy' : 'male-legacy';

            $actual = $engine->score($registration, $session->tools(), ['full'], $normSet);

            $diffs = [
                ...ResultDiff::diff($session->expectedKeys(), $actual),
                ...ResultDiff::diff(
                    $session->expectedStrings(),
                    $engine->score($registration, $session->tools(), ['full'], $normSet, 'strings'),
                ),
            ];
            if ($diffs !== []) {
                $failures[] = "{$session->sessionKey}: ".count($diffs).' diffs, first at '.$diffs[0]['path'];
            }
        }

        $this->assertSame([], $failures, count($failures).'/'.count($sessions).' golden masters failing');
    }
}
