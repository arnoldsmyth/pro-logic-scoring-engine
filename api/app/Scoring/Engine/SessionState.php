<?php

namespace App\Scoring\Engine;

/**
 * In-memory equivalent of the legacy per-session tables: vwValueByScale
 * (raw responses joined to scale mapping), the four {Stage}ScaleValue
 * tables, and SessionOutString. Row shapes mirror the legacy columns; all
 * responses are floats (legacy columns are float).
 */
class SessionState
{
    /**
     * vwValueByScale rows.
     *
     * @var list<array{trOutKey: int, toolScaleDetailKey: ?int, gender: string, response: float}>
     */
    public array $rawResponses = [];

    /**
     * {Stage}ScaleValue rows, keyed by stage (Tool|Package|Profile|Insight).
     * Tool rows use scaleKey = ToolScaleDetailKey (+ gender); the rest use
     * scaleKey = FrameworkKey. Insertion order is preserved (legacy heaps).
     *
     * @var array<string, list<array{ruleKey: int, scaleKey: ?int, gender: ?string, response: float, sequence: ?int}>>
     */
    public array $scaleValues = ['Tool' => [], 'Package' => [], 'Profile' => [], 'Insight' => []];

    /**
     * SessionOutString rows.
     *
     * @var list<array{typeKey: int, string: ?string, sequence: ?int, archetypeDetailKey: ?int, insightDetailKey: ?int}>
     */
    public array $outStrings = [];

    /** Stage failure messages, per stage — mirrors {Stage}Status Passed=0 rows. */
    public array $failures = [];

    public function __construct(
        public readonly string $gender,
        public readonly int $languageKey,
    ) {}

    public function addScaleValue(string $stage, int $ruleKey, ?int $scaleKey, ?string $gender, float $response, ?int $sequence = null): void
    {
        $this->scaleValues[$stage][] = [
            'ruleKey' => $ruleKey,
            'scaleKey' => $scaleKey,
            'gender' => $gender,
            'response' => $response,
            'sequence' => $sequence,
        ];
    }

    public function addOutString(int $typeKey, ?string $string, ?int $sequence, ?int $archetypeDetailKey = null, ?int $insightDetailKey = null): void
    {
        $this->outStrings[] = [
            'typeKey' => $typeKey,
            'string' => $string,
            'sequence' => $sequence,
            'archetypeDetailKey' => $archetypeDetailKey,
            'insightDetailKey' => $insightDetailKey,
        ];
    }

    /** @return list<array{ruleKey: int, scaleKey: ?int, gender: ?string, response: float, sequence: ?int}> */
    public function valuesOfRules(string $stage, array $ruleKeys): array
    {
        return array_values(array_filter(
            $this->scaleValues[$stage],
            fn ($row) => in_array($row['ruleKey'], $ruleKeys, true),
        ));
    }
}
