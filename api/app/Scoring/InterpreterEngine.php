<?php

namespace App\Scoring;

use App\Scoring\Contracts\ScoringEngine;

/**
 * The data-driven rule interpreter (docs/04): Tools → Package → Profile →
 * Insight stages executing rule rows from the imported legacy config via
 * ~15 math primitives. Built in phase 4, validated by `goldens:verify`
 * until 68/68 golden masters reproduce exactly.
 */
class InterpreterEngine implements ScoringEngine
{
    public function score(array $registration, array $tools, array $scopes = ['full'], string $normSet = 'male-legacy'): array
    {
        throw new EngineNotImplemented('Scoring engine not built yet — see beads phase 4 epic.');
    }
}
