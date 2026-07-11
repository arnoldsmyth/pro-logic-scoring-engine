<?php

namespace App\Http\Controllers\Panel;

use App\Api\ToolValidator;
use App\Http\Controllers\Controller;
use App\Scoring\Engine\LegacyConfig;
use App\Scoring\Engine\ProductCatalog;
use App\Scoring\Scopes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The under-the-hood pages (docs/08, first-class requirement): the 4-stage
 * scoring cascade rendered from the LIVE rule data — never hand-maintained
 * text — plus the dependency matrix and read-only content browsing. This is
 * the living documentation that keeps the engine from becoming a black box
 * again.
 */
class PipelineController extends Controller
{
    public function __construct(private readonly LegacyConfig $config) {}

    /** GET /panel/api/pipeline — stages + rule-type frequencies from live rule rows. */
    public function pipeline(): JsonResponse
    {
        $product = ProductCatalog::get(ProductCatalog::DEFAULT_CODE);

        $stages = [
            'Tool' => $this->config->toolRules(array_values($product['toolVersions'])),
            'Package' => $this->config->packageRules($product['packageVersionKey']),
            'Profile' => $this->config->profileRules($product['profileVersionKey']),
            'Insight' => $this->config->insightRules($product['insightScoreVersionKey']),
        ];

        return response()->json([
            'product' => ProductCatalog::DEFAULT_CODE,
            'stages' => collect($stages)->map(fn (array $rules, string $stage) => [
                'rules' => count($rules),
                'operations' => collect($rules)
                    ->groupBy(fn ($r) => trim($r->proc))
                    ->map->count()
                    ->sortDesc()
                    ->all(),
            ])->all(),
            'dependency_matrix' => collect(Scopes::SCOPES)->map(fn ($spec, $scope) => [
                'required_tools' => $spec['tools'],
                'uses_gender_split_norms' => $spec['gendered'],
            ])->all(),
            'tool_question_counts' => collect(ToolValidator::TOOLS)->map(fn ($spec) => $spec['count'])->all(),
        ]);
    }

    /** GET /panel/api/content/questions?tool=&language= (read-only browse). */
    public function questions(Request $request): JsonResponse
    {
        $language = $request->query('language', 'en');
        $languageKey = LegacyConfig::LANGUAGES[$language] ?? 1;
        $toolVersions = ProductCatalog::get(ProductCatalog::DEFAULT_CODE)['toolVersions'];
        $tool = $request->query('tool', 'areamissions');
        if (! isset($toolVersions[$tool])) {
            return response()->json(['error' => ['code' => 'unknown_tool', 'message' => "Unknown tool '{$tool}'."]], 404);
        }

        return response()->json([
            'tool' => $tool,
            'language' => $language,
            'questions' => DB::table('Question')
                ->where('ToolVersionKey', $toolVersions[$tool])
                ->where('LanguageKey', $languageKey)
                ->orderBy('Sequence')
                ->get(['Sequence', 'Description'])
                ->map(fn ($r) => ['q' => (int) $r->Sequence, 'text' => (string) $r->Description])
                ->all(),
        ]);
    }

    /** GET /panel/api/content/translations-summary — coverage counts per language. */
    public function translationsSummary(): JsonResponse
    {
        $counts = fn (string $table) => DB::table($table)
            ->selectRaw('LanguageKey, COUNT(*) as n')
            ->groupBy('LanguageKey')
            ->pluck('n', 'LanguageKey')
            ->all();

        $byLanguage = [];
        $names = array_flip(LegacyConfig::LANGUAGES); // key → code
        foreach (['ArchetypeDetail', 'InsightDetail', 'LookupDefinition'] as $table) {
            foreach ($counts($table) as $languageKey => $n) {
                $code = $names[$languageKey] ?? "lang{$languageKey}";
                $byLanguage[$code][$table] = (int) $n;
            }
        }

        return response()->json(['content_rows_by_language' => $byLanguage]);
    }
}
