<?php

namespace App\Scoring\Engine;

use RuntimeException;

/**
 * Port of the legacy rule-dispatch machinery: sp_ScoreTools/Package/Profile/
 * Insight cursors plus every primitive proc the current live product config
 * invokes (see ProductCatalog). Each primitive method is a line-for-line
 * translation of its stored procedure (reference bodies:
 * platform/original-source/databasescript20260225.sql); comments cite the
 * semantic quirks that MUST be preserved for golden parity.
 */
class RuleInterpreter
{
    public function __construct(
        private readonly LegacyConfig $config,
        private readonly SessionState $state,
        private readonly ?AuditCollector $audit = null,
    ) {}

    /** Run one stage's rule list in legacy cursor order. */
    public function runStage(string $stage, array $rules): void
    {
        foreach ($rules as $rule) {
            $this->audit?->ruleFired($stage, (int) $rule->ruleKey, trim($rule->proc));
            $this->dispatch($stage, (int) $rule->ruleKey, trim($rule->proc));
        }
        $this->audit?->stageComplete($stage, $this->state->scaleValues[$stage]);
    }

    private function dispatch(string $stage, int $ruleKey, string $proc): void
    {
        $params = $this->config->ruleParams($stage, $ruleKey);

        match ($proc) {
            'sp_Sum' => $this->sum($stage, $ruleKey),
            'sp_SumResponseValue' => $this->sumResponseValue($stage, $ruleKey, (int) $params['@tiTargetScale']),
            'sp_Average' => $this->average($stage, $ruleKey),
            'sp_SubtractByConstant' => $this->byConstant($stage, $ruleKey, fn ($r) => $r - (float) $params['@tiConstant']),
            'sp_MultiplyByConstant' => $this->byConstant($stage, $ruleKey, fn ($r) => $r * (float) $params['@tfConstant']),
            'sp_DivideByConstant' => $this->divideByConstant($stage, $ruleKey, (float) $params['@tfConstant']),
            'sp_DivideByValue' => $this->divideByValue($stage, $ruleKey, (int) $params['@tiRespSource']),
            'sp_ConvertResponse' => $this->convertResponse($stage, $ruleKey, (int) $params['@tiResponseWeightKey']),
            'sp_ConvertPZSD' => $this->convertPzsd($stage, $ruleKey),
            'sp_CalibratePZSD' => $this->calibratePzsd($stage, $ruleKey),
            'sp_ConvertPXI' => $this->convertPxi($stage, $ruleKey),
            'sp_PXIScore' => $this->pxiScore($stage, $ruleKey),
            'sp_PXISpecificType' => $this->pxiSpecificType($stage, $ruleKey),
            'sp_ConvertToolFramework' => $this->convertToolFramework($stage, $ruleKey),
            'sp_ConvertSCTMatrix' => $this->convertSctMatrix($stage, $ruleKey),
            'sp_ConvertCCTMatrix' => $this->convertCctMatrix($stage, $ruleKey),
            'sp_ConvertPXISCTMatrix' => $this->convertPxiSctMatrix($stage, $ruleKey),
            'sp_RankScaleOutput' => $this->rankScaleOutput($stage, $ruleKey, (int) ($params['@tiSortDesc'] ?? 0)),
            'sp_ConvertToArchetypeID' => $this->convertToArchetypeId($stage, $ruleKey),
            'sp_CreateKeyCode' => $this->createKeyCode($stage, $ruleKey, (string) $params['@tiRowsToConcat']),
            'sp_StoreKeyCode' => $this->storeKeyCode($stage, $ruleKey, (int) $params['@tiSOSType']),
            'sp_StoreValue' => $this->storeValue($stage, $ruleKey, (int) $params['@tiSOSType']),
            'sp_StoreID' => $this->storeId($stage, $ruleKey, (int) $params['@tiSOSType']),
            'sp_StoreFrameworkData' => $this->storeFrameworkData($stage, $ruleKey, (int) $params['@tiSOSType'], (int) $params['@tiArchetypeDetailType']),
            'sp_StoreCaseData' => $this->storeCaseData($stage, $ruleKey, (int) $params['@tiSOSType'], (int) $params['@tiCatType']),
            // Registration echo / reflections echo write SessionOutString
            // rows the JSON results never read (verified against all goldens
            // with full outstrings) — no-ops for scoring purposes.
            'StoreClientStrings', 'sp_StorePROD33Reflections' => null,
            default => throw new RuntimeException("Unimplemented primitive {$proc} ({$stage} rule {$ruleKey})"),
        };
    }

    // ------------------------------------------------------------------
    // Source resolution (sp_CreateSourceTable)

    /** @return list<array{ruleKey: ?int, scaleKey: ?int, gender: ?string, response: float, sequence: ?int}> */
    private function sourceRows(string $stage, int $ruleKey): array
    {
        $src = $this->config->ruleSource($stage, $ruleKey);

        return $this->rowsFor($src['type'], $src['keys']);
    }

    private function rowsFor(?string $type, array $keys): array
    {
        return match ($type) {
            'TV' => array_values(array_filter(array_map(
                fn ($r) => ['ruleKey' => null, 'scaleKey' => $r['toolScaleDetailKey'], 'gender' => $r['gender'], 'response' => $r['response'], 'sequence' => null, 'trOutKey' => $r['trOutKey']],
                $this->state->rawResponses,
            ), fn ($r) => in_array($r['trOutKey'], $keys, true))),
            'TO' => $this->state->valuesOfRules('Tool', $keys),
            'PO' => $this->state->valuesOfRules('Package', $keys),
            'PR' => $this->state->valuesOfRules('Profile', $keys),
            'IN' => $this->state->valuesOfRules('Insight', $keys),
            default => throw new RuntimeException("Unsupported SrcType '{$type}'"),
        };
    }

    /** Group key per legacy @lsSQLfields: Tool → (scale, gender); else → (framework). */
    private function groupKey(string $stage, array $row): string
    {
        return $stage === 'Tool'
            ? ($row['scaleKey'] ?? 'ø').'|'.($row['gender'] ?? 'ø')
            : (string) ($row['scaleKey'] ?? 'ø');
    }

    /** T-SQL ROUND(x, 6). */
    private static function round6(float $v): float
    {
        return round($v, 6);
    }

    // ------------------------------------------------------------------
    // Aggregation

    private function sum(string $stage, int $ruleKey): void
    {
        foreach ($this->grouped($stage, $this->sourceRows($stage, $ruleKey)) as $g) {
            $this->state->addScaleValue($stage, $ruleKey, $g['scaleKey'], $g['gender'], array_sum($g['responses']));
        }
    }

    private function average(string $stage, int $ruleKey): void
    {
        foreach ($this->grouped($stage, $this->sourceRows($stage, $ruleKey)) as $g) {
            $this->state->addScaleValue($stage, $ruleKey, $g['scaleKey'], $g['gender'], array_sum($g['responses']) / count($g['responses']));
        }
    }

    private function sumResponseValue(string $stage, int $ruleKey, int $targetScale): void
    {
        $rows = $this->sourceRows($stage, $ruleKey);
        $sum = array_sum(array_column($rows, 'response'));
        // Legacy writes Gender = " " (literal single space) on the Tool branch.
        $this->state->addScaleValue($stage, $ruleKey, $targetScale, $stage === 'Tool' ? ' ' : null, $sum);
    }

    /** @return list<array{scaleKey: ?int, gender: ?string, responses: list<float>}> in first-seen order */
    private function grouped(string $stage, array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $k = $this->groupKey($stage, $row);
            $groups[$k] ??= ['scaleKey' => $row['scaleKey'], 'gender' => $row['gender'], 'responses' => []];
            $groups[$k]['responses'][] = $row['response'];
        }

        return array_values($groups);
    }

    // ------------------------------------------------------------------
    // Arithmetic

    private function byConstant(string $stage, int $ruleKey, callable $f): void
    {
        foreach ($this->sourceRows($stage, $ruleKey) as $row) {
            $this->state->addScaleValue($stage, $ruleKey, $row['scaleKey'], $row['gender'], self::round6($f($row['response'])));
        }
    }

    private function divideByConstant(string $stage, int $ruleKey, float $constant): void
    {
        // Legacy GROUP BY fields, Response — identical (fields, response)
        // source rows collapse to one output row.
        $seen = [];
        foreach ($this->sourceRows($stage, $ruleKey) as $row) {
            $k = $this->groupKey($stage, $row).'|'.$row['response'];
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $this->state->addScaleValue($stage, $ruleKey, $row['scaleKey'], $row['gender'], self::round6($row['response'] / $constant));
        }
    }

    private function divideByValue(string $stage, int $ruleKey, int $respSourceRuleKey): void
    {
        $numerators = $this->sourceRows($stage, $ruleKey);
        // @tiIgnoreRuleSource = 1: the divisor is that rule's own output rows.
        $divisors = $this->state->valuesOfRules($stage, [$respSourceRuleKey]);

        $seen = [];
        foreach ($numerators as $ta) {
            foreach ($divisors as $tb) {
                $k = $this->groupKey($stage, $ta).'|'.$ta['response'].'|'.$tb['response'];
                if (isset($seen[$k])) {
                    continue;
                }
                $seen[$k] = true;
                $value = $tb['response'] == 0.0 ? 0.0 : self::round6($ta['response'] / $tb['response']);
                $this->state->addScaleValue($stage, $ruleKey, $ta['scaleKey'], $ta['gender'], $value);
            }
        }
    }

    // ------------------------------------------------------------------
    // Conversions

    private function convertResponse(string $stage, int $ruleKey, int $responseWeightKey): void
    {
        $weights = $this->config->responseWeights($responseWeightKey);
        foreach ($this->sourceRows($stage, $ruleKey) as $row) {
            $raw = (int) $row['response'];
            if (! array_key_exists($raw, $weights)) {
                continue; // inner join: unmatched raw values drop out
            }
            $this->state->addScaleValue($stage, $ruleKey, $row['scaleKey'], $row['gender'], $weights[$raw]);
        }
    }

    private function convertPzsd(string $stage, int $ruleKey): void
    {
        $matrix = $this->config->pzsdMatrix();
        foreach ($this->sourceRows($stage, $ruleKey) as $row) {
            $normed = $matrix[$row['gender']][$row['scaleKey']][(string) $row['response']] ?? null;
            if ($normed === null) {
                continue; // inner join
            }
            $this->state->addScaleValue($stage, $ruleKey, $row['scaleKey'], $row['gender'], $normed);
        }
    }

    private function calibratePzsd(string $stage, int $ruleKey): void
    {
        // Reads its source rule's output directly (ToolRuleSource.SrcKey),
        // fetching the four scales by ToolScaleDetail *name*. NB the
        // deliberate crosswiring in the legacy proc: D-variable ← scale "P",
        // I ← "Z", S ← "S", C ← "D".
        $src = $this->config->ruleSource($stage, $ruleKey);
        $rows = $this->state->valuesOfRules('Tool', $src['keys']);

        $byName = [];
        foreach ($rows as $row) {
            if ($row['scaleKey'] !== null) {
                $byName[$this->config->toolScaleDetail($row['scaleKey'])['name']] = $row['response'];
            }
        }
        $d = $byName['P'] ?? 0.0;
        $i = $byName['Z'] ?? 0.0;
        $s = $byName['S'] ?? 0.0;
        $c = $byName['D'] ?? 0.0;

        // Sequential if-chain from sp_CalibratePZSD — LAST matching wins.
        $label = 'NOVALUE';
        if ($d > .50 && $i < .50 && $s < .50 && $c < .50) {
            $label = 'PZSD1';
        }
        if ($c > .50 && $i < .50 && $s < .50 && $d < .50) {
            $label = 'PZSD2';
        }
        if ($d > .50 && $i > .50 && $s < .50 && $c < .50 && $d >= $i && $s >= $c) {
            $label = 'PZSD3';
        }
        if ($d > .50 && $i > .50 && $s < .50 && $c < .50 && $d >= $i && $s < $c) {
            $label = 'PZSD4';
        }
        if ($d > .50 && $i > .50 && $s < .50 && $c < .50 && $d < $i && $s < $c) {
            $label = 'PZSD5';
        }
        if ($d > .50 && $i > .50 && $s < .50 && $c < .50 && $d < $i && $s > $c) {
            $label = 'PZSD6';
        }
        if ($d < .50 && $i > .50 && $s < .50 && $c > .50) {
            $label = 'PZSD7';
        }
        if ($d > .50 && $i < .50 && $s > .50 && $c < .50) {
            $label = 'PZSD8';
        }
        if ($d < .50 && $i < .50 && $s > .50 && $c > .50 && $d >= $i) {
            $label = 'PZSD9';
        }
        if ($d < .50 && $i < .50 && $s > .50 && $c > .50 && $d < $i) {
            $label = 'PZSD10';
        }
        if ($d > .50 && $i < .50 && $s < .50 && $c > .50) {
            $label = 'PZSD11';
        }
        if ($d < .50 && $i > .50 && $s > .50 && $c < .50) {
            $label = 'PZSD12';
        }
        if ($d < .50 && $i > .50 && $s < .50 && $c < .50) {
            $label = 'PZSD6';
        }
        if ($d < .50 && $i < .50 && $s > .50 && $c < .50) {
            $label = 'PZSD12';
        }
        if ($d < .50 && $i > .50 && $s > .50 && $c > .50) {
            $label = 'PZSD9';
        }
        if ($d > .50 && $i < .50 && $s > .50 && $c > .50) {
            $label = 'PZSD8';
        }
        if ($d > .50 && $i > .50 && $s < .50 && $c > .50) {
            $label = 'PZSD4';
        }
        if ($d > .50 && $i > .50 && $s > .50 && $c < .50) {
            $label = 'PZSD3';
        }
        if ($label === 'NOVALUE') {
            $label = 'PZSD13';
        }

        foreach ($this->config->scaleDetailByScaleNameAndAltName('Personal Style', $label) as $detailKey) {
            $this->state->addScaleValue($stage, $ruleKey, $detailKey, '', 1.0);
        }
    }

    private function convertPxi(string $stage, int $ruleKey): void
    {
        // Cross join against PXIMatrix: every (source row, band) pair where
        // the response falls inside the band emits Response=1 under the
        // band's scale key.
        $matrix = $this->config->pxiMatrix();
        foreach ($this->sourceRows($stage, $ruleKey) as $row) {
            foreach ($matrix as $band) {
                if ($row['response'] >= $band['min'] && $row['response'] <= $band['max']) {
                    $this->state->addScaleValue($stage, $ruleKey, $band['toolScaleDetailKey'], $row['gender'], 1.0);
                }
            }
        }
    }

    private function pxiScore(string $stage, int $ruleKey): void
    {
        $rows = $this->sourceRows($stage, $ruleKey);
        $avg = $rows === [] ? 0.0 : self::round6(array_sum(array_column($rows, 'response')) / count($rows));
        // Legacy hardcodes ToolScaleDetailKey=1 and Gender="".
        $this->state->addScaleValue($stage, $ruleKey, 1, '', $avg);
    }

    private function pxiSpecificType(string $stage, int $ruleKey): void
    {
        // Add-side: source rows whose ToolScaleDetail.AltName = '1', summed
        // per RelatedScale; Sub-side: everything else; result = add − sub,
        // inner-joined on the RelatedScale key.
        $add = [];
        $sub = [];
        $genders = [];
        foreach ($this->sourceRows($stage, $ruleKey) as $row) {
            $detail = $this->config->toolScaleDetail($row['scaleKey']);
            $related = (int) $detail['relatedScale'];
            if ($detail['altName'] === '1') {
                $add[$related] = ($add[$related] ?? 0.0) + $row['response'];
            } else {
                $sub[$related] = ($sub[$related] ?? 0.0) + $row['response'];
            }
            $genders[$related] = $row['gender'];
        }
        foreach ($add as $related => $a) {
            if (array_key_exists($related, $sub)) {
                $this->state->addScaleValue($stage, $ruleKey, $related, $genders[$related], $a - $sub[$related]);
            }
        }
    }

    private function convertToolFramework(string $stage, int $ruleKey): void
    {
        $matrix = $this->config->toolFrameworkMatrix();
        foreach ($this->sourceRows($stage, $ruleKey) as $row) {
            // Matrix joins on the SOURCE row's rule key + scale key.
            foreach ($matrix[$row['ruleKey']][$row['scaleKey']] ?? [] as $frameworkKey) {
                $this->state->addScaleValue($stage, $ruleKey, $frameworkKey, null, $row['response']);
            }
        }
    }

    private function convertSctMatrix(string $stage, int $ruleKey): void
    {
        $matrix = $this->config->sctMatrix($ruleKey);
        foreach ($this->sourceRows($stage, $ruleKey) as $row) {
            foreach ($matrix[$row['scaleKey']] ?? [] as $cell) {
                $this->state->addScaleValue($stage, $ruleKey, $cell['frameworkKey'], null, $cell['value']);
            }
        }
    }

    private function convertCctMatrix(string $stage, int $ruleKey): void
    {
        $matrix = $this->config->cctMatrix();
        foreach ($this->sourceRows($stage, $ruleKey) as $row) {
            foreach ($matrix[$row['scaleKey']] ?? [] as $cell) {
                $this->state->addScaleValue($stage, $ruleKey, $cell['frameworkKey'], null, self::round6($row['response'] * $cell['weight']));
            }
        }
    }

    private function convertPxiSctMatrix(string $stage, int $ruleKey): void
    {
        $matrix = $this->config->pxiSctMatrix();
        foreach ($this->sourceRows($stage, $ruleKey) as $row) {
            $r2 = round($row['response'], 2);
            foreach ($matrix[$row['scaleKey']] ?? [] as $cell) {
                if ($r2 >= $cell['lo'] && $r2 <= $cell['hi']) {
                    $this->state->addScaleValue($stage, $ruleKey, $cell['frameworkKey'], null, $cell['value']);
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // Selection

    private function rankScaleOutput(string $stage, int $ruleKey, int $sortDesc): void
    {
        $rows = $this->sourceRows($stage, $ruleKey);
        // ORDER BY Response DESC, isNull(Sequence,0) ASC, key ASC
        // (Tool keys always ASC; FrameworkKey DESC when @tiSortDesc = 1).
        $keyDir = ($stage !== 'Tool' && $sortDesc === 1) ? -1 : 1;
        usort($rows, function ($a, $b) use ($keyDir) {
            return ($b['response'] <=> $a['response'])
                ?: (($a['sequence'] ?? 0) <=> ($b['sequence'] ?? 0))
                ?: $keyDir * (($a['scaleKey'] ?? 0) <=> ($b['scaleKey'] ?? 0));
        });
        foreach ($rows as $i => $row) {
            $this->state->addScaleValue($stage, $ruleKey, $row['scaleKey'], null, (float) ($i + 1), $row['sequence'] ?? 0);
        }
    }

    private function convertToArchetypeId(string $stage, int $ruleKey): void
    {
        $fwArch = $this->config->frameworkArchetype();
        $archetypes = $this->config->archetypes($this->state->languageKey);
        foreach ($this->sourceRows($stage, $ruleKey) as $row) {
            $arch = $archetypes[$fwArch[$row['scaleKey']] ?? -1] ?? null;
            if ($arch === null) {
                continue;
            }
            $this->state->addScaleValue($stage, $ruleKey, $arch['archetypeKey'], null, $row['response'], $row['sequence']);
        }
    }

    // ------------------------------------------------------------------
    // Insight stage

    /** Archetype ID (localized) for a source row keyed by FrameworkKey. */
    private function archetypeIdForRow(array $row): ?int
    {
        $fwArch = $this->config->frameworkArchetype();
        $arch = $this->config->archetypes($this->state->languageKey)[$fwArch[$row['scaleKey']] ?? -1] ?? null;

        return $arch['id'] ?? null;
    }

    private function createKeyCode(string $stage, int $ruleKey, string $positions): void
    {
        if (! in_array(strlen($positions), [3, 5], true)) {
            return; // legacy proc only acts on 3- or 5-digit position lists
        }
        $wanted = array_map(intval(...), str_split($positions));

        $rows = $this->sourceRows($stage, $ruleKey);
        usort($rows, fn ($a, $b) => $a['response'] <=> $b['response']); // ORDER BY Response ASC

        $picked = [];
        foreach ($rows as $i => $row) {
            if (in_array($i + 1, $wanted, true)) {
                $picked[] = (string) $this->archetypeIdForRow($row);
            }
        }
        sort($picked, SORT_STRING); // ORDER BY ArchID (char sort)

        $this->state->addScaleValue($stage, $ruleKey, null, null, (float) implode('', $picked));
    }

    private function storeKeyCode(string $stage, int $ruleKey, int $sosType): void
    {
        foreach ($this->sourceRows($stage, $ruleKey) as $row) {
            $this->state->addOutString($sosType, self::floatString($row['response']), 1);
        }
    }

    private function storeValue(string $stage, int $ruleKey, int $sosType): void
    {
        $rows = $this->sourceRows($stage, $ruleKey);
        usort($rows, fn ($a, $b) => ($a['scaleKey'] ?? 0) <=> ($b['scaleKey'] ?? 0)); // ORDER BY FrameworkKey
        foreach ($rows as $i => $row) {
            $this->state->addOutString($sosType, self::floatString(10 - $row['response']), $i + 1);
        }
    }

    private function storeId(string $stage, int $ruleKey, int $sosType): void
    {
        $rows = $this->sourceRows($stage, $ruleKey);
        usort($rows, fn ($a, $b) => $a['response'] <=> $b['response']); // ORDER BY Response ASC
        foreach ($rows as $i => $row) {
            $this->state->addOutString($sosType, (string) $this->archetypeIdForRow($row), $i + 1);
        }
    }

    private function storeFrameworkData(string $stage, int $ruleKey, int $sosType, int $englishDetailType): void
    {
        $detailType = $this->config->archetypeDetailType($englishDetailType, $this->state->languageKey);
        $fwArch = $this->config->frameworkArchetype();
        $archetypes = $this->config->archetypes($this->state->languageKey);
        $details = $this->config->archetypeDetails();

        $rows = $this->sourceRows($stage, $ruleKey);
        usort($rows, fn ($a, $b) => $a['response'] <=> $b['response']); // ORDER BY Response
        foreach ($rows as $row) {
            $arch = $archetypes[$fwArch[$row['scaleKey']] ?? -1] ?? null;
            if ($arch === null) {
                continue;
            }
            foreach ($details[$arch['archetypeKey']][$detailType] ?? [] as $detail) {
                $this->state->addOutString($sosType, $detail['string'], (int) $row['response'], $detail['key']);
            }
        }
    }

    private function storeCaseData(string $stage, int $ruleKey, int $sosType, int $englishCatType): void
    {
        $catType = $this->config->insightCategoryType($englishCatType, $this->state->languageKey);
        $byCase = $this->config->insightsByCaseId();
        $details = $this->config->insightDetails();

        foreach ($this->sourceRows($stage, $ruleKey) as $row) {
            // Legacy: Insight.CaseID = rtrim(ltrim(convert(char(3), Response)))
            // — key codes are three single-digit archetype IDs, so always
            // 3 chars; wider values would have overflowed char(3) in legacy.
            $caseId = self::floatString($row['response']);
            foreach ($byCase[$caseId] ?? [] as $insightKey) {
                foreach ($details[$insightKey][$catType] ?? [] as $detail) {
                    $this->state->addOutString($sosType, $detail['description'], $detail['sequence'], null, $detail['key']);
                }
            }
        }
    }

    /** T-SQL convert(char, float) for the whole numbers these procs emit. */
    private static function floatString(float $v): string
    {
        if ($v != (float) (int) $v) {
            throw new RuntimeException("Non-integral value {$v} where legacy emitted whole-number strings");
        }

        return (string) (int) $v;
    }
}
