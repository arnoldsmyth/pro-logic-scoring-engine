<?php

namespace App\Scoring;

use App\Scoring\Contracts\ScoringEngine;
use App\Scoring\Engine\LegacyConfig;
use App\Scoring\Engine\ProductCatalog;
use App\Scoring\Engine\ResultAssembler;
use App\Scoring\Engine\RuleInterpreter;
use App\Scoring\Engine\SessionState;
use RuntimeException;

/**
 * The data-driven rule interpreter (docs/04): Tools → Package → Profile →
 * Insight, executing rule rows from the imported legacy config. Correctness
 * contract: reproduces legacy sp_Score outputs exactly for all 68 golden
 * masters (`php artisan goldens:verify`).
 *
 * Norm sets: 'male-legacy'/'female-legacy' reproduce the legacy gender-split
 * PZSD lookup; versioned norm sets arrive in phase 6 (docs/06). Which
 * version bundle gets scored is selected by `$productCode` (ProductCatalog)
 * — the engine has no hardcoded product.
 */
class InterpreterEngine implements ScoringEngine
{
    public function __construct(private readonly LegacyConfig $config) {}

    public function score(array $registration, array $tools, array $scopes = ['full'], string $normSet = 'male-legacy', string $format = 'keys', string $productCode = ProductCatalog::DEFAULT_CODE): array
    {
        $product = ProductCatalog::get($productCode);

        // The norm set IS the gender table selection in legacy scoring;
        // callers pass the set matching the registrant's gender.
        $gender = match ($normSet) {
            'male-legacy' => 'M',
            'female-legacy' => 'F',
            default => throw new RuntimeException("Norm set '{$normSet}' not available until phase 6 — use male-legacy/female-legacy."),
        };

        $language = strtolower(trim($registration['language'] ?? 'en'));
        $languageKey = LegacyConfig::LANGUAGES[$language]
            ?? throw new RuntimeException("Unsupported language '{$language}'");

        $state = new SessionState($gender, $languageKey);
        $this->ingest($state, $tools, $languageKey, $product['toolVersions']);

        $interpreter = new RuleInterpreter($this->config, $state);
        $toolVersionKeys = array_values(array_intersect_key($product['toolVersions'], $tools));
        $interpreter->runStage('Tool', $this->config->toolRules($toolVersionKeys));
        $interpreter->runStage('Package', $this->config->packageRules($product['packageVersionKey']));
        $interpreter->runStage('Profile', $this->config->profileRules($product['profileVersionKey']));
        $interpreter->runStage('Insight', $this->config->insightRules($product['insightScoreVersionKey']));

        $assembler = new ResultAssembler;

        return $format === 'strings' ? $assembler->assembleStrings($state) : $assembler->assemble($state);
    }

    /**
     * The intake path of the legacy web service: each answer {q, a} becomes
     * a RawResponse against the QuestionTROut of the session-language
     * question with Question.Sequence = q (mapping verified against replica
     * RawResponse data). Free-text tools (reflections) never enter
     * vwValueByScale — their values are not numeric.
     *
     * @param  array<string, int>  $toolVersions
     */
    private function ingest(SessionState $state, array $tools, int $languageKey, array $toolVersions): void
    {
        foreach ($tools as $tool => $responses) {
            $toolVersionKey = $toolVersions[$tool]
                ?? throw new RuntimeException("Unknown tool '{$tool}' for this product");
            if ($tool === 'reflections') {
                continue;
            }
            $questions = $this->config->questionMap($toolVersionKey, $languageKey);
            foreach ($responses as $q => $a) {
                $question = $questions[$q]
                    ?? throw new RuntimeException("Tool '{$tool}' has no question {$q}");
                $state->rawResponses[] = [
                    'trOutKey' => $question['trOutKey'],
                    'toolScaleDetailKey' => $question['toolScaleDetailKey'],
                    'gender' => $state->gender,
                    'response' => (float) $a,
                ];
            }
        }
    }
}
