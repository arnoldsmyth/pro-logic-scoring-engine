<?php

namespace App\Api;

/**
 * Per-tool response validation (docs/02). Every answer is a single scalar
 * {q, a}, questions numbered from 1. Errors are structured per item:
 * {tool, q, rule, expected, got} — q is null for tool-level errors
 * (wrong question count, bad ranking totals).
 */
class ToolValidator
{
    /** tool => [question count, validator kind, args] */
    public const TOOLS = [
        'reflections' => ['count' => 28, 'kind' => 'text'],
        'personalmotivators' => ['count' => 27, 'kind' => 'ranking320'],
        'areamissions' => ['count' => 27, 'kind' => 'ranking320'],
        'abilitiesfilter' => ['count' => 63, 'kind' => 'range', 'min' => 1, 'max' => 6],
        'personalstyle' => ['count' => 96, 'kind' => 'style'],
        'personalexpectations' => ['count' => 72, 'kind' => 'range', 'min' => 1, 'max' => 4],
        'person' => ['count' => 54, 'kind' => 'range', 'min' => 1, 'max' => 6],
        'role' => ['count' => 54, 'kind' => 'range', 'min' => 1, 'max' => 6],
        'organization' => ['count' => 54, 'kind' => 'range', 'min' => 1, 'max' => 6],
    ];

    public static function isKnownTool(string $tool): bool
    {
        return isset(self::TOOLS[$tool]);
    }

    /**
     * @param  array<int|string, mixed>  $responses  {q: a}
     * @return list<array{tool: string, q: ?int, rule: string, expected: string, got: string}>
     */
    public function validate(string $tool, array $responses): array
    {
        $spec = self::TOOLS[$tool] ?? null;
        if ($spec === null) {
            return [self::error($tool, null, 'known_tool', implode('|', array_keys(self::TOOLS)), $tool)];
        }

        $errors = [];

        // Question numbering: exactly 1..count, each answered once.
        $qs = array_map(intval(...), array_keys($responses));
        $missing = array_diff(range(1, $spec['count']), $qs);
        $extra = array_diff($qs, range(1, $spec['count']));
        if ($missing !== [] || $extra !== []) {
            $errors[] = self::error($tool, null, 'question_set', "q1..q{$spec['count']} exactly once", count($responses).' answers'.($missing !== [] ? ', missing '.self::qList($missing) : '').($extra !== [] ? ', unexpected '.self::qList($extra) : ''));

            return $errors; // per-question checks are meaningless on a wrong set
        }

        return match ($spec['kind']) {
            'text' => $this->validateText($tool, $responses),
            'range' => $this->validateRange($tool, $responses, $spec['min'], $spec['max']),
            'ranking320' => $this->validateRanking320($tool, $responses),
            'style' => $this->validateStyle($tool, $responses),
        };
    }

    /** Free text (reflections): any scalar string, never scored. */
    private function validateText(string $tool, array $responses): array
    {
        $errors = [];
        foreach ($responses as $q => $a) {
            if (! is_string($a) && ! is_numeric($a)) {
                $errors[] = self::error($tool, (int) $q, 'text', 'string', get_debug_type($a));
            }
        }

        return $errors;
    }

    private function validateRange(string $tool, array $responses, int $min, int $max): array
    {
        $errors = [];
        foreach ($responses as $q => $a) {
            if (! self::isIntLike($a) || (int) $a < $min || (int) $a > $max) {
                $errors[] = self::error($tool, (int) $q, 'range', "integer {$min}-{$max}", self::show($a));
            }
        }

        return $errors;
    }

    /** Ranking tools (motivators/missions): exactly 3ד3", 3ד2", rest "0". */
    private function validateRanking320(string $tool, array $responses): array
    {
        $errors = [];
        $counts = ['3' => 0, '2' => 0, '0' => 0];
        foreach ($responses as $q => $a) {
            $v = self::isIntLike($a) ? (string) (int) $a : null;
            if ($v === null || ! isset($counts[$v])) {
                $errors[] = self::error($tool, (int) $q, 'allowed_values', '3, 2 or 0', self::show($a));

                continue;
            }
            $counts[$v]++;
        }
        if ($errors === [] && ($counts['3'] !== 3 || $counts['2'] !== 3)) {
            $errors[] = self::error($tool, null, 'ranking_counts', 'exactly 3×"3" and 3×"2", rest "0"', "{$counts['3']}×\"3\", {$counts['2']}×\"2\", {$counts['0']}×\"0\"");
        }

        return $errors;
    }

    /**
     * personalstyle: 24 groups of 4 consecutive questions; per group exactly
     * one "1" (most), one "-1" (least), two "0".
     */
    private function validateStyle(string $tool, array $responses): array
    {
        $errors = [];
        foreach ($responses as $q => $a) {
            if (! self::isIntLike($a) || ! in_array((int) $a, [1, -1, 0], true)) {
                $errors[] = self::error($tool, (int) $q, 'allowed_values', '1, -1 or 0', self::show($a));
            }
        }
        if ($errors !== []) {
            return $errors;
        }

        for ($group = 0; $group < 24; $group++) {
            $qs = range($group * 4 + 1, $group * 4 + 4);
            $values = array_map(fn (int $q) => (int) $responses[$q], $qs);
            $counts = array_count_values(array_map(strval(...), $values));
            if (($counts['1'] ?? 0) !== 1 || ($counts['-1'] ?? 0) !== 1) {
                $errors[] = self::error($tool, null, 'style_group', 'one "1", one "-1", two "0" in q'.$qs[0].'-q'.$qs[3], implode(',', $values));
            }
        }

        return $errors;
    }

    private static function isIntLike(mixed $a): bool
    {
        return is_int($a) || (is_string($a) && preg_match('/^-?\d+$/', $a) === 1);
    }

    private static function show(mixed $a): string
    {
        return is_scalar($a) ? (string) $a : get_debug_type($a);
    }

    /** @param list<int> $qs */
    private static function qList(array $qs): string
    {
        $qs = array_values($qs);
        $shown = array_slice($qs, 0, 5);

        return 'q'.implode(',q', $shown).(count($qs) > 5 ? '…' : '');
    }

    /** @return array{tool: string, q: ?int, rule: string, expected: string, got: string} */
    private static function error(string $tool, ?int $q, string $rule, string $expected, string $got): array
    {
        return ['tool' => $tool, 'q' => $q, 'rule' => $rule, 'expected' => $expected, 'got' => $got];
    }
}
