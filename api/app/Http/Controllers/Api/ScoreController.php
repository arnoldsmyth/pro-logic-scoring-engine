<?php

namespace App\Http\Controllers\Api;

use App\Api\ApiException;
use App\Api\ScoringService;
use App\Api\ToolValidator;
use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Assessment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ScoreController extends Controller
{
    public function __construct(private readonly ScoringService $scoring) {}

    /** POST /v2/assessments/{id}/score */
    public function scoreAssessment(Request $request, string $publicId): JsonResponse
    {
        $apiKey = $this->apiKey($request);
        $assessment = Assessment::query()
            ->where('public_id', $publicId)
            ->where('api_key_id', $apiKey->id)
            ->with('tools')
            ->first() ?? throw new ApiException(404, 'not_found', 'Assessment not found.');

        return response()->json($this->scoring->score($apiKey, $assessment, $request->all()));
    }

    /**
     * POST /v2/score — one-shot convenience (docs/05): registration + tools
     * + scoring params in a single synchronous call.
     */
    public function oneShot(Request $request): JsonResponse
    {
        $apiKey = $this->apiKey($request);

        $data = $request->validate([
            'registration' => ['required', 'array'],
            'registration.firstname' => ['required', 'string', 'max:255'],
            'registration.middlename' => ['nullable', 'string', 'max:255'],
            'registration.lastname' => ['required', 'string', 'max:255'],
            'registration.email' => ['required', 'email', 'max:255'],
            'registration.language' => ['required', Rule::in(['en', 'fr', 'pt'])],
            'registration.gender' => ['nullable', Rule::in(['M', 'F'])],
            'registration.dob' => ['nullable', 'date'],
            'registration.external_id' => ['nullable', 'string', 'max:255'],
            'tools' => ['required', 'array', 'min:1'],
        ]);

        // Validate every tool before creating anything — all errors at once.
        $validator = app(ToolValidator::class);
        $normalized = [];
        $errors = [];
        foreach ($request->input('tools') as $tool => $responses) {
            // Accept both {tool: responses} maps and legacy [{tool, responses}] lists.
            if (is_int($tool) && is_array($responses)) {
                $tool = $responses['tool'] ?? '';
                $responses = $responses['responses'] ?? [];
            }
            if (! ToolValidator::isKnownTool((string) $tool)) {
                $errors[] = ['tool' => (string) $tool, 'q' => null, 'rule' => 'known_tool', 'expected' => implode('|', array_keys(ToolValidator::TOOLS)), 'got' => (string) $tool];

                continue;
            }
            $map = [];
            if (is_array($responses) && array_is_list($responses) && is_array($responses[0] ?? null)) {
                foreach ($responses as $item) {
                    $map[(int) ($item['q'] ?? 0)] = $item['a'] ?? null;
                }
            } elseif (is_array($responses)) {
                foreach ($responses as $q => $a) {
                    $map[(int) $q] = $a;
                }
            }
            $errors = [...$errors, ...$validator->validate((string) $tool, $map)];
            $normalized[(string) $tool] = $map;
        }
        if ($errors !== []) {
            throw new ApiException(422, 'tool_validation_failed', 'One or more tools failed validation.', ['errors' => $errors]);
        }

        $assessment = Assessment::create([...$data['registration'], 'api_key_id' => $apiKey->id]);
        $now = now();
        $assessment->tools()->createMany(array_map(
            fn (string $tool) => ['tool' => $tool, 'responses' => $normalized[$tool], 'submitted_at' => $now],
            array_keys($normalized),
        ));
        $assessment->load('tools');

        return response()->json($this->scoring->score($apiKey, $assessment, $request->all()), 201);
    }

    private function apiKey(Request $request): ApiKey
    {
        return $request->attributes->get('apiKey');
    }
}
