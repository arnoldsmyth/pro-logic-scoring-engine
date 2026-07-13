<?php

namespace App\Api;

use App\Jobs\DeliverWebhook;
use App\Models\AccessCode;
use App\Models\ApiKey;
use App\Models\Assessment;
use App\Models\NormSample;
use App\Models\NormSet;
use App\Models\ScoredResult;
use App\Models\UsageEvent;
use App\Models\WebhookDelivery;
use App\Scoring\Contracts\ScoringEngine;
use App\Scoring\Engine\AuditCollector;
use App\Scoring\Engine\LegacyConfig;
use App\Scoring\Engine\NormSampler;
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

        [$normSet, $provisional] = $this->resolveNormSet($assessment, $scopes, $input['norms'] ?? null);

        $toolResponses = $assessment->toolResponses();
        $engineNormSet = $normSet === 'none' ? 'male-legacy' : $normSet; // norms never touch non-gendered scopes
        $registration = ['gender' => $assessment->gender, 'language' => $language];
        $audit = ($input['audit'] ?? false) ? new AuditCollector : null;
        $sampler = new NormSampler;

        $results = $this->engine->score($registration, $toolResponses, $scopes, $engineNormSet, $format, $product, $audit, $sampler);
        $payload = Scopes::filter($results, $scopes, $toolResponses['reflections'] ?? null);

        // Keys format is what gets persisted (docs/03: strings render on
        // demand, never stored twice).
        $keysPayload = $format === 'keys'
            ? $payload
            : Scopes::filter($this->engine->score($registration, $toolResponses, $scopes, $engineNormSet, 'keys', $product), $scopes, $toolResponses['reflections'] ?? null);

        $scoredAt = now();
        DB::transaction(function () use ($apiKey, $assessment, $code, $scopes, $normSet, $product, $language, $keysPayload, $scoredAt, $audit, $sampler) {
            ScoredResult::create([
                'assessment_id' => $assessment->id,
                'scopes' => $scopes,
                'norm_set' => $normSet,
                'product_code' => $product,
                'access_code_id' => $code->id,
                'language' => $language,
                'results' => $keysPayload,
                'audit' => $audit?->toArray(),
                'scored_at' => $scoredAt,
            ]);

            $event = UsageEvent::create([
                'api_key_id' => $apiKey->id,
                'access_code_id' => $code->id,
                'code_type' => $code->order_type,
                'product_code' => $product,
                'assessment_id' => $assessment->id,
                'scopes' => $scopes,
                'fees_due' => [],
                'created_at' => $scoredAt,
            ]);

            // Charges & payouts ledger (charges-payouts-data-model.md):
            // every usage logs a charge; only the first real charge per
            // order splits into payouts. fees_due mirrors the payout lines
            // as a snapshot on the raw access log.
            $charge = $code->recordCharge($event, $assessment, $language);
            $event->update(['fees_due' => $charge->payouts()->get(['payout_term_id', 'recipient', 'category', 'payout_type', 'amount', 'currency', 'language'])->toArray()]);

            $code->increment('uses_count');

            NormSample::record($language, $assessment->gender, $sampler->observations);
        });

        $envelope = [
            'assessment_id' => $assessment->public_id,
            'external_id' => $assessment->external_id,
            'scored_at' => $scoredAt->toIso8601String(),
            'language' => $language,
            'format' => $format,
            'norms' => ['set_id' => $normSet, 'provisional' => $provisional],
            'scopes' => $payload,
        ];

        // Optional per-key webhook (docs/05): scored event, HMAC-signed,
        // delivered async with retries — never blocks the sync response.
        if ($apiKey->webhook_url !== null) {
            $delivery = WebhookDelivery::create([
                'api_key_id' => $apiKey->id,
                'event' => 'scored',
                'url' => $apiKey->webhook_url,
                'payload' => ['event' => 'scored', ...$envelope, 'format' => 'keys', 'scopes' => $keysPayload],
            ]);
            DeliverWebhook::dispatch($delivery->id, $apiKey->webhook_secret ?? '');
        }

        return $envelope;
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
     * gender selects the matching legacy set, and gender-unspecified
     * registrants fall back to the active pooled set (decided 2026-07-11).
     * Returns [slug, provisional].
     *
     * @return array{0: string, 1: bool}
     */
    private function resolveNormSet(Assessment $assessment, array $scopes, ?string $norms): array
    {
        if (! Scopes::anyGendered($scopes)) {
            return ['none', false];
        }

        $requested = $norms ?? match ($assessment->gender) {
            'M' => 'male',
            'F' => 'female',
            default => 'pooled',
        };

        switch ($requested) {
            case 'male':
            case 'male-legacy':
                return ['male-legacy', false];
            case 'female':
            case 'female-legacy':
                return ['female-legacy', false];
            case 'pooled':
                $pooled = NormSet::query()->where('status', 'active')->whereNull('gender')->orderByDesc('activated_at')->first();
                if ($pooled === null) {
                    throw new ApiException(422, 'norm_set_unavailable', $norms === null
                        ? 'Registration has no gender and no pooled norm set is active yet — pass an explicit `norms` value.'
                        : 'No pooled norm set is active yet (pooled-v1 lands with the historical derivation) — use male or female for now.', [
                            'available' => $this->availableNormSets(),
                        ]);
                }

                return [$pooled->slug, $pooled->provisional];
            default:
                $set = NormSet::query()->where('slug', $requested)->first();
                if ($set === null) {
                    throw new ApiException(422, 'norm_set_unavailable', "Unknown norm set '{$requested}'.", ['available' => $this->availableNormSets()]);
                }
                if ($set->status !== 'active') {
                    throw new ApiException(422, 'norm_set_unavailable', "Norm set '{$requested}' is {$set->status}, not active — only active sets score client requests. Candidate sets are exercised via the impact-report pipeline.", ['available' => $this->availableNormSets()]);
                }

                return [$set->slug, $set->provisional];
        }
    }

    /** @return list<string> */
    private function availableNormSets(): array
    {
        return ['male', 'female', ...NormSet::query()->where('status', 'active')->pluck('slug')->all()];
    }
}
