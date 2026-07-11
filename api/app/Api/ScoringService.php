<?php

namespace App\Api;

use App\Models\AccessCode;
use App\Models\ApiKey;
use App\Models\Assessment;
use App\Models\ScoredResult;
use App\Models\UsageEvent;
use App\Scoring\Contracts\ScoringEngine;
use App\Scoring\Engine\LegacyConfig;
use App\Scoring\Engine\ProductCatalog;
use App\Scoring\Scopes;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates a scoring request (docs/05 + 07): resolve the access code,
 * enforce scope permissions, check tool completeness, resolve the norm set,
 * run the engine, persist the result, and meter the usage event with
 * fees_due computed from the code's royalty terms at event time.
 */
class ScoringService
{
    public function __construct(private readonly ScoringEngine $engine) {}

    /**
     * @param  array{scopes?: list<string>, format?: string, language?: string, norms?: string, access_code?: string}  $input
     * @return array<string, mixed> result envelope
     */
    public function score(ApiKey $apiKey, Assessment $assessment, array $input): array
    {
        [$scopes, $unknown] = Scopes::expand($input['scopes'] ?? ['full']);
        if ($unknown !== []) {
            throw new ApiException(422, 'unknown_scope', 'Unknown scope(s): '.implode(', ', $unknown).'.', [
                'known_scopes' => [...array_keys(Scopes::SCOPES), 'full'],
            ]);
        }

        $format = $input['format'] ?? 'keys';
        if (! in_array($format, ['keys', 'strings'], true)) {
            throw new ApiException(422, 'invalid_format', "format must be 'keys' or 'strings'.");
        }

        $language = strtolower($input['language'] ?? $assessment->language);
        if (! isset(LegacyConfig::LANGUAGES[$language]) || $language === 'tr') {
            throw new ApiException(422, 'unsupported_language', "Language '{$language}' is not supported. Supported: en, fr, pt.");
        }

        $code = $this->resolveAccessCode($apiKey, $input['access_code'] ?? null);
        $this->enforceScopePermissions($code, $scopes);

        $product = $code->product_code;
        if (! isset(ProductCatalog::PRODUCTS[$product])) {
            throw new ApiException(422, 'unknown_product', "Access code maps to unknown product '{$product}'.");
        }

        $submitted = $assessment->tools->pluck('tool')->all();
        $missing = Scopes::missingTools($scopes, $submitted);
        if ($missing !== []) {
            throw new ApiException(422, 'missing_tools', 'Requested scopes need tools that have not been submitted.', [
                'missing_tools_per_scope' => $missing,
            ]);
        }

        $normSet = $this->resolveNormSet($assessment, $scopes, $input['norms'] ?? null);

        $toolResponses = $assessment->toolResponses();
        $engineNormSet = $normSet === 'none' ? 'male-legacy' : $normSet; // norms never touch non-gendered scopes
        $registration = ['gender' => $assessment->gender, 'language' => $language];

        $results = $this->engine->score($registration, $toolResponses, $scopes, $engineNormSet, $format, $product);
        $payload = Scopes::filter($results, $scopes, $toolResponses['reflections'] ?? null);

        // Keys format is what gets persisted (docs/03: strings render on
        // demand, never stored twice).
        $keysPayload = $format === 'keys'
            ? $payload
            : Scopes::filter($this->engine->score($registration, $toolResponses, $scopes, $engineNormSet, 'keys', $product), $scopes, $toolResponses['reflections'] ?? null);

        $scoredAt = now();
        DB::transaction(function () use ($apiKey, $assessment, $code, $scopes, $normSet, $product, $language, $keysPayload, $scoredAt) {
            ScoredResult::create([
                'assessment_id' => $assessment->id,
                'scopes' => $scopes,
                'norm_set' => $normSet,
                'product_code' => $product,
                'access_code_id' => $code->id,
                'language' => $language,
                'results' => $keysPayload,
                'scored_at' => $scoredAt,
            ]);

            UsageEvent::create([
                'api_key_id' => $apiKey->id,
                'access_code_id' => $code->id,
                'code_type' => $code->type,
                'product_code' => $product,
                'assessment_id' => $assessment->id,
                'scopes' => $scopes,
                'fees_due' => $code->feesDueNow(),
                'created_at' => $scoredAt,
            ]);

            $code->increment('uses_count');
        });

        return [
            'assessment_id' => $assessment->public_id,
            'external_id' => $assessment->external_id,
            'scored_at' => $scoredAt->toIso8601String(),
            'language' => $language,
            'format' => $format,
            'norms' => ['set_id' => $normSet, 'provisional' => false],
            'scopes' => $payload,
        ];
    }

    /**
     * Re-render a stored result in any format/language (docs/05: results are
     * re-renderable any time; strings are resolved at request time). Keys
     * payloads are served from storage; strings re-run the engine over the
     * stored inputs with the recorded norm set — deterministic by the golden
     * contract.
     *
     * @param  list<string>|null  $scopes  subset of the stored scopes, null = all
     */
    public function render(Assessment $assessment, ScoredResult $result, ?array $scopes, string $format, ?string $language): array
    {
        $scopes ??= $result->scopes;
        $notScored = array_diff($scopes, $result->scopes);
        if ($notScored !== []) {
            throw new ApiException(422, 'scope_not_scored', 'Scope(s) not part of this result: '.implode(', ', $notScored).'.', [
                'scored_scopes' => $result->scopes,
            ]);
        }

        $language = strtolower($language ?? $result->language);
        if (! isset(LegacyConfig::LANGUAGES[$language]) || $language === 'tr') {
            throw new ApiException(422, 'unsupported_language', "Language '{$language}' is not supported. Supported: en, fr, pt.");
        }

        if ($format === 'keys') {
            $payload = array_intersect_key($result->results, array_flip($scopes));
        } else {
            $toolResponses = $assessment->toolResponses();
            $engineNormSet = $result->norm_set === 'none' ? 'male-legacy' : $result->norm_set;
            $body = $this->engine->score(['gender' => $assessment->gender, 'language' => $language], $toolResponses, $scopes, $engineNormSet, 'strings', $result->product_code);
            $payload = Scopes::filter($body, $scopes, $toolResponses['reflections'] ?? null);
        }

        return [
            'assessment_id' => $assessment->public_id,
            'external_id' => $assessment->external_id,
            'scored_at' => $result->scored_at->toIso8601String(),
            'language' => $language,
            'format' => $format,
            'norms' => ['set_id' => $result->norm_set, 'provisional' => false],
            'scopes' => $payload,
        ];
    }

    private function resolveAccessCode(ApiKey $apiKey, ?string $codeString): AccessCode
    {
        if ($codeString !== null) {
            $code = AccessCode::query()->where('code', $codeString)->first();
            if ($code === null) {
                throw new ApiException(403, 'unknown_access_code', 'Access code not recognized.');
            }
        } else {
            $code = $apiKey->defaultAccessCode;
            if ($code === null) {
                throw new ApiException(403, 'access_code_required', 'No access_code given and this API key has no default code.');
            }
        }

        if (! $code->isUsable()) {
            throw new ApiException(403, 'access_code_unusable', 'Access code is inactive, expired, or exhausted.', [
                'active' => $code->active,
                'expires_at' => $code->expires_at?->toIso8601String(),
                'max_uses' => $code->max_uses,
                'uses_count' => $code->uses_count,
            ]);
        }

        return $code;
    }

    /** docs/07: requested scopes must be ⊆ the code's allowed scopes. */
    private function enforceScopePermissions(AccessCode $code, array $scopes): void
    {
        $allowed = Scopes::allowedSet($code->allowed_scopes);
        $denied = array_values(array_diff($scopes, $allowed));
        if ($denied !== []) {
            throw new ApiException(403, 'scope_not_allowed', 'Access code does not permit scope(s): '.implode(', ', $denied).'.', [
                'allowed_scopes' => $allowed,
            ]);
        }
    }

    /**
     * docs/04 + 06: gender-split norms apply only to scopes touching the S
     * or P dimensions. Explicit `norms` wins; otherwise the registrant's
     * gender selects the matching legacy set. Versioned/pooled sets land in
     * phase 6.
     */
    private function resolveNormSet(Assessment $assessment, array $scopes, ?string $norms): string
    {
        if (! Scopes::anyGendered($scopes)) {
            return 'none';
        }

        $requested = $norms ?? match ($assessment->gender) {
            'M' => 'male',
            'F' => 'female',
            default => throw new ApiException(422, 'norms_required', 'Requested scopes use gender-split norms; registration has no gender, so pass an explicit `norms` value.', [
                'available' => ['male', 'female'],
            ]),
        };

        return match ($requested) {
            'male', 'male-legacy' => 'male-legacy',
            'female', 'female-legacy' => 'female-legacy',
            'pooled' => throw new ApiException(422, 'norm_set_unavailable', 'Pooled norms (pooled-v1) arrive with the norm analytics phase — use male or female for now.'),
            default => throw new ApiException(422, 'norm_set_unavailable', "Unknown norm set '{$requested}'.", ['available' => ['male', 'female']]),
        };
    }
}
