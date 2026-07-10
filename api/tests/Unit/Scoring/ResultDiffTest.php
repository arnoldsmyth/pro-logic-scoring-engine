<?php

namespace Tests\Unit\Scoring;

use App\Scoring\GoldenMaster\ResultDiff;
use PHPUnit\Framework\TestCase;

class ResultDiffTest extends TestCase
{
    public function test_identical_structures_produce_no_diffs(): void
    {
        $body = ['mcs' => ['societal_change' => ['m' => 1, 'c' => 2, 's' => 3]], 'etc' => ['x' => 'y']];

        $this->assertSame([], ResultDiff::diff($body, $body));
    }

    public function test_value_mismatch_is_reported_with_dotted_path(): void
    {
        $diffs = ResultDiff::diff(
            ['mcs' => ['societal_change' => ['m' => 1]]],
            ['mcs' => ['societal_change' => ['m' => 2]]],
        );

        $this->assertCount(1, $diffs);
        $this->assertSame('mcs.societal_change.m', $diffs[0]['path']);
        $this->assertSame('value', $diffs[0]['kind']);
        $this->assertSame(1, $diffs[0]['expected']);
        $this->assertSame(2, $diffs[0]['actual']);
    }

    public function test_missing_and_unexpected_keys_are_reported(): void
    {
        $diffs = ResultDiff::diff(['a' => 1, 'b' => 2], ['b' => 2, 'c' => 3]);

        $kinds = array_column($diffs, 'kind', 'path');
        $this->assertSame('missing', $kinds['a']);
        $this->assertSame('unexpected', $kinds['c']);
        $this->assertArrayNotHasKey('b', $kinds);
    }

    public function test_numeric_strings_compare_by_value(): void
    {
        $this->assertSame([], ResultDiff::diff(['rank' => '7'], ['rank' => 7]));
        $this->assertSame([], ResultDiff::diff(['score' => '2.5'], ['score' => 2.5]));
    }

    public function test_non_numeric_strings_compare_strictly(): void
    {
        $diffs = ResultDiff::diff(['label' => 'Alpha'], ['label' => 'alpha']);

        $this->assertCount(1, $diffs);
    }

    public function test_nested_structure_replaced_by_scalar_is_a_value_diff(): void
    {
        $diffs = ResultDiff::diff(['a' => ['b' => 1]], ['a' => 'oops']);

        $this->assertCount(1, $diffs);
        $this->assertSame('a', $diffs[0]['path']);
        $this->assertSame('value', $diffs[0]['kind']);
    }
}
