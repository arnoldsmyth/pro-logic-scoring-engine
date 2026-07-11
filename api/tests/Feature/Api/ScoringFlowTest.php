<?php

namespace Tests\Feature\Api;

use App\Models\AccessCode;
use App\Models\ApiKey;
use App\Models\NormSample;
use App\Models\NormSet;
use App\Models\RoyaltyTerm;
use App\Models\UsageEvent;
use App\Models\WebhookDelivery;
use App\Scoring\GoldenMaster\GoldenRepository;
use App\Scoring\Norms\NormAnalytics;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * End-to-end scoring through the HTTP API against a golden session: the
 * envelope's scope payloads must match the legacy results exactly, and the
 * usage event must meter the call with the code's royalty terms (docs/05 +
 * 07). Same environment contract as GoldenMasterTest: runs on the dev
 * database (legacy config + goldens present), skips elsewhere.
 */
#[Group('goldens')]
class ScoringFlowTest extends TestCase
{
    private GoldenRepository $goldens;

    protected function setUp(): void
    {
        parent::setUp();

        $this->goldens = new GoldenRepository;
        if (! $this->goldens->available()) {
            $this->markTestSkipped('Goldens not present (expected off-repo).');
        }
        $devDb = base_path('database/database.sqlite');
        if (! is_file($devDb)) {
            $this->markTestSkipped('Dev database not present.');
        }
        config(['database.connections.sqlite.database' => $devDb]);
        DB::purge('sqlite');
        if (! Schema::hasTable('ToolRule')) {
            $this->markTestSkipped('Legacy config not imported — run `php artisan legacy:import` first.');
        }

        Artisan::call('migrate', ['--force' => true]); // API tables on the dev DB, idempotent
        DB::beginTransaction(); // keep test keys/assessments out of the dev DB
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_webhook_delivers_scored_event_with_valid_hmac(): void
    {
        Http::fake(['partner.test/*' => Http::response(['ok' => true])]);

        $session = $this->goldens->all()[0];
        ['token' => $token, 'attributes' => $attributes] = ApiKey::generate();
        ApiKey::create([
            ...$attributes,
            'name' => 'webhook-e2e',
            'webhook_url' => 'https://partner.test/hooks/scoring',
            'webhook_secret' => 'whsec_test',
        ]);
        $code = AccessCode::create([
            'code' => AccessCode::generateCode(),
            'type' => 'bizdev',
            'product_code' => 'VC18',
            'allowed_scopes' => ['pro.org'],
        ]);
        $this->withHeader('Authorization', "Bearer {$token}");

        $this->postJson('/v2/score', [
            'registration' => ['firstname' => 'Hook', 'lastname' => 'Test', 'email' => 'hook@example.com', 'language' => 'en'],
            'tools' => ['organization' => $session->tools()['organization']],
            'scopes' => ['pro.org'],
            'access_code' => $code->code,
        ])->assertStatus(201);

        Http::assertSent(function ($request) {
            $body = $request->body();

            return $request->url() === 'https://partner.test/hooks/scoring'
                && $request->header('X-Event')[0] === 'scored'
                && $request->header('X-Signature')[0] === hash_hmac('sha256', $body, 'whsec_test')
                && json_decode($body, true)['event'] === 'scored';
        });

        $delivery = WebhookDelivery::query()->latest('id')->first();
        $this->assertSame('delivered', $delivery->status);
        $this->assertSame(1, $delivery->attempts);
    }

    public function test_versioned_norm_sets_score_and_lifecycle_works(): void
    {
        Artisan::call('norms:seed-legacy');
        $session = $this->goldens->all()[0];
        $gender = strtoupper($session->registration()['gender'] ?? 'M');
        $legacySlug = $gender === 'F' ? 'female-legacy' : 'male-legacy';

        // A versioned set cloned from the matching legacy set must score
        // byte-identically to it.
        $legacy = NormSet::query()->where('slug', $legacySlug)->first();
        $clone = NormSet::create([
            'slug' => 'clone-v1',
            'status' => 'active',
            'language' => 'en',
            'gender' => $gender,
            'provenance' => ['method' => 'test clone'],
            'activated_at' => now(),
        ]);
        $clone->entries()->createMany(
            $legacy->entries()->get(['tool_scale_detail_key', 'raw', 'normed'])->map->only(['tool_scale_detail_key', 'raw', 'normed'])->all()
        );

        ['token' => $token, 'attributes' => $attributes] = ApiKey::generate();
        ApiKey::create([...$attributes, 'name' => 'norms-e2e']);
        $code = AccessCode::create([
            'code' => AccessCode::generateCode(),
            'type' => 'training',
            'product_code' => 'VC18',
            'allowed_scopes' => ['full'],
        ]);
        $this->withHeader('Authorization', "Bearer {$token}");

        $body = [
            'registration' => ['firstname' => 'Norm', 'lastname' => 'Test', 'email' => 'n@example.com', 'language' => 'en', 'gender' => $gender],
            'tools' => $session->tools(),
            'scopes' => ['mcs', 'pro.person'],
            'access_code' => $code->code,
        ];
        $viaLegacy = $this->postJson('/v2/score', [...$body, 'norms' => $legacySlug])->assertStatus(201)->json();
        $viaClone = $this->postJson('/v2/score', [...$body, 'norms' => 'clone-v1'])->assertStatus(201)->json();

        $this->assertSame('clone-v1', $viaClone['norms']['set_id']);
        $this->assertSame($viaLegacy['scopes'], $viaClone['scopes'], 'versioned set cloned from legacy must score identically');

        // Sampling: gendered scoring accumulated anonymized raw observations.
        $this->assertGreaterThan(0, NormSample::query()->where('language', 'en')->where('gender', $gender)->sum('count'));

        // Candidate lifecycle: build (forced — tiny sample), impact, promote.
        $analytics = app(NormAnalytics::class);
        $candidate = $analytics->buildCandidate('test-candidate', 'en', $gender, force: true);
        $this->assertSame('candidate', $candidate->status);
        $this->assertTrue($candidate->provisional, 'below-threshold build must be marked provisional');

        // Candidate sets never score client API requests.
        $this->postJson('/v2/score', [...$body, 'norms' => 'test-candidate'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'norm_set_unavailable');

        $report = $analytics->impactReport($candidate, limit: 10);
        $this->assertArrayHasKey('pct_changed', $report);
        $this->assertGreaterThan(0, $report['assessments_compared']);
        $stored = $candidate->refresh()->impact;
        $this->assertSame($report['baseline'], $stored['baseline']);
        $this->assertEquals($report['assessments_compared'], $stored['assessments_compared']);
    }

    /** Key-order-insensitive assertSame (golden JSON key order differs from the engine map's). */
    private function assertSameCanonical(array $expected, array $actual, string $message = ''): void
    {
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual, $message);
    }

    public function test_full_scoring_flow_reproduces_golden_results(): void
    {
        $session = $this->goldens->all()[0];
        $registration = $session->registration();
        $gender = strtoupper($registration['gender'] ?? 'M');

        ['token' => $token, 'attributes' => $attributes] = ApiKey::generate();
        $key = ApiKey::create([...$attributes, 'name' => 'golden-e2e']);
        $code = AccessCode::create([
            'code' => AccessCode::generateCode(),
            'type' => 'training',
            'product_code' => 'VC18',
            'allowed_scopes' => ['full'],
        ]);
        RoyaltyTerm::create([
            'access_code_id' => $code->id,
            'recipient' => 'content-owner',
            'kind' => 'flat_per_report',
            'amount' => '12.5000',
            'currency' => 'USD',
        ]);
        $this->withHeader('Authorization', "Bearer {$token}");

        // Create + submit all nine tools incrementally.
        $created = $this->postJson('/v2/assessments', [
            'firstname' => 'Golden',
            'lastname' => 'Session',
            'email' => 'golden@example.com',
            'language' => 'en',
            'gender' => $gender,
            'external_id' => "golden-{$session->sessionKey}",
        ])->assertStatus(201);
        $id = $created->json('assessment_id');

        foreach ($session->tools() as $tool => $responses) {
            $this->putJson("/v2/assessments/{$id}/tools/{$tool}", ['responses' => $responses])->assertOk();
        }

        $ready = $this->getJson("/v2/assessments/{$id}")
            ->assertOk()
            ->json('scopes_ready');
        $this->assertTrue($ready['mcs']);
        $this->assertTrue($ready['pro.role']);

        $envelope = $this->postJson("/v2/assessments/{$id}/score", [
            'scopes' => ['full'],
            'access_code' => $code->code,
            'audit' => true,
        ])->assertOk()->json();

        $expected = $session->expectedKeys();
        $this->assertSame($expected['mcs'], $envelope['scopes']['mcs'], 'mcs section must match the golden');
        $this->assertSameCanonical($expected['etc'], $envelope['scopes']['insights'], 'insights must match the golden etc section');
        foreach (['pro.person' => 'p', 'pro.role' => 'r', 'pro.org' => 'o'] as $scope => $dim) {
            foreach ($expected['pro'] as $area => $values) {
                $this->assertSame($values[$dim], $envelope['scopes'][$scope][$area], "{$scope}/{$area} must match");
            }
        }
        $reflections = $session->tools()['reflections'];
        $this->assertSame($reflections[1], $envelope['scopes']['reflections']['RoleAtWork_1']);
        $this->assertSame($reflections[7], $envelope['scopes']['reflections']['Best_Qual_1']);
        $this->assertSame($gender === 'F' ? 'female-legacy' : 'male-legacy', $envelope['norms']['set_id']);

        // Metering: one usage event, fees from the code's active terms.
        $event = UsageEvent::query()->latest('id')->first();
        $this->assertSame($code->id, $event->access_code_id);
        $this->assertSame('training', $event->code_type);
        $this->assertCount(1, $event->fees_due);
        $this->assertSame('content-owner', $event->fees_due[0]['recipient']);
        $this->assertSame(1, $code->refresh()->uses_count);

        // Re-render: strings format resolves content, same envelope shape.
        $strings = $this->getJson("/v2/assessments/{$id}/results?format=strings")
            ->assertOk()->json();
        $expectedStrings = $session->expectedStrings();
        $this->assertSame($expectedStrings['mcs'], $strings['scopes']['mcs']);
        $this->assertSameCanonical($expectedStrings['etc'], $strings['scopes']['insights']);

        // Audit trace (docs/03): rules in cursor order through all four
        // stages, intermediate values, resolved content keys.
        $audit = $this->getJson("/v2/assessments/{$id}/results/audit")
            ->assertOk()->json('audit');
        $this->assertSame('Tool', $audit['rules_fired'][0]['stage']);
        $stages = array_values(array_unique(array_column($audit['rules_fired'], 'stage')));
        $this->assertSame(['Tool', 'Package', 'Profile', 'Insight'], $stages);
        $this->assertNotEmpty($audit['stage_scale_values']['Profile']);
        $this->assertNotEmpty($audit['content_keys_resolved']);

        // Scope-sliced re-render straight from storage.
        $sliced = $this->getJson("/v2/assessments/{$id}/results?scope=pro.role")
            ->assertOk()
            ->assertJsonMissingPath('scopes.mcs')
            ->json('scopes');
        $this->assertSame($expected['pro']['societal_change']['r'], $sliced['pro.role']['societal_change']);
    }

    public function test_partial_scope_scores_with_only_its_tools(): void
    {
        $session = $this->goldens->all()[0];

        ['token' => $token, 'attributes' => $attributes] = ApiKey::generate();
        ApiKey::create([...$attributes, 'name' => 'partial-e2e']);
        $code = AccessCode::create([
            'code' => AccessCode::generateCode(),
            'type' => 'derivative',
            'product_code' => 'VC18',
            'allowed_scopes' => ['pro.role', 'pro.org'],
        ]);
        $this->withHeader('Authorization', "Bearer {$token}");

        // One-shot with just the role tool — no gender needed (non-gendered scope).
        $envelope = $this->postJson('/v2/score', [
            'registration' => [
                'firstname' => 'Role',
                'lastname' => 'Only',
                'email' => 'role@example.com',
                'language' => 'en',
            ],
            'tools' => ['role' => $session->tools()['role']],
            'scopes' => ['pro.role'],
            'access_code' => $code->code,
        ])->assertStatus(201)->json();

        $expected = $session->expectedKeys();
        foreach ($expected['pro'] as $area => $values) {
            $this->assertSame($values['r'], $envelope['scopes']['pro.role'][$area]);
        }
        $this->assertSame('none', $envelope['norms']['set_id']);

        // Derivative code: usage metered, no fees due (docs/07).
        $event = UsageEvent::query()->latest('id')->first();
        $this->assertSame('derivative', $event->code_type);
        $this->assertSame([], $event->fees_due);
    }
}
