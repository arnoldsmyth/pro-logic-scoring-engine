<?php

namespace App\Http\Controllers\Api;

use App\Api\ApiException;
use App\Api\ToolValidator;
use App\Http\Controllers\Controller;
use App\Models\NormSet;
use App\Scoring\Engine\LegacyConfig;
use App\Scoring\Engine\ProductCatalog;
use App\Scoring\Scopes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Self-documenting reference data (docs/05): languages, questionnaire
 * content, key→string translation maps, and the scope catalog.
 */
class ReferenceController extends Controller
{
    private const SUPPORTED = ['en', 'fr', 'pt'];

    /** GET /v2/reference/languages */
    public function languages(): JsonResponse
    {
        $sets = NormSet::query()->orderBy('id')->get();

        return response()->json([
            'languages' => array_map(fn (string $code) => [
                'code' => $code,
                'content_coverage' => 'full', // en/fr/pt content is 100% symmetric (docs/03)
                'norm_status' => $sets
                    ->filter(fn (NormSet $s) => $s->language === null || $s->language === $code)
                    ->mapWithKeys(fn (NormSet $s) => [$s->slug => $s->status])
                    ->all() ?: ['male-legacy' => 'active', 'female-legacy' => 'active'],
            ], self::SUPPORTED),
        ]);
    }

    /** GET /v2/reference/norm-sets — versioned norm sets with provenance (docs/06). */
    public function normSets(): JsonResponse
    {
        return response()->json([
            'norm_sets' => NormSet::query()->orderBy('id')->get()->map(fn (NormSet $s) => [
                'slug' => $s->slug,
                'status' => $s->status,
                'language' => $s->language, // null = all languages
                'gender' => $s->gender, // null = pooled
                'provisional' => $s->provisional,
                'description' => $s->description,
                'provenance' => $s->provenance,
                'activated_at' => $s->activated_at?->toIso8601String(),
                'retired_at' => $s->retired_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    /** GET /v2/reference/questions?tool=&language= */
    public function questions(Request $request): JsonResponse
    {
        $tool = $request->query('tool');
        $language = strtolower($request->query('language', 'en'));
        $languageKey = $this->languageKey($language);

        $toolVersions = ProductCatalog::get(ProductCatalog::DEFAULT_CODE)['toolVersions'];
        if ($tool !== null && ! isset($toolVersions[$tool])) {
            throw new ApiException(404, 'unknown_tool', "Unknown tool '{$tool}'.", ['known_tools' => array_keys($toolVersions)]);
        }

        $tools = $tool !== null ? [$tool => $toolVersions[$tool]] : $toolVersions;
        $out = [];
        foreach ($tools as $name => $toolVersionKey) {
            $rows = DB::table('Question')
                ->where('ToolVersionKey', $toolVersionKey)
                ->where('LanguageKey', $languageKey)
                ->orderBy('Sequence')
                ->get(['Sequence', 'Description']);
            $out[$name] = [
                'validation' => ToolValidator::TOOLS[$name] ?? null,
                'questions' => $rows->map(fn ($r) => ['q' => (int) $r->Sequence, 'text' => (string) $r->Description])->all(),
            ];
        }

        return response()->json(['language' => $language, 'tools' => $out]);
    }

    /**
     * GET /v2/reference/translations?language= — key→string maps for
     * keys-mode clients (docs/03). Maps are keyed by the ENGLISH detail
     * keys, which is what keys-format results contain when scored with
     * language=en (the standard keys-mode integration).
     */
    public function translations(Request $request): JsonResponse
    {
        $language = strtolower($request->query('language', 'en'));
        $languageKey = $this->languageKey($language);

        $archetype = DB::table('ArchetypeDetail')
            ->where('LanguageKey', $languageKey)
            ->pluck('String', 'EnglishArchetypeDetailKey')
            ->map(fn ($s) => (string) $s)->all();

        $insight = DB::table('InsightDetail')
            ->where('LanguageKey', $languageKey)
            ->pluck('Description', 'EnglishInsightDetailKey')
            ->map(fn ($s) => (string) $s)->all();

        $lookup = DB::table('LookupDefinition')
            ->where('LanguageKey', $languageKey)
            ->pluck('Definition', 'EnglishLookupDefinitionKey')
            ->map(fn ($s) => (string) $s)->all();

        return response()->json([
            'language' => $language,
            'archetype_details' => $archetype,
            'insight_details' => $insight,
            'lookup_definitions' => $lookup,
        ]);
    }

    /** GET /v2/reference/scopes — scope → required tools → output shape. */
    public function scopes(): JsonResponse
    {
        $out = [];
        foreach (Scopes::SCOPES as $scope => $spec) {
            $out[$scope] = [
                'required_tools' => $spec['tools'],
                'uses_gender_split_norms' => $spec['gendered'],
                'output' => match ($scope) {
                    'mcs' => 'per-area {m, c, s} ranks 1-9',
                    'mcs.m', 'mcs.c', 'mcs.s' => 'per-area '.substr($scope, 4).' rank 1-9',
                    'pro.person' => 'per-area p rank 1-9',
                    'pro.role' => 'per-area r rank 1-9',
                    'pro.org' => 'per-area o rank 1-9',
                    'insights' => 'narrative field map (docs 03 output catalog)',
                    'reflections' => 'verbatim echo of the reflections tool',
                },
            ];
        }
        $out['full'] = [
            'alias_for' => Scopes::FULL,
            'required_tools' => Scopes::requiredTools(Scopes::FULL),
        ];

        return response()->json(['scopes' => $out]);
    }

    private function languageKey(string $language): int
    {
        if (! in_array($language, self::SUPPORTED, true)) {
            throw new ApiException(422, 'unsupported_language', "Language '{$language}' is not supported. Supported: en, fr, pt.");
        }

        return LegacyConfig::LANGUAGES[$language];
    }
}
