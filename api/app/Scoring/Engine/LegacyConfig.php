<?php

namespace App\Scoring\Engine;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Read-side of the imported legacy config (see `legacy:import`). Every lookup
 * mirrors a join in the legacy procs; tables keep their legacy names. All
 * data is cached per-instance — config is immutable at runtime.
 *
 * Version-bundle selection (which PackageVersion/ProfileVersion/
 * InsightScoreVersion a request scores against) is NOT hardcoded here — it
 * comes from a product/access code (ProductCatalog), so this class stays
 * agnostic to any one product.
 */
class LegacyConfig
{
    /** ISO 639-1 → legacy Language.ID */
    public const LANGUAGES = ['en' => 1, 'fr' => 2, 'tr' => 3, 'pt' => 4];

    private array $cache = [];

    /**
     * q (1-based, = Question.Sequence) → question mapping for a tool version
     * and language. Each question has exactly one QuestionTROut per language.
     *
     * @return array<int, array{questionTROutKey: int, trOutKey: int, toolScaleDetailKey: ?int, weight: ?float}>
     */
    public function questionMap(int $toolVersionKey, int $languageKey): array
    {
        return $this->cache["qm.{$toolVersionKey}.{$languageKey}"] ??= (function () use ($toolVersionKey, $languageKey) {
            $rows = DB::table('Question as q')
                ->join('QuestionTROut as qt', 'qt.QuestionKey', '=', 'q.QuestionKey')
                ->where('q.ToolVersionKey', $toolVersionKey)
                ->where('q.LanguageKey', $languageKey)
                ->get(['q.Sequence', 'qt.QuestionTROutKey', 'qt.TROutKey', 'qt.ToolScaleDetailKey', 'qt.Weight']);
            $map = [];
            foreach ($rows as $r) {
                $map[(int) $r->Sequence] = [
                    'questionTROutKey' => (int) $r->QuestionTROutKey,
                    'trOutKey' => (int) $r->TROutKey,
                    'toolScaleDetailKey' => $r->ToolScaleDetailKey === null ? null : (int) $r->ToolScaleDetailKey,
                    'weight' => $r->Weight === null ? null : (float) $r->Weight,
                ];
            }

            return $map;
        })();
    }

    /**
     * Tool-stage rules for a set of tool versions, in sp_ScoreTools cursor
     * order (TROutKey, Sequence).
     *
     * @param  list<int>  $toolVersionKeys
     * @return list<object>
     */
    public function toolRules(array $toolVersionKeys): array
    {
        sort($toolVersionKeys);
        $key = 'toolRules.'.implode(',', $toolVersionKeys);

        return $this->cache[$key] ??= DB::table('ToolRule as tr')
            ->join('ToolRuleOut as tro', 'tro.TROutKey', '=', 'tr.TROutKey')
            ->join('ToolRuleType as trt', 'trt.TRTypeKey', '=', 'tr.trTypeKey')
            ->whereIn('tro.ToolVersionKey', $toolVersionKeys)
            ->orderBy('tr.TROutKey')->orderBy('tr.Sequence')
            ->get(['tr.ToolRuleKey as ruleKey', 'trt.StoredProcName as proc'])
            ->all();
    }

    /** @return list<object> Package rules in sp_ScorePackage order, for the given product's PackageVersion */
    public function packageRules(int $packageVersionKey): array
    {
        return $this->cache["packageRules.{$packageVersionKey}"] ??= DB::table('PackageRule as pr')
            ->join('PackageOut as po', 'po.PackageOutKey', '=', 'pr.PackageoutKey')
            ->join('PackageRuleType as prt', 'prt.PackageRuleType', '=', 'pr.PackageRuleTypeKey')
            ->where('po.PackageVersionKey', $packageVersionKey)
            ->orderBy('po.PackageOutKey')->orderBy('pr.Sequence')
            ->get(['pr.PackageRuleKey as ruleKey', 'prt.StoredProcName as proc'])
            ->all();
    }

    /** @return list<object> Profile rules in sp_ScoreProfile order, for the given product's ProfileVersion */
    public function profileRules(int $profileVersionKey): array
    {
        return $this->cache["profileRules.{$profileVersionKey}"] ??= DB::table('ProfileRule as pr')
            ->join('ProfileOut as po', 'po.ProfileOutKey', '=', 'pr.ProfileOutKey')
            ->join('ProfileRuleType as prt', 'prt.ProfileRuleTypeKey', '=', 'pr.ProfileRuleTypeKey')
            ->where('po.ProfileVersionKey', $profileVersionKey)
            ->orderBy('po.ProfileOutKey')->orderBy('pr.Sequence')
            ->get(['pr.ProfileRuleKey as ruleKey', 'prt.StoredProcName as proc'])
            ->all();
    }

    /** @return list<object> Insight rules in sp_ScoreInsight order, for the given product's InsightScoreVersion */
    public function insightRules(int $insightScoreVersionKey): array
    {
        return $this->cache["insightRules.{$insightScoreVersionKey}"] ??= DB::table('InsightRule as ir')
            ->join('InsightOut as io', 'io.InsightOutKey', '=', 'ir.InsightOutKey')
            ->join('InsightRuleType as irt', 'irt.InsightRuleTypeKey', '=', 'ir.InsightRuleTypeKey')
            ->where('io.InsightScoreVersionKey', $insightScoreVersionKey)
            ->orderBy('io.InsightOutKey')->orderBy('ir.Sequence')
            ->get(['ir.InsightRuleKey as ruleKey', 'irt.StoredProcName as proc'])
            ->all();
    }

    /**
     * Named parameters for a rule (sp_CreateParamString): '@tiConstant' => value.
     *
     * @return array<string, string>
     */
    public function ruleParams(string $stage, int $ruleKey): array
    {
        return $this->cache["params.{$stage}.{$ruleKey}"] ??= DB::table("{$stage}RuleParams")
            ->where("{$stage}RuleKey", $ruleKey)
            ->pluck('Value', 'Name')
            ->map(fn ($v) => trim((string) $v))
            ->all();
    }

    /**
     * Rule source: SrcType + SrcKeys (sp_CreateSourceTable). SrcTypes are
     * homogeneous per rule (verified across all four RuleSource tables).
     *
     * @return array{type: ?string, keys: list<int>}
     */
    public function ruleSource(string $stage, int $ruleKey): array
    {
        return $this->cache["src.{$stage}.{$ruleKey}"] ??= (function () use ($stage, $ruleKey) {
            $rows = DB::table("{$stage}RuleSource")->where("{$stage}RuleKey", $ruleKey)->get(['SrcType', 'SrcKey']);
            if ($rows->isEmpty()) {
                return ['type' => null, 'keys' => []];
            }

            return [
                'type' => trim($rows[0]->SrcType),
                'keys' => $rows->map(fn ($r) => (int) $r->SrcKey)->all(),
            ];
        })();
    }

    /** ResponseWeightDetails recode map: rawValue → convertedValue */
    public function responseWeights(int $responseWeightKey): array
    {
        return $this->cache["rw.{$responseWeightKey}"] ??= DB::table('ResponseWeightDetails')
            ->where('ResponseWeightKey', $responseWeightKey)
            ->pluck('ConvertedValue', 'RawValue')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    /** PZSD norm lookup: [gender][toolScaleDetailKey][raw] → normed */
    public function pzsdMatrix(): array
    {
        return $this->cache['pzsd'] ??= (function () {
            $map = [];
            foreach (DB::table('PZSDConversionMatrix')->get() as $r) {
                $map[trim($r->Gender)][(int) $r->ToolScaleDetailKey][(string) (float) $r->Raw] = (float) $r->Normed;
            }

            return $map;
        })();
    }

    /** @return list<array{min: float, max: float, toolScaleDetailKey: int}> */
    public function pxiMatrix(): array
    {
        return $this->cache['pxi'] ??= DB::table('PXIMatrix')->get()
            ->map(fn ($r) => ['min' => (float) $r->minValue, 'max' => (float) $r->maxValue, 'toolScaleDetailKey' => (int) $r->ToolScaleDetailKey])
            ->all();
    }

    /** ToolFrameworkMatrix: [toolRuleKey][toolScaleDetailKey] → list<frameworkKey> */
    public function toolFrameworkMatrix(): array
    {
        return $this->cache['tfm'] ??= (function () {
            $map = [];
            foreach (DB::table('ToolFrameworkMatrix')->get() as $r) {
                $map[(int) $r->ToolRuleKey][(int) $r->ToolScaleDetailKey][] = (int) $r->FrameworkKey;
            }

            return $map;
        })();
    }

    /** SCTPkgMatrix rows for a package rule: [toolScaleDetailKey] → list<{frameworkKey, value}> */
    public function sctMatrix(int $packageRuleKey): array
    {
        return $this->cache["sct.{$packageRuleKey}"] ??= (function () use ($packageRuleKey) {
            $map = [];
            foreach (DB::table('SCTPkgMatrix')->where('PackageRuleKey', $packageRuleKey)->get() as $r) {
                $map[(int) $r->ToolScaleDetailKey][] = ['frameworkKey' => (int) $r->FrameworkKey, 'value' => (float) $r->Value];
            }

            return $map;
        })();
    }

    /** CCTConversionMatrix: [toolScaleDetailKey] → list<{frameworkKey, weight}> */
    public function cctMatrix(): array
    {
        return $this->cache['cct'] ??= (function () {
            $map = [];
            foreach (DB::table('CCTConversionMatrix')->get() as $r) {
                $map[(int) $r->ToolScaleDetailKey][] = ['frameworkKey' => (int) $r->FrameworkKey, 'weight' => (float) $r->Weight];
            }

            return $map;
        })();
    }

    /** PkgPXISCTMatrix: [toolScaleDetailKey] → list<{lo, hi, frameworkKey, value}> */
    public function pxiSctMatrix(): array
    {
        return $this->cache['pxisct'] ??= (function () {
            $map = [];
            foreach (DB::table('PkgPXISCTMatrix')->get() as $r) {
                $map[(int) $r->ToolScaleDetailKey][] = [
                    'lo' => (float) $r->LoRange, 'hi' => (float) $r->HiRange,
                    'frameworkKey' => (int) $r->FrameworkKey, 'value' => (float) $r->Value,
                ];
            }

            return $map;
        })();
    }

    /** @return array{toolScaleKey: int, name: string, altName: ?string, relatedScale: ?string} */
    public function toolScaleDetail(int $key): array
    {
        $all = $this->cache['tsd'] ??= (function () {
            $map = [];
            foreach (DB::table('ToolScaleDetail')->get() as $r) {
                $map[(int) $r->ToolScaleDetailKey] = [
                    'toolScaleKey' => (int) $r->ToolScaleKey,
                    'name' => trim((string) $r->Name),
                    'altName' => $r->AltName === null ? null : trim((string) $r->AltName),
                    'relatedScale' => $r->RelatedScale === null ? null : trim((string) $r->RelatedScale),
                ];
            }

            return $map;
        })();

        return $all[$key] ?? throw new RuntimeException("Unknown ToolScaleDetailKey {$key}");
    }

    /** ToolScaleDetailKeys under a named ToolScale with a given AltName (sp_CalibratePZSD target lookup). */
    public function scaleDetailByScaleNameAndAltName(string $scaleName, string $altName): array
    {
        $scales = $this->cache['toolScales'] ??= DB::table('ToolScale')->pluck('ToolScaleKey', 'Name')
            ->mapWithKeys(fn ($v, $k) => [trim($k) => (int) $v])->all();
        $scaleKey = $scales[$scaleName] ?? throw new RuntimeException("Unknown ToolScale '{$scaleName}'");

        $this->cache['tsd'] ?? $this->toolScaleDetail(1); // warm cache

        return array_keys(array_filter(
            $this->cache['tsd'],
            fn ($d) => $d['toolScaleKey'] === $scaleKey && $d['altName'] === $altName,
        ));
    }

    /** FrameworkKey → English ArchetypeKey */
    public function frameworkArchetype(): array
    {
        return $this->cache['fwArch'] ??= DB::table('Framework')->pluck('ArcheTypeKey', 'FrameworkKey')
            ->map(fn ($v) => (int) $v)->all();
    }

    /**
     * Localized archetype for an English ArchetypeKey:
     * [englishArchetypeKey] → {archetypeKey, id} for a language.
     */
    public function archetypes(int $languageKey): array
    {
        return $this->cache["arch.{$languageKey}"] ??= (function () use ($languageKey) {
            $map = [];
            foreach (DB::table('Archetype')->where('LanguageKey', $languageKey)->get() as $r) {
                $map[(int) $r->EnglishArcheTypeKey] = ['archetypeKey' => (int) $r->ArcheTypeKey, 'id' => (int) $r->ID];
            }

            return $map;
        })();
    }

    /** Localized ArchetypeDetailTypeKey for an English one. */
    public function archetypeDetailType(int $englishKey, int $languageKey): ?int
    {
        $all = $this->cache["adt.{$languageKey}"] ??= DB::table('ArchetypeDetailType')
            ->where('LanguageKey', $languageKey)
            ->pluck('ArchetypeDetailTypeKey', 'EnglishArchetypeDetailTypeKey')
            ->map(fn ($v) => (int) $v)->all();

        return $all[$englishKey] ?? null;
    }

    /** ArchetypeDetail rows: [archetypeKey][detailTypeKey] → list<{key, string}> */
    public function archetypeDetails(): array
    {
        return $this->cache['ad'] ??= (function () {
            $map = [];
            foreach (DB::table('ArchetypeDetail')->orderBy('ArchetypeDetailKey')->get() as $r) {
                $map[(int) $r->ArcheTypeKey][(int) $r->ArchetypeDetailTypeKey][] = [
                    'key' => (int) $r->ArchetypeDetailKey,
                    'string' => (string) $r->String,
                ];
            }

            return $map;
        })();
    }

    /** Localized InsightCategoryTypeKey for an English one. */
    public function insightCategoryType(int $englishKey, int $languageKey): ?int
    {
        $all = $this->cache["ict.{$languageKey}"] ??= DB::table('InsightCategoryType')
            ->where('LanguageKey', $languageKey)
            ->pluck('InsightCategoryTypeKey', 'EnglishInsightCategoryTypeKey')
            ->map(fn ($v) => (int) $v)->all();

        return $all[$englishKey] ?? null;
    }

    /** Insight.CaseID (trimmed string) → list<InsightKey> */
    public function insightsByCaseId(): array
    {
        return $this->cache['insightCase'] ??= (function () {
            $map = [];
            foreach (DB::table('Insight')->get() as $r) {
                $map[trim((string) $r->CaseID)][] = (int) $r->InsightKey;
            }

            return $map;
        })();
    }

    /** InsightDetail rows: [insightKey][categoryTypeKey] → list<{key, description, sequence}> */
    public function insightDetails(): array
    {
        return $this->cache['id'] ??= (function () {
            $map = [];
            foreach (DB::table('InsightDetail')->orderBy('InsightDetailKey')->get() as $r) {
                $map[(int) $r->InsightKey][(int) $r->InsightCategoryTypeKey][] = [
                    'key' => (int) $r->InsightDetailKey,
                    'description' => (string) $r->Description,
                    'sequence' => $r->Sequence === null ? null : (int) $r->Sequence,
                ];
            }

            return $map;
        })();
    }
}
