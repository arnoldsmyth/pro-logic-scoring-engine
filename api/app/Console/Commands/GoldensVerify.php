<?php

namespace App\Console\Commands;

use App\Scoring\Contracts\ScoringEngine;
use App\Scoring\EngineNotImplemented;
use App\Scoring\GoldenMaster\GoldenRepository;
use App\Scoring\GoldenMaster\GoldenSession;
use App\Scoring\GoldenMaster\ResultDiff;
use Illuminate\Console\Command;
use Throwable;

/**
 * The correctness gate (docs/10): replay every golden-master session through
 * the engine and diff against the legacy results. Deploy blocks on 68/68.
 */
class GoldensVerify extends Command
{
    protected $signature = 'goldens:verify
        {--session=* : Only these session keys}
        {--max-diffs=10 : Field diffs to print per failing session}';

    protected $description = 'Replay golden-master sessions through the scoring engine and diff against legacy results';

    public function handle(GoldenRepository $goldens, ScoringEngine $engine): int
    {
        if (! $goldens->available()) {
            $this->warn("Goldens directory not found: {$goldens->path()}");
            $this->line('Golden masters live outside git (PII). Set GOLDENS_PATH or run on a machine with taicode/restore-db/goldens/.');

            return self::INVALID;
        }

        $sessions = $goldens->all();
        if ($only = $this->option('session')) {
            $sessions = array_values(array_filter($sessions, fn ($s) => in_array($s->sessionKey, $only, true)));
        }
        if ($sessions === []) {
            $this->error('No golden sessions matched.');

            return self::FAILURE;
        }

        $passed = 0;
        $failed = 0;
        foreach ($sessions as $session) {
            if ($this->verifySession($session, $engine)) {
                $passed++;
            } else {
                $failed++;
            }
        }

        $total = count($sessions);
        $this->newLine();
        if ($failed === 0) {
            $this->info("{$passed}/{$total} golden masters pass.");

            return self::SUCCESS;
        }
        $this->error("{$passed}/{$total} golden masters pass ({$failed} failing).");

        return self::FAILURE;
    }

    private function verifySession(GoldenSession $session, ScoringEngine $engine): bool
    {
        $registration = $session->registration();
        $normSet = strtoupper($registration['gender'] ?? '') === 'F' ? 'female-legacy' : 'male-legacy';

        try {
            $actual = $engine->score($registration, $session->tools(), ['full'], $normSet);
        } catch (EngineNotImplemented $e) {
            $this->line("<comment>SKIP</comment> {$session->sessionKey}: {$e->getMessage()}");

            return false;
        } catch (Throwable $e) {
            $this->line("<error>ERR </error> {$session->sessionKey}: {$e->getMessage()}");

            return false;
        }

        $diffs = ResultDiff::diff($session->expectedKeys(), $actual);
        if ($diffs === []) {
            $this->line("<info>PASS</info> {$session->sessionKey}");

            return true;
        }

        $this->line("<error>FAIL</error> {$session->sessionKey}: ".count($diffs).' field diffs');
        foreach (array_slice($diffs, 0, (int) $this->option('max-diffs')) as $diff) {
            $this->line(sprintf(
                '       %-9s %s  expected=%s actual=%s',
                $diff['kind'],
                $diff['path'],
                json_encode($diff['expected']),
                json_encode($diff['actual']),
            ));
        }
        if ($legacy = $session->outstringsPath()) {
            $this->line("       legacy per-field trace: {$legacy}");
        }

        return false;
    }
}
