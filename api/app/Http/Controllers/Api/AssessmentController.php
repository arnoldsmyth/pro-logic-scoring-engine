<?php

namespace App\Http\Controllers\Api;

use App\Api\ApiException;
use App\Api\ScoringService;
use App\Api\ToolValidator;
use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Assessment;
use App\Scoring\Scopes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AssessmentController extends Controller
{
    /** POST /v2/assessments — create from registration info (docs/02). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'firstname' => ['required', 'string', 'max:255'],
            'middlename' => ['nullable', 'string', 'max:255'],
            'lastname' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'language' => ['required', Rule::in(['en', 'fr', 'pt'])],
            'gender' => ['nullable', Rule::in(['M', 'F'])],
            'dob' => ['nullable', 'date'],
            'external_id' => ['nullable', 'string', 'max:255'],
        ]);

        $assessment = Assessment::create([...$data, 'api_key_id' => $this->apiKey($request)->id]);

        return response()->json($this->status($assessment), 201);
    }

    /** PUT /v2/assessments/{id}/tools/{tool} — submit/replace one tool, validated on write. */
    public function submitTool(Request $request, string $publicId, string $tool): JsonResponse
    {
        $assessment = $this->find($request, $publicId);

        if (! ToolValidator::isKnownTool($tool)) {
            throw new ApiException(404, 'unknown_tool', "Unknown tool '{$tool}'.", [
                'known_tools' => array_keys(ToolValidator::TOOLS),
            ]);
        }

        $responses = $this->normalizeResponses($request->input('responses'));
        $errors = app(ToolValidator::class)->validate($tool, $responses);
        if ($errors !== []) {
            throw new ApiException(422, 'tool_validation_failed', "Tool '{$tool}' failed validation.", [
                'errors' => $errors,
            ]);
        }

        $assessment->tools()->updateOrCreate(
            ['tool' => $tool],
            ['responses' => $responses, 'submitted_at' => now()],
        );

        return response()->json($this->status($assessment->refresh()->load('tools')));
    }

    /** GET /v2/assessments/{id} — tools received, scopes ready, results available. */
    public function show(Request $request, string $publicId): JsonResponse
    {
        return response()->json($this->status($this->find($request, $publicId)));
    }

    /** GET /v2/assessments/{id}/results — re-render any scored result (docs/05). */
    public function results(Request $request, string $publicId, ScoringService $scoring): JsonResponse
    {
        $assessment = $this->find($request, $publicId);

        $result = $assessment->results()->latest('scored_at')->first();
        if ($result === null) {
            throw new ApiException(404, 'not_scored', 'This assessment has not been scored yet.');
        }

        $scopeParam = $request->query('scope');
        $scopes = null;
        if ($scopeParam !== null) {
            [$scopes, $unknown] = Scopes::expand(array_map(trim(...), explode(',', $scopeParam)));
            if ($unknown !== []) {
                throw new ApiException(422, 'unknown_scope', 'Unknown scope(s): '.implode(', ', $unknown).'.');
            }
        }

        $format = $request->query('format', 'keys');
        if (! in_array($format, ['keys', 'strings'], true)) {
            throw new ApiException(422, 'invalid_format', "format must be 'keys' or 'strings'.");
        }

        return response()->json($scoring->render($assessment, $result, $scopes, $format, $request->query('language')));
    }

    /** GET /v2/assessments/{id}/results/audit — docs/03 audit trace. */
    public function audit(Request $request, string $publicId): JsonResponse
    {
        $assessment = $this->find($request, $publicId);

        $result = $assessment->results()->latest('scored_at')->first();
        if ($result === null) {
            throw new ApiException(404, 'not_scored', 'This assessment has not been scored yet.');
        }
        if ($result->audit === null) {
            throw new ApiException(404, 'audit_not_captured', 'No audit trace was captured for this result — pass audit:true when scoring.');
        }

        return response()->json([
            'assessment_id' => $assessment->public_id,
            'scored_at' => $result->scored_at->toIso8601String(),
            'norms' => ['set_id' => $result->norm_set],
            'audit' => $result->audit,
        ]);
    }

    private function apiKey(Request $request): ApiKey
    {
        return $request->attributes->get('apiKey');
    }

    /** Assessments are visible only to the key that created them. */
    private function find(Request $request, string $publicId): Assessment
    {
        $assessment = Assessment::query()
            ->where('public_id', $publicId)
            ->where('api_key_id', $this->apiKey($request)->id)
            ->with('tools')
            ->first();

        return $assessment ?? throw new ApiException(404, 'not_found', 'Assessment not found.');
    }

    /**
     * Accept responses as either a {q: a} map or a legacy-style
     * [{q, a}] list; normalize to an int-keyed map.
     *
     * @return array<int, mixed>
     */
    private function normalizeResponses(mixed $raw): array
    {
        if (! is_array($raw) || $raw === []) {
            throw new ApiException(422, 'invalid_request', 'Body must include a non-empty `responses` map {q: a} or list [{q, a}].');
        }

        $responses = [];
        if (array_is_list($raw) && is_array($raw[0] ?? null)) {
            foreach ($raw as $item) {
                if (! isset($item['q'])) {
                    throw new ApiException(422, 'invalid_request', 'Each responses[] item needs `q` and `a`.');
                }
                $responses[(int) $item['q']] = $item['a'] ?? null;
            }
        } else {
            foreach ($raw as $q => $a) {
                $responses[(int) $q] = $a;
            }
        }

        return $responses;
    }

    /** Status body per docs/05: tools received/valid, scopes scored. */
    private function status(Assessment $assessment): array
    {
        $assessment->loadMissing('tools', 'results');
        $submitted = $assessment->tools->pluck('tool')->all();

        $scopesReady = [];
        foreach (array_keys(Scopes::SCOPES) as $scope) {
            $scopesReady[$scope] = Scopes::missingTools([$scope], $submitted) === [];
        }

        return [
            'assessment_id' => $assessment->public_id,
            'external_id' => $assessment->external_id,
            'language' => $assessment->language,
            'gender' => $assessment->gender,
            'tools' => $assessment->tools->mapWithKeys(fn ($t) => [$t->tool => [
                'received' => true,
                'valid' => true, // validation happens on write; invalid submissions are rejected
                'submitted_at' => $t->submitted_at->toIso8601String(),
            ]])->all(),
            'scopes_ready' => $scopesReady,
            'results' => $assessment->results->map(fn ($r) => [
                'scopes' => $r->scopes,
                'norms' => ['set_id' => $r->norm_set],
                'language' => $r->language,
                'scored_at' => $r->scored_at->toIso8601String(),
            ])->all(),
        ];
    }
}
