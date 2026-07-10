<?php

namespace App\Scoring\Contracts;

use App\Scoring\Engine\ProductCatalog;

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
     * @param  string  $format  'keys' (results.format 1) or 'strings' (format 2, resolved content text)
     * @param  string  $productCode  which catalog product/version-bundle to score against (ProductCatalog, docs/07)
     * @return array<string, mixed>
     */
    public function score(array $registration, array $tools, array $scopes = ['full'], string $normSet = 'male-legacy', string $format = 'keys', string $productCode = ProductCatalog::DEFAULT_CODE): array;
}
