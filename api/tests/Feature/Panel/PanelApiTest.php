<?php

namespace Tests\Feature\Panel;

use App\Models\AccessCode;
use App\Models\ApiKey;
use App\Models\Assessment;
use App\Models\NormSet;
use App\Models\RoyaltyTerm;
use App\Models\UsageEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Control-panel admin API (docs/08): session auth, viewer/admin role split,
 * and the read/write surfaces the React panel consumes. Engine-dependent
 * pieces (pipeline from live rule data) are covered in the goldens-gated
 * suite; everything here runs on the in-memory DB.
 */
class PanelApiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['name' => 'Arnold', 'email' => 'admin@example.com', 'password' => bcrypt('secret-123'), 'role' => 'admin']);
    }

    private function viewer(): User
    {
        return User::create(['name' => 'Viewer', 'email' => 'viewer@example.com', 'password' => bcrypt('secret-123'), 'role' => 'viewer']);
    }

    public function test_login_flow_and_me(): void
    {
        $this->admin();

        $this->postJson('/panel/api/login', ['email' => 'admin@example.com', 'password' => 'wrong'])
            ->assertStatus(422);

        $this->postJson('/panel/api/login', ['email' => 'admin@example.com', 'password' => 'secret-123'])
            ->assertOk()
            ->assertJsonPath('user.role', 'admin');

        $this->getJson('/panel/api/me')->assertOk()->assertJsonPath('user.email', 'admin@example.com');
    }

    public function test_panel_requires_login(): void
    {
        $this->getJson('/panel/api/stats')->assertStatus(401);
    }

    public function test_viewer_reads_but_cannot_mutate(): void
    {
        $this->actingAs($this->viewer());

        $this->getJson('/panel/api/stats')->assertOk();
        $this->getJson('/panel/api/keys')->assertOk();
        $this->postJson('/panel/api/keys', ['name' => 'nope'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden');
    }

    public function test_admin_key_lifecycle(): void
    {
        $this->actingAs($this->admin());

        $created = $this->postJson('/panel/api/keys', ['name' => 'partner-x', 'rate_limit_per_minute' => 120])
            ->assertStatus(201)->json();
        $this->assertStringStartsWith('sk_', $created['token']);

        $rotated = $this->postJson("/panel/api/keys/{$created['id']}/rotate")->assertOk()->json();
        $this->assertNotSame($created['token'], $rotated['token']);
        $this->assertNull(ApiKey::findByToken($created['token']), 'old token must stop working after rotate');
        $this->assertNotNull(ApiKey::findByToken($rotated['token']));

        $this->patchJson("/panel/api/keys/{$created['id']}", ['active' => false])->assertOk();
        $this->assertNull(ApiKey::findByToken($rotated['token']), 'revoked key must stop authenticating');
    }

    public function test_admin_code_lifecycle_with_terms(): void
    {
        $this->actingAs($this->admin());

        $codes = $this->postJson('/panel/api/codes', [
            'type' => 'training',
            'product_code' => 'VC18',
            'allowed_scopes' => ['full'],
            'count' => 3,
            'issued_to' => 'Partner X',
        ])->assertStatus(201)->json('codes');
        $this->assertCount(3, $codes);

        $code = AccessCode::query()->where('code', $codes[0])->first();
        $this->postJson("/panel/api/codes/{$code->id}/terms", [
            'recipient' => 'content-owner',
            'kind' => 'flat_per_report',
            'amount' => 10,
        ])->assertStatus(201);

        $listed = $this->getJson('/panel/api/codes')->assertOk()->json('codes');
        $target = collect($listed)->firstWhere('code', $codes[0]);
        $this->assertCount(1, $target['royalty_terms']);

        $termId = $target['royalty_terms'][0]['id'];
        $this->postJson("/panel/api/terms/{$termId}/end")->assertOk();
        $this->assertFalse(RoyaltyTerm::find($termId)->active);
    }

    public function test_royalty_statement_excludes_derivative_from_totals(): void
    {
        $this->actingAs($this->admin());

        $key = ApiKey::create([...ApiKey::generate()['attributes'], 'name' => 'k']);
        $assessment = Assessment::create(['api_key_id' => $key->id, 'firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.c', 'language' => 'en']);
        $training = AccessCode::create(['code' => 'ac_train', 'type' => 'training', 'product_code' => 'VC18', 'allowed_scopes' => ['full']]);
        $derivative = AccessCode::create(['code' => 'ac_deriv', 'type' => 'derivative', 'product_code' => 'VC18', 'allowed_scopes' => ['full']]);

        UsageEvent::create(['api_key_id' => $key->id, 'access_code_id' => $training->id, 'code_type' => 'training', 'product_code' => 'VC18', 'assessment_id' => $assessment->id, 'scopes' => ['mcs'], 'fees_due' => [['royalty_term_id' => 1, 'recipient' => 'owner', 'kind' => 'flat_per_report', 'amount' => '12.5', 'currency' => 'USD']], 'created_at' => now()]);
        UsageEvent::create(['api_key_id' => $key->id, 'access_code_id' => $derivative->id, 'code_type' => 'derivative', 'product_code' => 'VC18', 'assessment_id' => $assessment->id, 'scopes' => ['mcs'], 'fees_due' => [], 'created_at' => now()]);

        $statement = $this->getJson('/panel/api/codes/statement')->assertOk()->json();
        $this->assertSame(2, $statement['events']);
        $this->assertSame(1, $statement['derivative_events_excluded']);
        $this->assertEquals(12.5, $statement['totals_by_recipient']['owner']['USD']);

        $csv = $this->get('/panel/api/codes/statement.csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('ac_train', $csv);
        $this->assertStringContainsString('no fees', $csv);
    }

    public function test_norms_view_and_promotion_guard(): void
    {
        $this->actingAs($this->admin());

        $candidate = NormSet::create(['slug' => 'cand-1', 'status' => 'candidate', 'language' => 'en', 'gender' => 'M', 'provenance' => ['method' => 'test']]);

        $this->getJson('/panel/api/norms')->assertOk()
            ->assertJsonPath('sets.0.slug', 'cand-1');

        // Promotion policy: no impact report, no promotion.
        $this->postJson('/panel/api/norms/cand-1/promote')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'impact_required');

        $candidate->update(['impact' => ['pct_changed' => 1.2, 'baseline' => 'male-legacy']]);
        $this->postJson('/panel/api/norms/cand-1/promote')->assertOk();
        $this->assertSame('active', $candidate->refresh()->status);

        $this->postJson('/panel/api/norms/cand-1/retire')->assertOk();
        $this->assertSame('retired', $candidate->refresh()->status);
    }

    public function test_assessment_search(): void
    {
        $this->actingAs($this->viewer());
        $key = ApiKey::create([...ApiKey::generate()['attributes'], 'name' => 'k']);
        Assessment::create(['api_key_id' => $key->id, 'firstname' => 'Ada', 'lastname' => 'Lovelace', 'email' => 'ada@x.com', 'language' => 'en', 'external_id' => 'ord-42']);

        $this->getJson('/panel/api/assessments?q=ord-42')->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('assessments.0.external_id', 'ord-42');
        $this->getJson('/panel/api/assessments?q=nothing')->assertOk()->assertJsonPath('total', 0);
    }
}
