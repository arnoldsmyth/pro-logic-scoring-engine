<?php

namespace App\Scoring\Engine;

/**
 * Opt-in explainability trace (docs/03): every rule fired in cursor order,
 * the intermediate scale values each stage produced, and the content keys
 * the insight stage resolved. Backs the control panel's "under the hood"
 * requirement (docs/08) and golden-master debugging.
 */
class AuditCollector
{
    /** @var list<array{stage: string, rule: int, proc: string}> */
    public array $rulesFired = [];

    /** @var array<string, list<array<string, mixed>>> stage => scale value rows */
    public array $stageValues = [];

    public function ruleFired(string $stage, int $ruleKey, string $proc): void
    {
        $this->rulesFired[] = ['stage' => $stage, 'rule' => $ruleKey, 'proc' => $proc];
    }

    /** @param list<array<string, mixed>> $scaleValues */
    public function stageComplete(string $stage, array $scaleValues): void
    {
        $this->stageValues[$stage] = $scaleValues;
    }

    /** @var list<array<string, mixed>> */
    private array $contentKeys = [];

    /** Called by the engine once all stages have run. */
    public function finalize(SessionState $state): void
    {
        $this->contentKeys = [];
        foreach ($state->outStrings as $row) {
            if ($row['archetypeDetailKey'] !== null || $row['insightDetailKey'] !== null) {
                $this->contentKeys[] = [
                    'out_string_type' => $row['typeKey'],
                    'sequence' => $row['sequence'],
                    'archetype_detail_key' => $row['archetypeDetailKey'],
                    'insight_detail_key' => $row['insightDetailKey'],
                ];
            }
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'rules_fired' => $this->rulesFired,
            'stage_scale_values' => $this->stageValues,
            'content_keys_resolved' => $this->contentKeys,
        ];
    }
}
