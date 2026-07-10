<?php

namespace App\Scoring\GoldenMaster;

/**
 * Deep structural comparison of an engine result against a legacy golden,
 * reporting every divergent field by dotted path (docs/10 step 3).
 */
final class ResultDiff
{
    /**
     * @return list<array{path: string, kind: 'missing'|'unexpected'|'value', expected: mixed, actual: mixed}>
     */
    public static function diff(array $expected, array $actual, string $prefix = ''): array
    {
        $diffs = [];

        foreach ($expected as $key => $expectedValue) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            if (! array_key_exists($key, $actual)) {
                $diffs[] = ['path' => $path, 'kind' => 'missing', 'expected' => $expectedValue, 'actual' => null];

                continue;
            }
            $actualValue = $actual[$key];
            if (is_array($expectedValue) && is_array($actualValue)) {
                $diffs = [...$diffs, ...self::diff($expectedValue, $actualValue, $path)];
            } elseif (! self::equals($expectedValue, $actualValue)) {
                $diffs[] = ['path' => $path, 'kind' => 'value', 'expected' => $expectedValue, 'actual' => $actualValue];
            }
        }

        foreach ($actual as $key => $actualValue) {
            if (! array_key_exists($key, $expected)) {
                $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
                $diffs[] = ['path' => $path, 'kind' => 'unexpected', 'expected' => null, 'actual' => $actualValue];
            }
        }

        return $diffs;
    }

    /**
     * Scalar equality: numeric strings and numbers compare by value ("7" == 7
     * — legacy JSON is inconsistent about numeric types), everything else
     * strictly.
     */
    private static function equals(mixed $expected, mixed $actual): bool
    {
        if (is_numeric($expected) && is_numeric($actual)) {
            return (float) $expected === (float) $actual;
        }

        return $expected === $actual;
    }
}
