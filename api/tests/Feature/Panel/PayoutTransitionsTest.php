<?php

namespace Tests\Feature\Panel;

use App\Models\AccessCode;
use App\Models\ApiKey;
use App\Models\Assessment;
use App\Models\Charge;
use App\Models\Payee;
use App\Models\Payout;
use App\Models\UsageEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Payout status transitions + payout-aging report (prolog-9x0). Transitions
 * are one-way and write-once: accrued -> paid or accrued -> void, never
 * re-transitioned or edited. Money stays per-currency throughout.
 */
class PayoutTransitionsTest extends TestCase
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

    /** Minimal but FK-valid charge to hang payouts off of. */
    private function makeCharge(): Charge
    {
        $key = ApiKey::create([...ApiKey::generate()['attributes'], 'name' => 'k']);
        $code = AccessCode::create(['code' => 'ac_'.uniqid(), 'name' => 'test code', 'order_type' => 'sale', 'charge_amount' => 100, 'product_code' => 'VC18', 'allowed_scopes' => ['full']]);
        $assessment = Assessment::create(['api_key_id' => $key->id, 'firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.c', 'language' => 'en']);
        $event = UsageEvent::create(['api_key_id' => $key->id, 'access_code_id' => $code->id, 'code_type' => 'sale', 'product_code' => 'VC18', 'assessment_id' => $assessment->id, 'scopes' => ['full'], 'fees_due' => [], 'created_at' => now()]);

        return Charge::create(['usage_event_id' => $event->id, 'access_code_id' => $code->id, 'api_key_id' => $key->id, 'assessment_id' => $assessment->id, 'order_type' => 'sale', 'product_code' => 'VC18', 'amount' => 100, 'currency' => 'USD', 'created_at' => now()]);
    }

    private function makePayout(Charge $charge, Payee $payee, array $overrides = []): Payout
    {
        return Payout::create(array_merge([
            'charge_id' => $charge->id,
            'recipient' => $payee->name,
            'payee_id' => $payee->id,
            'category' => 'royalty',
            'payout_type' => 'pro_d_royalty',
            'amount' => 10,
            'currency' => 'USD',
            'status' => 'accrued',
            'created_at' => now(),
        ], $overrides));
    }

    public function test_pay_happy_path_sets_transition_fields(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $payee = Payee::create(['name' => 'owner']);
        $payout = $this->makePayout($this->makeCharge(), $payee);

        $this->postJson("/panel/api/payouts/{$payout->id}/pay")
            ->assertOk()
            ->assertJsonPath('payout.id', $payout->id)
            ->assertJsonPath('payout.status', 'paid');

        $payout->refresh();
        $this->assertSame('paid', $payout->status);
        $this->assertNotNull($payout->paid_at);
        $this->assertSame($admin->id, $payout->transitioned_by);
        $this->assertNull($payout->voided_at);
    }

    public function test_pay_on_already_paid_payout_is_rejected(): void
    {
        $this->actingAs($this->admin());
        $payee = Payee::create(['name' => 'owner']);
        $payout = $this->makePayout($this->makeCharge(), $payee, ['status' => 'paid', 'paid_at' => now()]);

        $this->postJson("/panel/api/payouts/{$payout->id}/pay")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only accrued payouts can be marked paid.');
    }

    public function test_void_requires_reason_and_sets_transition_fields(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $payee = Payee::create(['name' => 'owner']);
        $payout = $this->makePayout($this->makeCharge(), $payee);

        $this->postJson("/panel/api/payouts/{$payout->id}/void")
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->postJson("/panel/api/payouts/{$payout->id}/void", ['reason' => 'duplicate line item'])
            ->assertOk()
            ->assertJsonPath('payout.status', 'void')
            ->assertJsonPath('payout.void_reason', 'duplicate line item');

        $payout->refresh();
        $this->assertSame('void', $payout->status);
        $this->assertNotNull($payout->voided_at);
        $this->assertSame('duplicate line item', $payout->void_reason);
        $this->assertSame($admin->id, $payout->transitioned_by);
    }

    public function test_void_on_paid_payout_is_rejected(): void
    {
        $this->actingAs($this->admin());
        $payee = Payee::create(['name' => 'owner']);
        $payout = $this->makePayout($this->makeCharge(), $payee, ['status' => 'paid', 'paid_at' => now()]);

        $this->postJson("/panel/api/payouts/{$payout->id}/void", ['reason' => 'oops'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only accrued payouts can be voided.');
    }

    public function test_viewer_gets_403_on_all_mutations(): void
    {
        $this->actingAs($this->viewer());
        $payee = Payee::create(['name' => 'owner']);
        $payout = $this->makePayout($this->makeCharge(), $payee);

        $this->postJson("/panel/api/payouts/{$payout->id}/pay")->assertStatus(403);
        $this->postJson("/panel/api/payouts/{$payout->id}/void", ['reason' => 'x'])->assertStatus(403);
        $this->postJson('/panel/api/payouts/settle', ['payee_id' => $payee->id, 'from' => '2026-01-01', 'to' => '2026-01-31'])->assertStatus(403);
    }

    public function test_settle_only_touches_the_given_payees_accrued_payouts_in_window(): void
    {
        $this->actingAs($this->admin());
        $a = Payee::create(['name' => 'payee-a']);
        $b = Payee::create(['name' => 'payee-b']);
        $charge = $this->makeCharge();

        // Payee A: two currencies inside the window.
        $inUsd = $this->makePayout($charge, $a, ['amount' => 30, 'currency' => 'USD', 'created_at' => '2026-07-10']);
        $inEur = $this->makePayout($charge, $a, ['amount' => 20, 'currency' => 'EUR', 'created_at' => '2026-07-11']);
        // Payee A: outside the window — must be untouched.
        $outOfWindow = $this->makePayout($charge, $a, ['amount' => 99, 'currency' => 'USD', 'created_at' => '2026-05-01']);
        // Payee A: already paid inside the window — must be untouched (not accrued).
        $alreadyPaid = $this->makePayout($charge, $a, ['amount' => 5, 'currency' => 'USD', 'status' => 'paid', 'paid_at' => now(), 'created_at' => '2026-07-10']);
        // Payee B: inside the window — must be untouched (different payee).
        $otherPayee = $this->makePayout($charge, $b, ['amount' => 77, 'currency' => 'USD', 'created_at' => '2026-07-10']);

        $response = $this->postJson('/panel/api/payouts/settle', [
            'payee_id' => $a->id,
            'from' => '2026-07-01',
            'to' => '2026-07-15',
        ])->assertOk()->json();

        $this->assertSame(2, $response['settled']);
        $this->assertEquals(30, $response['totals']['USD']);
        $this->assertEquals(20, $response['totals']['EUR']);

        $this->assertSame('paid', $inUsd->refresh()->status);
        $this->assertSame('paid', $inEur->refresh()->status);
        $this->assertNotNull($inUsd->transitioned_by);

        $this->assertSame('accrued', $outOfWindow->refresh()->status, 'outside the window must stay untouched');
        $this->assertSame('paid', $alreadyPaid->refresh()->status, 'was already paid, must stay untouched by settle');
        $this->assertSame('accrued', $otherPayee->refresh()->status, "another payee's accrued payout must stay untouched");
    }

    public function test_settle_with_zero_matching_payouts_is_a_no_op(): void
    {
        $this->actingAs($this->admin());
        $payee = Payee::create(['name' => 'empty-payee']);

        $response = $this->postJson('/panel/api/payouts/settle', [
            'payee_id' => $payee->id,
            'from' => '2026-01-01',
            'to' => '2026-01-31',
        ])->assertOk()->json();

        $this->assertSame(['settled' => 0, 'totals' => []], $response);
    }

    public function test_settle_validates_payee_and_dates(): void
    {
        $this->actingAs($this->admin());

        $this->postJson('/panel/api/payouts/settle', ['payee_id' => 999999, 'from' => '2026-01-01', 'to' => '2026-01-31'])
            ->assertStatus(422);
        $this->postJson('/panel/api/payouts/settle', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payee_id', 'from', 'to']);
    }

    public function test_aging_report_shape_and_bucket_math(): void
    {
        $this->actingAs($this->viewer()); // read-only report: any logged-in role
        $payee = Payee::create(['name' => 'aging-payee']);
        $charge = $this->makeCharge();

        // Accrued payouts at 5, 45, and 100 days old -> d0_30 / d31_60 / d90_plus.
        $this->makePayout($charge, $payee, ['amount' => 10, 'currency' => 'USD', 'created_at' => now()->subDays(5)]);
        $this->makePayout($charge, $payee, ['amount' => 20, 'currency' => 'USD', 'created_at' => now()->subDays(45)]);
        $this->makePayout($charge, $payee, ['amount' => 30, 'currency' => 'USD', 'created_at' => now()->subDays(100)]);
        // All-time paid + void lines feed the top-level sums but never the aging buckets.
        $this->makePayout($charge, $payee, ['amount' => 15, 'currency' => 'USD', 'status' => 'paid', 'paid_at' => now(), 'created_at' => now()->subDays(200)]);
        $this->makePayout($charge, $payee, ['amount' => 8, 'currency' => 'USD', 'status' => 'void', 'voided_at' => now(), 'void_reason' => 'test', 'created_at' => now()->subDays(3)]);
        // A different currency for the same payee: its own row.
        $this->makePayout($charge, $payee, ['amount' => 6, 'currency' => 'EUR', 'created_at' => now()->subDays(1)]);

        $json = $this->getJson('/panel/api/reports/payout-aging')->assertOk()->json();

        $this->assertSame(now()->toDateString(), $json['as_of']);

        $rows = collect($json['rows'])->keyBy('currency');
        $usd = $rows['USD'];
        $this->assertSame($payee->id, $usd['payee_id']);
        $this->assertSame('aging-payee', $usd['recipient']);
        $this->assertEquals(60, $usd['accrued']); // 10 + 20 + 30
        $this->assertEquals(15, $usd['paid']);
        $this->assertEquals(8, $usd['void']);
        $this->assertEquals(10, $usd['aging']['d0_30']);
        $this->assertEquals(20, $usd['aging']['d31_60']);
        $this->assertEquals(0, $usd['aging']['d61_90']);
        $this->assertEquals(30, $usd['aging']['d90_plus']);

        $eur = $rows['EUR'];
        $this->assertEquals(6, $eur['accrued']);
        $this->assertEquals(6, $eur['aging']['d0_30']);

        // Sorted by recipient then currency: EUR before USD for the same payee.
        $this->assertSame(['EUR', 'USD'], collect($json['rows'])->pluck('currency')->all());
    }

    public function test_aging_report_omits_payees_with_no_activity(): void
    {
        $this->actingAs($this->viewer());
        Payee::create(['name' => 'never-paid-anyone']); // no payouts at all

        $json = $this->getJson('/panel/api/reports/payout-aging')->assertOk()->json();

        $this->assertSame([], $json['rows']);
    }
}
