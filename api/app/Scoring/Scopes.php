<?php

namespace App\Scoring;

use App\Scoring\Engine\ResultAssembler;

/**
 * Scoring scopes, requestable à la carte (docs/04 dependency matrix). Each
 * scope names the tools it needs and the slice of the full results body it
 * returns. The engine always runs the full rule set over whatever tools are
 * present — rules whose sources are absent simply produce nothing, exactly
 * like the legacy cursors over empty tables — and the requested scopes are
 * what gets surfaced, so partially-computed dimensions are never returned.
 */
class Scopes
{
    private const SELF_TOOLS = ['areamissions', 'personalmotivators', 'abilitiesfilter', 'personalstyle', 'personalexpectations', 'person'];

    /**
     * scope => {tools: required tools, gendered: does scoring touch the
     * S or P dimensions (gender-split norms, docs/04)}.
     */
    public const SCOPES = [
        'mcs.m' => ['tools' => ['areamissions', 'personalmotivators'], 'gendered' => false],
        'mcs.c' => ['tools' => ['abilitiesfilter'], 'gendered' => false],
        'mcs.s' => ['tools' => ['person', 'personalexpectations', 'personalstyle'], 'gendered' => true],
        'mcs' => ['tools' => self::SELF_TOOLS, 'gendered' => true],
        'pro.person' => ['tools' => self::SELF_TOOLS, 'gendered' => true],
        'pro.role' => ['tools' => ['role'], 'gendered' => false],
        'pro.org' => ['tools' => ['organization'], 'gendered' => false],
        'insights' => ['tools' => self::SELF_TOOLS, 'gendered' => true],
        'reflections' => ['tools' => ['reflections'], 'gendered' => false],
    ];

    /** 'full' = all 9 tools = every scope (docs/04). */
    public const FULL = ['mcs', 'pro.person', 'pro.role', 'pro.org', 'insights', 'reflections'];

    /** Reflections echo field names by question block (docs/02 + 03). */
    private const REFLECTION_FIELDS = [
        'RoleAtWork' => [1, 6],
        'Best_Qual' => [7, 6],
        'Least_Qual' => [13, 6],
        'KeyDevNeed' => [19, 4],
        'FiveYearRole' => [23, 6],
    ];

    /**
     * Expand aliases and validate. Returns [scopes, unknown].
     *
     * @param  list<string>  $requested
     * @return array{0: list<string>, 1: list<string>}
     */
    public static function expand(array $requested): array
    {
        $scopes = [];
        $unknown = [];
        foreach ($requested as $scope) {
            if ($scope === 'full') {
                $scopes = [...$scopes, ...self::FULL];
            } elseif (isset(self::SCOPES[$scope])) {
                $scopes[] = $scope;
            } else {
                $unknown[] = $scope;
            }
        }

        return [array_values(array_unique($scopes)), $unknown];
    }

    /**
     * The full set of scopes an allowance list grants (docs/07 permission
     * check). Unlike request expansion, allowances are hierarchical: 'full'
     * grants everything and 'mcs' grants its per-dimension sub-scopes.
     *
     * @param  list<string>  $allowed  as stored on the access code
     * @return list<string>
     */
    public static function allowedSet(array $allowed): array
    {
        if (in_array('full', $allowed, true)) {
            return array_keys(self::SCOPES);
        }

        [$set] = self::expand($allowed);
        if (in_array('mcs', $set, true)) {
            $set = [...$set, 'mcs.m', 'mcs.c', 'mcs.s'];
        }

        return array_values(array_unique($set));
    }

    /**
     * Tools each requested scope still needs (docs/05: 422 lists missing
     * tools per scope).
     *
     * @param  list<string>  $scopes  expanded scope names
     * @param  list<string>  $submitted  tool names present and valid
     * @return array<string, list<string>> scope => missing tools (only scopes with gaps)
     */
    public static function missingTools(array $scopes, array $submitted): array
    {
        $missing = [];
        foreach ($scopes as $scope) {
            $gap = array_values(array_diff(self::SCOPES[$scope]['tools'], $submitted));
            if ($gap !== []) {
                $missing[$scope] = $gap;
            }
        }

        return $missing;
    }

    /** @param list<string> $scopes */
    public static function anyGendered(array $scopes): bool
    {
        return array_filter($scopes, fn (string $s) => self::SCOPES[$s]['gendered']) !== [];
    }

    /**
     * Union of tools needed by the requested scopes — what the engine gets.
     *
     * @param  list<string>  $scopes
     * @return list<string>
     */
    public static function requiredTools(array $scopes): array
    {
        return array_values(array_unique(array_merge(...array_map(fn (string $s) => self::SCOPES[$s]['tools'], $scopes))));
    }

    /**
     * Slice the engine's full results body ({mcs, pro, etc}) plus the raw
     * reflections responses into the per-scope payload map for the result
     * envelope (docs/05).
     *
     * @param  array<string, mixed>  $results  engine keys- or strings-format body
     * @param  list<string>  $scopes
     * @param  array<int|string, mixed>|null  $reflections  raw {q: a} responses
     * @return array<string, mixed> scope => payload
     */
    public static function filter(array $results, array $scopes, ?array $reflections): array
    {
        $out = [];
        foreach ($scopes as $scope) {
            $out[$scope] = match ($scope) {
                'mcs' => $results['mcs'] ?? null,
                'mcs.m', 'mcs.c', 'mcs.s' => self::column($results['mcs'] ?? [], substr($scope, 4)),
                'pro.person' => self::column($results['pro'] ?? [], 'p'),
                'pro.role' => self::column($results['pro'] ?? [], 'r'),
                'pro.org' => self::column($results['pro'] ?? [], 'o'),
                'insights' => $results['etc'] ?? null,
                'reflections' => $reflections === null ? null : self::echoReflections($reflections),
            };
        }

        return $out;
    }

    /** @param array<string, array<string, mixed>> $section */
    private static function column(array $section, string $key): array
    {
        $out = [];
        foreach (ResultAssembler::AREAS as $area) {
            if (isset($section[$area][$key])) {
                $out[$area] = $section[$area][$key];
            }
        }

        return $out;
    }

    /** @param array<int|string, mixed> $responses */
    private static function echoReflections(array $responses): array
    {
        $out = [];
        foreach (self::REFLECTION_FIELDS as $field => [$start, $n]) {
            for ($i = 0; $i < $n; $i++) {
                $out["{$field}_".($i + 1)] = $responses[$start + $i] ?? $responses[(string) ($start + $i)] ?? null;
            }
        }

        return $out;
    }
}
