<?php

namespace Tests\Feature\Api;

use App\Models\AccessCode;
use App\Models\ApiKey;
use App\Models\Assessment;
use App\Scoring\Scopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API surface behaviors that need no scoring engine: auth, registration,
 * tool validation, status, scope/tool preconditions, access-code
 * permissions. Engine-inclusive flows live in ScoringFlowTest.
 */
class ApiSurfaceTest extends TestCase
{
    use RefreshDatabase;

    private ApiKey $key;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        ['token' => $this->token, 'attributes' => $attributes] = ApiKey::generate();
        $this->key = ApiKey::create([...$attributes, 'name' => 'test']);
    }

    private function authed(): static
    {
        $this->withHeader('Authorization', "Bearer {$this->token}");

        return $this;
    }

    private function makeAssessment(array $overrides = []): Assessment
    {
        return Assessment::create([
            'api_key_id' => $this->key->id,
            'firstname' => 'Ada',
            'lastname' => 'Lovelace',
            'email' => 'ada@example.com',
            'language' => 'en',
            'gender' => 'F',
            ...$overrides,
        ]);
    }

    public function test_requests_without_bearer_token_are_rejected(): void
    {
        $this->postJson('/v2/assessments', [])->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_unknown_token_is_rejected(): void
    {
        $this->withHeader('Authorization', 'Bearer sk_nonsense')
            ->postJson('/v2/assessments', [])->assertStatus(401);
    }

    public function test_revoked_key_is_rejected(): void
    {
        $this->key->update(['active' => false]);
        $this->authed()->postJson('/v2/assessments', [])->assertStatus(401);
    }

    public function test_create_assessment_returns_status_body(): void
    {
        $response = $this->authed()->postJson('/v2/assessments', [
            'firstname' => 'Ada',
            'lastname' => 'Lovelace',
            'email' => 'ada@example.com',
            'language' => 'en',
            'gender' => 'F',
            'external_id' => 'client-123',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('external_id', 'client-123')
            ->assertJsonPath('scopes_ready.mcs', false);
        $this->assertNotEmpty($response->json('assessment_id'));
    }

    public function test_registration_validation_uses_error_envelope(): void
    {
        $this->authed()->postJson('/v2/assessments', ['firstname' => 'Ada'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_request');
    }

    public function test_tool_submission_validates_ranking_counts(): void
    {
        $assessment = $this->makeAssessment();

        // 27 answers but wrong ranking distribution (4×"3").
        $responses = array_fill(1, 27, 0);
        foreach ([1, 2, 3, 4] as $q) {
            $responses[$q] = 3;
        }

        $this->authed()->putJson("/v2/assessments/{$assessment->public_id}/tools/personalmotivators", ['responses' => $responses])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'tool_validation_failed')
            ->assertJsonPath('error.details.errors.0.rule', 'ranking_counts');
    }

    public function test_tool_submission_validates_question_set(): void
    {
        $assessment = $this->makeAssessment();

        $this->authed()->putJson("/v2/assessments/{$assessment->public_id}/tools/abilitiesfilter", ['responses' => [1 => 3]])
            ->assertStatus(422)
            ->assertJsonPath('error.details.errors.0.rule', 'question_set');
    }

    public function test_valid_tool_submission_updates_scope_readiness(): void
    {
        $assessment = $this->makeAssessment();

        $response = $this->authed()->putJson("/v2/assessments/{$assessment->public_id}/tools/role", ['responses' => array_fill(1, 54, 4)])
            ->assertOk()
            ->assertJsonPath('tools.role.received', true)
            ->assertJsonPath('scopes_ready.mcs', false);
        $this->assertTrue($response->json('scopes_ready')['pro.role']);
    }

    public function test_legacy_style_response_list_is_accepted(): void
    {
        $assessment = $this->makeAssessment();
        $responses = array_map(fn (int $q) => ['q' => $q, 'a' => 2], range(1, 54));

        $response = $this->authed()->putJson("/v2/assessments/{$assessment->public_id}/tools/organization", ['responses' => $responses])
            ->assertOk();
        $this->assertTrue($response->json('scopes_ready')['pro.org']);
    }

    public function test_assessments_are_scoped_to_their_key(): void
    {
        $assessment = $this->makeAssessment();
        ['token' => $otherToken, 'attributes' => $attributes] = ApiKey::generate();
        ApiKey::create([...$attributes, 'name' => 'other']);

        $this->withHeader('Authorization', "Bearer {$otherToken}")
            ->getJson("/v2/assessments/{$assessment->public_id}")
            ->assertStatus(404);
    }

    public function test_scoring_requires_an_access_code(): void
    {
        $assessment = $this->makeAssessment();

        $this->authed()->postJson("/v2/assessments/{$assessment->public_id}/score", ['scopes' => ['pro.role']])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'access_code_required');
    }

    public function test_scoring_rejects_scopes_outside_the_codes_allowance(): void
    {
        $assessment = $this->makeAssessment();
        $code = AccessCode::create([
            'code' => AccessCode::generateCode(),
            'order_type' => 'training',
            'product_code' => 'VC18',
            'allowed_scopes' => ['pro.role'],
        ]);

        $this->authed()->postJson("/v2/assessments/{$assessment->public_id}/score", [
            'scopes' => ['mcs'],
            'access_code' => $code->code,
        ])->assertStatus(403)
            ->assertJsonPath('error.code', 'scope_not_allowed');
    }

    public function test_scoring_lists_missing_tools_per_scope(): void
    {
        $assessment = $this->makeAssessment();
        $code = AccessCode::create([
            'code' => AccessCode::generateCode(),
            'order_type' => 'training',
            'product_code' => 'VC18',
            'allowed_scopes' => ['full'],
        ]);

        $this->authed()->postJson("/v2/assessments/{$assessment->public_id}/score", [
            'scopes' => ['pro.role'],
            'access_code' => $code->code,
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'missing_tools');
        $this->assertSame(['role'], $this->authed()->postJson("/v2/assessments/{$assessment->public_id}/score", [
            'scopes' => ['pro.role'],
            'access_code' => $code->code,
        ])->json('error.details.missing_tools_per_scope')['pro.role']);
    }

    public function test_exhausted_code_is_rejected(): void
    {
        $assessment = $this->makeAssessment();
        $code = AccessCode::create([
            'code' => AccessCode::generateCode(),
            'order_type' => 'training',
            'product_code' => 'VC18',
            'allowed_scopes' => ['full'],
            'max_uses' => 1,
            'uses_count' => 1,
        ]);

        $this->authed()->postJson("/v2/assessments/{$assessment->public_id}/score", [
            'scopes' => ['pro.role'],
            'access_code' => $code->code,
        ])->assertStatus(403)
            ->assertJsonPath('error.code', 'access_code_unusable');
    }

    public function test_gendered_scope_without_gender_falls_back_to_pooled_which_is_unavailable(): void
    {
        $assessment = $this->makeAssessment(['gender' => null]);
        $assessment->tools()->create(['tool' => 'person', 'responses' => array_fill(1, 54, 3), 'submitted_at' => now()]);
        $assessment->tools()->create(['tool' => 'personalexpectations', 'responses' => array_fill(1, 72, 2), 'submitted_at' => now()]);
        $style = [];
        for ($g = 0; $g < 24; $g++) {
            $style[$g * 4 + 1] = 1;
            $style[$g * 4 + 2] = -1;
            $style[$g * 4 + 3] = 0;
            $style[$g * 4 + 4] = 0;
        }
        $assessment->tools()->create(['tool' => 'personalstyle', 'responses' => $style, 'submitted_at' => now()]);

        $code = AccessCode::create([
            'code' => AccessCode::generateCode(),
            'order_type' => 'training',
            'product_code' => 'VC18',
            'allowed_scopes' => ['full'],
        ]);

        $this->authed()->postJson("/v2/assessments/{$assessment->public_id}/score", [
            'scopes' => ['mcs.s'],
            'access_code' => $code->code,
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'norm_set_unavailable');
    }

    public function test_reference_scopes_is_self_documenting(): void
    {
        $scopes = $this->authed()->getJson('/v2/reference/scopes')
            ->assertOk()
            ->json('scopes');
        $this->assertSame(['areamissions', 'personalmotivators'], $scopes['mcs.m']['required_tools']);
        $this->assertFalse($scopes['pro.role']['uses_gender_split_norms']);
        $this->assertSame(Scopes::FULL, $scopes['full']['alias_for']);
    }

    public function test_rate_limit_applies_per_key(): void
    {
        $this->key->update(['rate_limit_per_minute' => 2]);

        $this->authed()->getJson('/v2/reference/scopes')->assertOk();
        $this->authed()->getJson('/v2/reference/scopes')->assertOk();
        $this->authed()->getJson('/v2/reference/scopes')
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'rate_limited');
    }

    public function test_idempotency_replays_identical_requests(): void
    {
        $body = [
            'firstname' => 'Ada',
            'lastname' => 'Lovelace',
            'email' => 'ada@example.com',
            'language' => 'en',
        ];

        $first = $this->authed()->withHeader('Idempotency-Key', 'idem-1')
            ->postJson('/v2/assessments', $body);
        $second = $this->authed()->withHeader('Idempotency-Key', 'idem-1')
            ->postJson('/v2/assessments', $body);

        $second->assertStatus(201)->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame($first->json('assessment_id'), $second->json('assessment_id'));
        $this->assertSame(1, Assessment::count());
    }

    public function test_openapi_document_is_published(): void
    {
        $spec = $this->getJson('/openapi.json')->assertOk()->json();
        $this->assertSame('3.1.0', $spec['openapi']);
        $this->assertArrayHasKey('/v2/score', $spec['paths']);
        $this->assertArrayHasKey('scored', $spec['webhooks']);

        $this->get('/docs')->assertOk()->assertSee('api-reference', false);
    }

    public function test_idempotency_conflicts_on_payload_change(): void
    {
        $body = ['firstname' => 'Ada', 'lastname' => 'Lovelace', 'email' => 'ada@example.com', 'language' => 'en'];

        $this->authed()->withHeader('Idempotency-Key', 'idem-2')->postJson('/v2/assessments', $body)->assertStatus(201);
        $this->authed()->withHeader('Idempotency-Key', 'idem-2')
            ->postJson('/v2/assessments', [...$body, 'firstname' => 'Grace'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'idempotency_conflict');
    }
}
