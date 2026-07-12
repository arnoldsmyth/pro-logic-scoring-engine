<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Scoring\Scopes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Assessments search + detail + audit trace (docs/08 §4). */
class AssessmentsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $assessments = Assessment::query()
            ->with('apiKey:id,name')
            ->withCount('tools', 'results')
            ->when($request->query('q'), function ($query, $q) {
                $query->where(fn ($w) => $w
                    ->where('external_id', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhere('lastname', 'like', "%{$q}%"));
            })
            ->when($request->query('from'), fn ($q, $from) => $q->where('created_at', '>=', $from))
            ->when($request->query('to'), fn ($q, $to) => $q->where('created_at', '<=', $to.' 23:59:59'))
            ->latest('id')
            ->paginate(25);

        return response()->json([
            'assessments' => collect($assessments->items())->map(fn (Assessment $a) => [
                'public_id' => $a->public_id,
                'external_id' => $a->external_id,
                'name' => trim("{$a->firstname} {$a->lastname}"),
                'email' => $a->email,
                'language' => $a->language,
                'gender' => $a->gender,
                'api_key' => $a->apiKey?->name,
                'tools_submitted' => $a->tools_count,
                'times_scored' => $a->results_count,
                'created_at' => $a->created_at->toIso8601String(),
            ])->all(),
            'page' => $assessments->currentPage(),
            'last_page' => $assessments->lastPage(),
            'total' => $assessments->total(),
        ]);
    }

    public function show(string $publicId): JsonResponse
    {
        $a = Assessment::query()->where('public_id', $publicId)->with('tools', 'results.accessCode')->firstOrFail();
        $submitted = $a->tools->pluck('tool')->all();

        return response()->json([
            'public_id' => $a->public_id,
            'external_id' => $a->external_id,
            'name' => trim("{$a->firstname} {$a->lastname}"),
            'email' => $a->email,
            'language' => $a->language,
            'gender' => $a->gender,
            'created_at' => $a->created_at->toIso8601String(),
            'tools' => $a->tools->map(fn ($t) => [
                'tool' => $t->tool,
                'answers' => count($t->responses),
                'submitted_at' => $t->submitted_at->toIso8601String(),
            ])->all(),
            'scopes_ready' => collect(Scopes::SCOPES)->map(fn ($spec, $scope) => Scopes::missingTools([$scope], $submitted) === [])->all(),
            'results' => $a->results->map(fn ($r) => [
                'id' => $r->id,
                'scopes' => $r->scopes,
                'norm_set' => $r->norm_set,
                'product_code' => $r->product_code,
                'access_code' => $r->accessCode?->code,
                'language' => $r->language,
                'has_audit' => $r->audit !== null,
                'scored_at' => $r->scored_at->toIso8601String(),
                'results' => $r->results,
            ])->all(),
        ]);
    }

    /**
     * Person timeline (decided 2026-07-11): every assessment linked to the
     * same person — caller-supplied external_id per key, exact-email
     * fallback — ordered over time with their results, so the panel can
     * show retakes and score deltas between takes. Each take stays a fully
     * independent submission; only this reporting link exists.
     */
    public function personTimeline(string $publicId): JsonResponse
    {
        $a = Assessment::query()->where('public_id', $publicId)->firstOrFail();

        $takes = $a->samePersonQuery()
            ->with('results')
            ->orderBy('created_at')
            ->get()
            ->map(fn (Assessment $take) => [
                'public_id' => $take->public_id,
                'external_id' => $take->external_id,
                'email' => $take->email,
                'created_at' => $take->created_at->toIso8601String(),
                'is_current' => $take->id === $a->id,
                'results' => $take->results->sortBy('scored_at')->values()->map(fn ($r) => [
                    'scopes' => $r->scopes,
                    'norm_set' => $r->norm_set,
                    'scored_at' => $r->scored_at->toIso8601String(),
                    'results' => $r->results,
                ])->all(),
            ]);

        return response()->json([
            'identity' => $a->external_id !== null && $a->external_id !== ''
                ? ['matched_by' => 'external_id', 'value' => $a->external_id]
                : ['matched_by' => 'email', 'value' => $a->email],
            'takes' => $takes->all(),
        ]);
    }

    /** The audit-trace walkthrough data (docs/08: UI over results/audit). */
    public function audit(string $publicId, int $resultId): JsonResponse
    {
        $a = Assessment::query()->where('public_id', $publicId)->firstOrFail();
        $result = $a->results()->findOrFail($resultId);
        if ($result->audit === null) {
            return response()->json(['error' => ['code' => 'audit_not_captured', 'message' => 'This result was scored without audit:true.']], 404);
        }

        $rules = $result->audit['rules_fired'] ?? [];
        $byStage = [];
        foreach ($rules as $r) {
            $byStage[$r['stage']][] = $r;
        }

        return response()->json([
            'norm_set' => $result->norm_set,
            'scored_at' => $result->scored_at->toIso8601String(),
            'stages' => collect($byStage)->map(fn ($stageRules, $stage) => [
                'rules_fired' => count($stageRules),
                'rules' => $stageRules,
                'scale_values' => $result->audit['stage_scale_values'][$stage] ?? [],
            ])->all(),
            'content_keys_resolved' => $result->audit['content_keys_resolved'] ?? [],
        ]);
    }
}
