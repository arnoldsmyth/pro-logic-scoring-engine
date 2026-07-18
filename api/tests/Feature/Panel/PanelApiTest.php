<?php

namespace Tests\Feature\Panel;

use App\Models\AccessCode;
use App\Models\ApiKey;
use App\Models\Assessment;
use App\Models\Charge;
use App\Models\Client;
use App\Models\NormSet;
use App\Models\Payee;
use App\Models\PayoutTerm;
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

    public function test_admin_code_lifecycle_with_payout_schedule(): void
    {
        $this->actingAs($this->admin());

        $codes = $this->postJson('/panel/api/codes', [
            'name' => 'Partner X sales batch',
            'order_type' => 'sale',
            'charge_amount' => 100,
            'product_code' => 'VC18',
            'allowed_scopes' => ['full'],
            'count' => 3,
            'issued_to' => 'Partner X',
        ])->assertStatus(201)->json('codes');
        $this->assertCount(3, $codes);
        $codeStr = $codes[0];

        $owner = Payee::create(['name' => 'content-owner']);
        $us = Payee::create(['name' => 'us']);

        $termId = $this->postJson("/panel/api/codes/{$codeStr}/terms", [
            'payee_id' => $owner->id,
            'category' => 'royalty',
            'payout_type' => 'pro_d_royalty',
            'kind' => 'flat',
            'amount' => 10,
        ])->assertStatus(201)->json('id');

        // Exactly one active residual line allowed.
        $this->postJson("/panel/api/codes/{$codeStr}/terms", [
            'payee_id' => $us->id,
            'category' => 'residual',
            'payout_type' => 'residual_margin',
        ])->assertStatus(201);
        $this->postJson("/panel/api/codes/{$codeStr}/terms", [
            'payee_id' => $owner->id,
            'category' => 'residual',
        ])->assertStatus(422)->assertJsonPath('error.code', 'residual_exists');

        $detail = $this->getJson("/panel/api/codes/{$codeStr}")->assertOk()->json();
        $this->assertCount(2, $detail['payout_terms']);
        $this->assertFalse($detail['payout_terms'][0]['locked'], 'a line with no payouts yet must be editable');

        // Unused line: freely editable.
        $this->patchJson("/panel/api/terms/{$termId}", ['amount' => 15])
            ->assertOk()
            ->assertJsonPath('amount', '15');

        $this->postJson("/panel/api/terms/{$termId}/end")->assertOk();
        $this->assertFalse(PayoutTerm::find($termId)->active);
    }

    public function test_code_metadata_editable_but_scope_and_order_type_lock_after_first_use(): void
    {
        $this->actingAs($this->admin());
        $key = ApiKey::create([...ApiKey::generate()['attributes'], 'name' => 'k']);
        $code = AccessCode::create(['code' => 'ac_lock', 'name' => 'Lock test', 'order_type' => 'training', 'product_code' => 'VC18', 'allowed_scopes' => ['full']]);

        // Fresh, unused code: order_type/scopes editable.
        $this->getJson('/panel/api/codes/ac_lock')->assertOk()->assertJsonPath('scope_and_type_locked', false);
        $this->patchJson('/panel/api/codes/ac_lock', ['allowed_scopes' => ['pro.role']])->assertOk();

        // Simulate a scoring call having used it.
        $assessment = Assessment::create(['api_key_id' => $key->id, 'firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.c', 'language' => 'en']);
        UsageEvent::create(['api_key_id' => $key->id, 'access_code_id' => $code->id, 'code_type' => 'training', 'product_code' => 'VC18', 'assessment_id' => $assessment->id, 'scopes' => ['pro.role'], 'fees_due' => [], 'created_at' => now()]);
        $code->increment('uses_count');

        $this->getJson('/panel/api/codes/ac_lock')->assertOk()->assertJsonPath('scope_and_type_locked', true);
        $this->patchJson('/panel/api/codes/ac_lock', ['order_type' => 'sale'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'locked');

        // Metadata fields remain editable regardless.
        $this->patchJson('/panel/api/codes/ac_lock', ['name' => 'Renamed', 'notes' => 'note'])
            ->assertOk()
            ->assertJsonPath('name', 'Renamed');
    }

    public function test_payout_line_locks_once_it_has_accrued(): void
    {
        $this->actingAs($this->admin());
        $key = ApiKey::create([...ApiKey::generate()['attributes'], 'name' => 'k']);
        $code = AccessCode::create(['code' => 'ac_termlock', 'name' => 'Line lock test', 'order_type' => 'sale', 'charge_amount' => 50, 'product_code' => 'VC18', 'allowed_scopes' => ['full']]);
        $owner = Payee::create(['name' => 'owner']);
        $term = $code->payoutTerms()->create(['payee_id' => $owner->id, 'category' => 'royalty', 'payout_type' => 'pro_d_royalty', 'kind' => 'flat', 'amount' => 10, 'currency' => 'USD']);

        // Simulate a real charge having split into this line.
        $assessment = Assessment::create(['api_key_id' => $key->id, 'firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.c', 'language' => 'en']);
        $event = UsageEvent::create(['api_key_id' => $key->id, 'access_code_id' => $code->id, 'code_type' => 'sale', 'product_code' => 'VC18', 'assessment_id' => $assessment->id, 'scopes' => ['mcs'], 'fees_due' => [], 'created_at' => now()]);
        $code->recordCharge($event, $assessment, 'en');

        $this->getJson('/panel/api/codes/ac_termlock')->assertOk()->assertJsonPath('payout_terms.0.locked', true);
        $this->patchJson("/panel/api/terms/{$term->id}", ['amount' => 999])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'locked');

        // End-and-replace is still the correct path forward.
        $this->postJson("/panel/api/terms/{$term->id}/end")->assertOk();
        $newTermId = $this->postJson('/panel/api/codes/ac_termlock/terms', ['payee_id' => $owner->id, 'category' => 'royalty', 'kind' => 'flat', 'amount' => 999])
            ->assertStatus(201)->json('id');
        $this->assertNotSame($term->id, $newTermId);
    }

    public function test_charge_ledger_first_real_then_zero_repeats_per_order(): void
    {
        $key = ApiKey::create([...ApiKey::generate()['attributes'], 'name' => 'k']);
        $sale = AccessCode::create(['code' => 'ac_sale', 'name' => 'Sale code', 'order_type' => 'sale', 'charge_amount' => 100, 'product_code' => 'VC18', 'allowed_scopes' => ['full']]);
        [$owner, $translator, $us] = [Payee::create(['name' => 'owner']), Payee::create(['name' => 'fr-translator']), Payee::create(['name' => 'us'])];
        $sale->payoutTerms()->create(['payee_id' => $owner->id, 'category' => 'royalty', 'payout_type' => 'pro_d_royalty', 'kind' => 'percent_of_charge', 'amount' => 20, 'currency' => 'USD']);
        $sale->payoutTerms()->create(['payee_id' => $translator->id, 'category' => 'fee', 'payout_type' => 'language_fee', 'kind' => 'flat', 'amount' => 5, 'currency' => 'USD', 'language' => 'fr']);
        $sale->payoutTerms()->create(['payee_id' => $us->id, 'category' => 'residual', 'payout_type' => 'residual_margin', 'kind' => 'flat', 'amount' => 0, 'currency' => 'USD']);

        $mkEvent = function ($assessment) use ($key, $sale) {
            return UsageEvent::create(['api_key_id' => $key->id, 'access_code_id' => $sale->id, 'code_type' => 'sale', 'product_code' => 'VC18', 'assessment_id' => $assessment->id, 'scopes' => ['mcs'], 'fees_due' => [], 'created_at' => now()]);
        };

        // First usage for order-1 (en): real charge, royalty 20% + residual 80.
        $a1 = Assessment::create(['api_key_id' => $key->id, 'firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.c', 'language' => 'en', 'external_id' => 'order-1']);
        $charge1 = $sale->recordCharge($mkEvent($a1), $a1, 'en');
        $this->assertEquals(100, (float) $charge1->amount);
        $payouts = $charge1->payouts()->orderBy('id')->get();
        $this->assertCount(2, $payouts, 'fr language fee must not fire on an en event');
        $this->assertEquals(20, (float) $payouts[0]->amount); // 20% of 100
        $this->assertSame('residual', $payouts[1]->category);
        $this->assertEquals(80, (float) $payouts[1]->amount, 'residual absorbs the balance');
        $this->assertSame('accrued', $payouts[1]->status);

        // Same order rescored (update): $0 repeat referencing the original.
        $a2 = Assessment::create(['api_key_id' => $key->id, 'firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.c', 'language' => 'en', 'external_id' => 'order-1']);
        $charge2 = $sale->recordCharge($mkEvent($a2), $a2, 'en');
        $this->assertEquals(0, (float) $charge2->amount, 'repeat usage of the same order never charges again');
        $this->assertSame($charge1->id, $charge2->original_charge_id);
        $this->assertCount(0, $charge2->payouts()->get());

        // A different order charges again — and in fr the language fee fires.
        $a3 = Assessment::create(['api_key_id' => $key->id, 'firstname' => 'C', 'lastname' => 'D', 'email' => 'c@d.e', 'language' => 'fr', 'external_id' => 'order-2']);
        $charge3 = $sale->recordCharge($mkEvent($a3), $a3, 'fr');
        $this->assertEquals(100, (float) $charge3->amount);
        $fr = $charge3->payouts()->get()->keyBy('recipient');
        $this->assertEquals(5, (float) $fr['fr-translator']->amount);
        $this->assertEquals(75, (float) $fr['us']->amount, 'residual shrinks when the language fee fires: 100 - 20 - 5');
    }

    public function test_statement_reports_ledger_and_infers_conversion(): void
    {
        $this->actingAs($this->admin());
        $key = ApiKey::create([...ApiKey::generate()['attributes'], 'name' => 'k']);
        $lead = AccessCode::create(['code' => 'ac_lead', 'name' => 'Lead taster', 'order_type' => 'lead', 'charge_amount' => 0, 'product_code' => 'VC18', 'allowed_scopes' => ['mcs.m']]);
        $sale = AccessCode::create(['code' => 'ac_sale2', 'name' => 'Full product sale', 'order_type' => 'sale', 'charge_amount' => 100, 'product_code' => 'VC18', 'allowed_scopes' => ['full']]);
        $sale->payoutTerms()->create(['payee_id' => Payee::create(['name' => 'owner'])->id, 'category' => 'royalty', 'payout_type' => 'pro_d_royalty', 'kind' => 'flat', 'amount' => 30, 'currency' => 'USD']);
        $sale->payoutTerms()->create(['payee_id' => Payee::create(['name' => 'us'])->id, 'category' => 'residual', 'payout_type' => 'residual_margin', 'kind' => 'flat', 'amount' => 0, 'currency' => 'USD']);

        $mkEvent = function ($assessment, $code) use ($key) {
            return UsageEvent::create(['api_key_id' => $key->id, 'access_code_id' => $code->id, 'code_type' => $code->order_type, 'product_code' => 'VC18', 'assessment_id' => $assessment->id, 'scopes' => ['mcs'], 'fees_due' => [], 'created_at' => now()]);
        };

        // order-10: lead, later converts to sale. order-11: unconverted lead.
        $l1 = Assessment::create(['api_key_id' => $key->id, 'firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.c', 'language' => 'en', 'external_id' => 'order-10']);
        $lead->recordCharge($mkEvent($l1, $lead), $l1, 'en');
        $s1 = Assessment::create(['api_key_id' => $key->id, 'firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.c', 'language' => 'en', 'external_id' => 'order-10']);
        $sale->recordCharge($mkEvent($s1, $sale), $s1, 'en');
        $l2 = Assessment::create(['api_key_id' => $key->id, 'firstname' => 'E', 'lastname' => 'F', 'email' => 'e@f.g', 'language' => 'en', 'external_id' => 'order-11']);
        $lead->recordCharge($mkEvent($l2, $lead), $l2, 'en');

        $statement = $this->getJson('/panel/api/codes/statement')->assertOk()->json();
        $this->assertSame(3, $statement['charges']);
        $this->assertSame(2, $statement['by_order_type']['lead']['usages']);
        $this->assertEquals(0, $statement['by_order_type']['lead']['charged']['USD']);
        $this->assertEquals(100, $statement['by_order_type']['sale']['charged']['USD']);
        $this->assertEquals(30, $statement['payouts_by_recipient']['owner']['USD']);
        $this->assertEquals(70, $statement['payouts_by_recipient']['us']['USD']);
        $this->assertEquals(100, $statement['payouts_by_code']['Full product sale']['USD']);
        $this->assertSame(2, $statement['conversion']['leads']);
        $this->assertSame(1, $statement['conversion']['converted']);
        $this->assertEquals(50.0, $statement['conversion']['rate']);

        $csv = $this->get('/panel/api/codes/statement.csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('Full product sale', $csv);
        $this->assertStringContainsString('residual_margin', $csv);
        $this->assertStringContainsString('order-11', $csv);
    }

    /**
     * Royalty Statement report: reads the charges + payouts ledgers,
     * per-currency (never blended), across two payees and two currencies,
     * including a $0 repeat (no payouts) and a negative residual, plus a
     * paid line so net_owed differs from accrued.
     */
    public function test_royalty_report_totals_grouping_and_filters(): void
    {
        $key = ApiKey::create([...ApiKey::generate()['attributes'], 'name' => 'k']);
        $acme = Payee::create(['name' => 'Acme Content']);
        $globe = Payee::create(['name' => 'Globe Media']);
        $client = Client::create(['name' => 'Acme Corp']);

        // Code A: USD sale, acme royalty 40 + globe residual 60.
        $codeA = AccessCode::create(['code' => 'ac_A', 'name' => 'USD sale', 'order_type' => 'sale', 'charge_amount' => 100, 'charge_currency' => 'USD', 'product_code' => 'VC18', 'allowed_scopes' => ['full'], 'client_id' => $client->id]);
        $codeA->payoutTerms()->create(['payee_id' => $acme->id, 'category' => 'royalty', 'payout_type' => 'pro_d_royalty', 'kind' => 'flat', 'amount' => 40, 'currency' => 'USD']);
        $codeA->payoutTerms()->create(['payee_id' => $globe->id, 'category' => 'residual', 'payout_type' => 'residual_margin', 'kind' => 'flat', 'amount' => 0, 'currency' => 'USD']);

        // Code B: EUR sale, globe royalty 40 > charge 30 -> acme residual -10.
        $codeB = AccessCode::create(['code' => 'ac_B', 'name' => 'EUR sale', 'order_type' => 'sale', 'charge_amount' => 30, 'charge_currency' => 'EUR', 'product_code' => 'VC18', 'allowed_scopes' => ['full']]);
        $codeB->payoutTerms()->create(['payee_id' => $globe->id, 'category' => 'royalty', 'payout_type' => 'pro_d_royalty', 'kind' => 'flat', 'amount' => 40, 'currency' => 'EUR']);
        $codeB->payoutTerms()->create(['payee_id' => $acme->id, 'category' => 'residual', 'payout_type' => 'residual_margin', 'kind' => 'flat', 'amount' => 0, 'currency' => 'EUR']);

        $charge = function (AccessCode $code, string $order, string $lang) use ($key) {
            $a = Assessment::create(['api_key_id' => $key->id, 'firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.c', 'language' => $lang, 'external_id' => $order]);
            $e = UsageEvent::create(['api_key_id' => $key->id, 'access_code_id' => $code->id, 'code_type' => $code->order_type, 'product_code' => 'VC18', 'assessment_id' => $a->id, 'scopes' => ['mcs'], 'fees_due' => [], 'created_at' => now()]);

            return $code->recordCharge($e, $a, $lang);
        };

        $a1 = $charge($codeA, 'order-A', 'en');   // real: acme 40 USD, globe 60 USD
        $charge($codeA, 'order-A', 'en');         // $0 repeat, no payouts
        $charge($codeB, 'order-B', 'en');         // real: globe 40 EUR, acme -10 EUR

        // Backdated charge (outside the default window) to prove from/to.
        $old = $charge($codeA, 'order-OLD', 'en');
        Charge::whereKey($old->id)->update(['created_at' => now()->subMonths(3)]);

        // Mark globe's USD residual on A1 as paid so net_owed != accrued.
        $a1->payouts()->where('payee_id', $globe->id)->update(['status' => 'paid']);

        $this->actingAs($this->viewer()); // viewer (non-admin) CAN read reports

        $json = $this->getJson('/panel/api/reports/royalties')->assertOk()->json();

        // Window excludes the backdated charge: 3 charges, 1 repeat.
        $this->assertSame(3, $json['totals']['charges']);
        $this->assertSame(1, $json['totals']['repeat_charges']);

        // Per-currency totals, never blended.
        $this->assertEquals(40, $json['totals']['accrued']['USD']); // acme royalty (globe's 60 is paid)
        $this->assertEquals(30, $json['totals']['accrued']['EUR']); // globe 40 + acme -10
        $this->assertEquals(60, $json['totals']['paid']['USD']);
        $this->assertEquals(-20, $json['totals']['net_owed']['USD']); // 40 - 60
        $this->assertEquals(30, $json['totals']['net_owed']['EUR']);
        $this->assertSame('payee', $json['group_by']);

        // group_by=payee: per-payee accrued per currency + net_owed.
        $byKey = collect($json['groups'])->keyBy('key');
        $acmeG = $byKey["payee:{$acme->id}"];
        $this->assertEquals(40, $acmeG['totals']['accrued']['USD']);
        $this->assertEquals(-10, $acmeG['totals']['accrued']['EUR']); // negative residual passes through
        $globeG = $byKey["payee:{$globe->id}"];
        $this->assertEquals(40, $globeG['totals']['accrued']['EUR']);
        $this->assertEquals(60, $globeG['totals']['paid']['USD']);
        $this->assertEquals(-60, $globeG['totals']['net_owed']['USD']);
        $this->assertSame('Acme Content', $acmeG['label']);

        // payee_id filter narrows to one payee.
        $onlyAcme = $this->getJson("/panel/api/reports/royalties?payee_id={$acme->id}")->assertOk()->json();
        $this->assertCount(1, $onlyAcme['groups']);
        $this->assertSame("payee:{$acme->id}", $onlyAcme['groups'][0]['key']);

        // status filter: only accrued lines feed money totals.
        $accruedOnly = $this->getJson('/panel/api/reports/royalties?status=accrued')->assertOk()->json();
        $this->assertArrayNotHasKey('USD', $accruedOnly['totals']['paid']);
        $this->assertEquals(40, $accruedOnly['totals']['accrued']['USD']);
        $this->assertSame(3, $accruedOnly['totals']['charges'], 'charge count ignores the status filter');

        // from/to widens to include the backdated charge.
        $wide = $this->getJson('/panel/api/reports/royalties?from='.now()->subMonths(6)->toDateString())->assertOk()->json();
        $this->assertSame(4, $wide['totals']['charges']);

        // group_by=client resolves via accessCode.client, fallback "(no client)".
        $byClient = $this->getJson('/panel/api/reports/royalties?group_by=client')->assertOk()->json();
        $labels = collect($byClient['groups'])->pluck('label');
        $this->assertTrue($labels->contains('Acme Corp'));
        $this->assertTrue($labels->contains('(no client)'));
    }

    public function test_royalty_report_csv(): void
    {
        $key = ApiKey::create([...ApiKey::generate()['attributes'], 'name' => 'k']);
        $acme = Payee::create(['name' => 'Acme Content']);
        $us = Payee::create(['name' => 'Us']);
        $code = AccessCode::create(['code' => 'ac_csv', 'name' => 'CSV sale', 'order_type' => 'sale', 'charge_amount' => 100, 'charge_currency' => 'USD', 'product_code' => 'VC18', 'allowed_scopes' => ['full']]);
        $code->payoutTerms()->create(['payee_id' => $acme->id, 'category' => 'royalty', 'payout_type' => 'pro_d_royalty', 'kind' => 'flat', 'amount' => 30, 'currency' => 'USD']);
        $code->payoutTerms()->create(['payee_id' => $us->id, 'category' => 'residual', 'payout_type' => 'residual_margin', 'kind' => 'flat', 'amount' => 0, 'currency' => 'USD']);

        $a = Assessment::create(['api_key_id' => $key->id, 'firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.c', 'language' => 'en', 'external_id' => 'order-1']);
        $e = UsageEvent::create(['api_key_id' => $key->id, 'access_code_id' => $code->id, 'code_type' => 'sale', 'product_code' => 'VC18', 'assessment_id' => $a->id, 'scopes' => ['mcs'], 'fees_due' => [], 'created_at' => now()]);
        $code->recordCharge($e, $a, 'en'); // real charge -> 2 payout lines
        // A $0 repeat (no payouts) must NOT produce a CSV row.
        $a2 = Assessment::create(['api_key_id' => $key->id, 'firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.c', 'language' => 'en', 'external_id' => 'order-1']);
        $e2 = UsageEvent::create(['api_key_id' => $key->id, 'access_code_id' => $code->id, 'code_type' => 'sale', 'product_code' => 'VC18', 'assessment_id' => $a2->id, 'scopes' => ['mcs'], 'fees_due' => [], 'created_at' => now()]);
        $code->recordCharge($e2, $a2, 'en');

        $this->actingAs($this->viewer());
        $res = $this->get('/panel/api/reports/royalties.csv')->assertOk();
        $this->assertStringContainsString('royalty-statement.csv', $res->headers->get('content-disposition'));

        $rows = array_values(array_filter(explode("\n", trim($res->streamedContent()))));
        $this->assertSame('payout_id,payee_id,recipient,category,payout_type,amount,currency,language,status,charge_id,original_charge_id,product_code,external_order_id,order_type,charge_amount,charge_date', $rows[0]);
        $this->assertCount(3, $rows, 'header + one row per payout line (2), no row for the $0 repeat');
    }

    public function test_clients_and_payees_crud(): void
    {
        $this->actingAs($this->admin());

        $clientId = $this->postJson('/panel/api/clients', ['name' => 'Acme Corp', 'billing_email' => 'ap@acme.com'])
            ->assertStatus(201)->json('id');
        // Duplicate names refused.
        $this->postJson('/panel/api/clients', ['name' => 'Acme Corp'])->assertStatus(422);

        // A payee optionally linked to the same-party client record.
        $payeeId = $this->postJson('/panel/api/payees', ['name' => 'Acme Royalties', 'client_id' => $clientId])
            ->assertStatus(201)->json('id');

        $this->patchJson("/panel/api/clients/{$clientId}", ['active' => false])->assertOk();
        $this->patchJson("/panel/api/payees/{$payeeId}", ['name' => 'Acme Royalties Ltd'])->assertOk();

        $clients = $this->getJson('/panel/api/clients')->assertOk()->json('clients');
        $this->assertFalse(collect($clients)->firstWhere('id', $clientId)['active']);
        $payees = $this->getJson('/panel/api/payees')->assertOk()->json('payees');
        $found = collect($payees)->firstWhere('id', $payeeId);
        $this->assertSame('Acme Royalties Ltd', $found['name']);
        $this->assertSame('Acme Corp', $found['client']);

        // Keys and codes attach to clients.
        $keyId = $this->postJson('/panel/api/keys', ['name' => 'acme-live', 'client_id' => $clientId])->assertStatus(201)->json('id');
        $keys = $this->getJson('/panel/api/keys')->assertOk()->json('keys');
        $this->assertSame('Acme Corp', collect($keys)->firstWhere('id', $keyId)['client']);

        $codes = $this->postJson('/panel/api/codes', [
            'name' => 'Acme sale', 'order_type' => 'sale', 'charge_amount' => 50,
            'product_code' => 'VC18', 'allowed_scopes' => ['full'], 'client_id' => $clientId,
        ])->assertStatus(201)->json('codes');
        $this->getJson("/panel/api/codes/{$codes[0]}")->assertOk()->assertJsonPath('client', 'Acme Corp');

        // Viewers read, cannot mutate.
        $this->actingAs($this->viewer());
        $this->getJson('/panel/api/payees')->assertOk();
        $this->postJson('/panel/api/clients', ['name' => 'Nope Inc'])->assertStatus(403);
    }

    public function test_person_timeline_links_same_person_assessments(): void
    {
        $this->actingAs($this->viewer());
        $key = ApiKey::create([...ApiKey::generate()['attributes'], 'name' => 'k']);
        $a1 = Assessment::create(['api_key_id' => $key->id, 'firstname' => 'Ada', 'lastname' => 'L', 'email' => 'ada@x.com', 'language' => 'en', 'external_id' => 'p-1']);
        $a2 = Assessment::create(['api_key_id' => $key->id, 'firstname' => 'Ada', 'lastname' => 'L', 'email' => 'ada@x.com', 'language' => 'en', 'external_id' => 'p-1']);
        Assessment::create(['api_key_id' => $key->id, 'firstname' => 'Bob', 'lastname' => 'M', 'email' => 'bob@x.com', 'language' => 'en', 'external_id' => 'p-2']);

        $timeline = $this->getJson("/panel/api/assessments/{$a1->public_id}/person-timeline")->assertOk()->json();
        $this->assertSame('external_id', $timeline['identity']['matched_by']);
        $this->assertCount(2, $timeline['takes']);
        $this->assertSame([$a1->public_id, $a2->public_id], array_column($timeline['takes'], 'public_id'));
        $this->assertTrue($timeline['takes'][0]['is_current']);
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
