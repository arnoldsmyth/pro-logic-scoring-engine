<?php

namespace Tests\Feature\Panel;

use App\Models\ApiKey;
use App\Models\Assessment;
use App\Models\Client;
use App\Models\ScoredResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Assessment-volume report (prolog-cic): created/scored counts over a
 * day-by-day series plus slice breakdowns by language/gender/client/scope.
 * Fixture window is always 2026-07-01..2026-07-04 (4 days, the last one
 * deliberately empty to prove the series zero-fills) with out-of-window
 * rows on both sides to prove the date filter actually excludes them.
 */
class VolumeReportTest extends TestCase
{
    use RefreshDatabase;

    private function viewer(): User
    {
        return User::query()->firstOrCreate(
            ['email' => 'viewer@example.com'],
            ['name' => 'Viewer', 'password' => bcrypt('secret-123'), 'role' => 'viewer']
        );
    }

    /**
     * @return array{0: ApiKey, 1: ApiKey, 2: Client, 3: Assessment, 4: Assessment, 5: Assessment}
     */
    private function seedVolumeData(): array
    {
        $client = Client::create(['name' => 'Client A']);
        $keyWithClient = ApiKey::create([...ApiKey::generate()['attributes'], 'name' => 'k1', 'client_id' => $client->id]);
        $keyNoClient = ApiKey::create([...ApiKey::generate()['attributes'], 'name' => 'k2']);

        $a1 = Assessment::create([
            'api_key_id' => $keyWithClient->id, 'firstname' => 'A', 'lastname' => 'One', 'email' => 'a1@x.com',
            'language' => 'en', 'gender' => 'M', 'created_at' => '2026-07-01 10:00:00',
        ]);
        $a2 = Assessment::create([
            'api_key_id' => $keyWithClient->id, 'firstname' => 'A', 'lastname' => 'Two', 'email' => 'a2@x.com',
            'language' => 'en', 'created_at' => '2026-07-02 10:00:00',
        ]);
        $a3 = Assessment::create([
            'api_key_id' => $keyNoClient->id, 'firstname' => 'A', 'lastname' => 'Three', 'email' => 'a3@x.com',
            'language' => 'fr', 'gender' => 'F', 'created_at' => '2026-07-03 10:00:00',
        ]);

        // Outside the [07-01, 07-04] window on both sides.
        Assessment::create([
            'api_key_id' => $keyWithClient->id, 'firstname' => 'A', 'lastname' => 'Before', 'email' => 'before@x.com',
            'language' => 'en', 'created_at' => '2026-06-30 10:00:00',
        ]);
        Assessment::create([
            'api_key_id' => $keyWithClient->id, 'firstname' => 'A', 'lastname' => 'After', 'email' => 'after@x.com',
            'language' => 'en', 'created_at' => '2026-07-05 10:00:00',
        ]);

        ScoredResult::create([
            'assessment_id' => $a1->id, 'scopes' => ['mcs'], 'norm_set' => 'none', 'product_code' => 'VC18',
            'language' => 'en', 'results' => [], 'scored_at' => '2026-07-01 12:00:00',
        ]);
        ScoredResult::create([
            'assessment_id' => $a2->id, 'scopes' => ['mcs', 'insights'], 'norm_set' => 'none', 'product_code' => 'VC18',
            'language' => 'en', 'results' => [], 'scored_at' => '2026-07-02 09:00:00',
        ]);
        ScoredResult::create([
            'assessment_id' => $a3->id, 'scopes' => ['insights'], 'norm_set' => 'none', 'product_code' => 'VC18',
            'language' => 'fr', 'results' => [], 'scored_at' => '2026-07-03 15:00:00',
        ]);

        // Scored outside the window, even though the assessment is in it.
        ScoredResult::create([
            'assessment_id' => $a1->id, 'scopes' => ['mcs'], 'norm_set' => 'none', 'product_code' => 'VC18',
            'language' => 'en', 'results' => [], 'scored_at' => '2026-06-30 08:00:00',
        ]);
        ScoredResult::create([
            'assessment_id' => $a1->id, 'scopes' => ['mcs'], 'norm_set' => 'none', 'product_code' => 'VC18',
            'language' => 'en', 'results' => [], 'scored_at' => '2026-07-05 08:00:00',
        ]);

        return [$keyWithClient, $keyNoClient, $client, $a1, $a2, $a3];
    }

    private function volume(array $query): array
    {
        $this->actingAs($this->viewer());

        return $this->getJson('/panel/api/reports/volume?'.http_build_query($query))
            ->assertOk()
            ->json();
    }

    public function test_requires_login(): void
    {
        $this->getJson('/panel/api/reports/volume')->assertStatus(401);
    }

    public function test_totals_and_zero_filled_series_across_window(): void
    {
        $this->seedVolumeData();

        $json = $this->volume(['from' => '2026-07-01', 'to' => '2026-07-04']);

        $this->assertSame(['from' => '2026-07-01', 'to' => '2026-07-04'], $json['period']);
        $this->assertSame(3, $json['totals']['created']);
        $this->assertSame(3, $json['totals']['scored']);

        $this->assertCount(4, $json['series']);
        $byDate = collect($json['series'])->keyBy('date');
        $this->assertSame(['date' => '2026-07-01', 'created' => 1, 'scored' => 1], $byDate['2026-07-01']);
        $this->assertSame(['date' => '2026-07-02', 'created' => 1, 'scored' => 1], $byDate['2026-07-02']);
        $this->assertSame(['date' => '2026-07-03', 'created' => 1, 'scored' => 1], $byDate['2026-07-03']);
        $this->assertSame(['date' => '2026-07-04', 'created' => 0, 'scored' => 0], $byDate['2026-07-04']);
    }

    public function test_defaults_group_by_to_language_and_rejects_invalid_value(): void
    {
        $this->seedVolumeData();

        $json = $this->volume(['from' => '2026-07-01', 'to' => '2026-07-04']);
        $this->assertSame('language', $json['group_by']);

        $json = $this->volume(['from' => '2026-07-01', 'to' => '2026-07-04', 'group_by' => 'bogus']);
        $this->assertSame('language', $json['group_by']);
    }

    public function test_language_slicing_counts_created_and_scored(): void
    {
        $this->seedVolumeData();

        $json = $this->volume(['from' => '2026-07-01', 'to' => '2026-07-04', 'group_by' => 'language']);
        $slices = collect($json['slices'])->keyBy('key');

        $this->assertSame(['key' => 'en', 'label' => 'en', 'created' => 2, 'scored' => 2], $slices['en']);
        $this->assertSame(['key' => 'fr', 'label' => 'fr', 'created' => 1, 'scored' => 1], $slices['fr']);
    }

    public function test_gender_null_becomes_unspecified(): void
    {
        $this->seedVolumeData();

        $json = $this->volume(['from' => '2026-07-01', 'to' => '2026-07-04', 'group_by' => 'gender']);
        $slices = collect($json['slices'])->keyBy('key');

        $this->assertSame(['key' => 'M', 'label' => 'M', 'created' => 1, 'scored' => 1], $slices['M']);
        $this->assertSame(['key' => 'F', 'label' => 'F', 'created' => 1, 'scored' => 1], $slices['F']);
        $this->assertSame(['key' => 'unspecified', 'label' => 'unspecified', 'created' => 1, 'scored' => 1], $slices['unspecified']);
    }

    public function test_client_slicing_includes_no_client_and_sorts_by_created_desc(): void
    {
        [, , $client] = $this->seedVolumeData();

        $json = $this->volume(['from' => '2026-07-01', 'to' => '2026-07-04', 'group_by' => 'client']);

        $this->assertSame(
            [(string) $client->id, '0'],
            array_column($json['slices'], 'key'),
            'expected the client with more created assessments (2) sorted before the no-client bucket (1)'
        );

        $slices = collect($json['slices'])->keyBy('key');
        $this->assertSame(['key' => (string) $client->id, 'label' => 'Client A', 'created' => 2, 'scored' => 2], $slices[(string) $client->id]);
        $this->assertSame(['key' => '0', 'label' => '(no client)', 'created' => 1, 'scored' => 1], $slices['0']);
    }

    public function test_scope_slicing_counts_multi_scope_result_in_each_scope_with_zero_created(): void
    {
        $this->seedVolumeData();

        $json = $this->volume(['from' => '2026-07-01', 'to' => '2026-07-04', 'group_by' => 'scope']);
        $slices = collect($json['slices'])->keyBy('key');

        // mcs: a1's result + a2's result. insights: a2's result + a3's result.
        $this->assertSame(['key' => 'mcs', 'label' => 'mcs', 'created' => 0, 'scored' => 2], $slices['mcs']);
        $this->assertSame(['key' => 'insights', 'label' => 'insights', 'created' => 0, 'scored' => 2], $slices['insights']);
    }

    public function test_records_outside_window_are_excluded(): void
    {
        $this->seedVolumeData();

        // Narrow window to just 07-02 so only a2's created assessment and
        // scored result should count.
        $json = $this->volume(['from' => '2026-07-02', 'to' => '2026-07-02']);

        $this->assertSame(1, $json['totals']['created']);
        $this->assertSame(1, $json['totals']['scored']);
        $this->assertCount(1, $json['series']);
        $this->assertSame(['date' => '2026-07-02', 'created' => 1, 'scored' => 1], $json['series'][0]);
    }
}
