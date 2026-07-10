<?php

namespace Tests\Feature;

use App\Scoring\Contracts\ScoringEngine;
use App\Scoring\EngineNotImplemented;
use App\Scoring\GoldenMaster\GoldenRepository;
use App\Scoring\GoldenMaster\ResultDiff;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * PHPUnit face of the golden-master gate (docs/10). Skips when the goldens
 * directory is absent (they contain PII and never enter git, so hosted CI
 * won't have them) and while the engine is unbuilt. `php artisan
 * goldens:verify` gives the detailed per-field report.
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

        $sessions = $goldens->all();
        $this->assertNotEmpty($sessions, 'Goldens directory exists but contains no sessions.');

        $engine = $this->app->make(ScoringEngine::class);
        $failures = [];
        foreach ($sessions as $session) {
            $registration = $session->registration();
            $normSet = strtoupper($registration['gender'] ?? '') === 'F' ? 'female-legacy' : 'male-legacy';

            try {
                $actual = $engine->score($registration, $session->tools(), ['full'], $normSet);
            } catch (EngineNotImplemented) {
                $this->markTestIncomplete('Scoring engine not implemented yet (phase 4).');
            }

            $diffs = ResultDiff::diff($session->expectedKeys(), $actual);
            if ($diffs !== []) {
                $failures[] = "{$session->sessionKey}: ".count($diffs).' diffs, first at '.$diffs[0]['path'];
            }
        }

        $this->assertSame([], $failures, count($failures).'/'.count($sessions).' golden masters failing');
    }
}
