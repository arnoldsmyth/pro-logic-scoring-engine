<?php

namespace App\Scoring\Engine;

/**
 * Collects the raw PZSD scale scores a scoring run converts (docs/06
 * continuous-evaluation layer). These are the pre-conversion inputs to the
 * norm lookup — exactly the observations candidate norm sets are derived
 * from. Aggregate values only; the caller attaches language/gender context
 * when persisting.
 */
class NormSampler
{
    /** @var list<array{scale: int, raw: float}> */
    public array $observations = [];

    public function observe(int $scaleKey, float $raw): void
    {
        $this->observations[] = ['scale' => $scaleKey, 'raw' => $raw];
    }
}
