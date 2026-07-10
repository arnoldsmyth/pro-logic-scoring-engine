<?php

namespace App\Scoring\Contracts;

use App\Scoring\EngineNotImplemented;

interface ScoringEngine
{
    /**
     * Score a session and return the results body in keys format:
     * {mcs: {<area>: {m,c,s}}, pro: {<area>: {p,r,o}}, etc: {...}, reflections?}.
     *
     * @param  array{gender?: string, language?: string}  $registration
     * @param  array<string, array<int, int|string>>  $tools  tool name => [q => a], q 1-based
     * @param  list<string>  $scopes  e.g. ['full'], ['mcs'], ['pro.role'] (docs/04 scope table)
     * @param  string  $normSet  male-legacy | female-legacy | pooled | <norm set id> (docs/06)
     * @return array<string, mixed>
     *
     * @throws EngineNotImplemented until phase 4 lands
     */
    public function score(array $registration, array $tools, array $scopes = ['full'], string $normSet = 'male-legacy'): array;
}
