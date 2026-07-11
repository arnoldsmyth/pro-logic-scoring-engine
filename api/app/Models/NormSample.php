<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Aggregate raw-score distribution per (language, gender, scale, raw) —
 * the docs/06 continuous-evaluation accumulator. Counts only, never
 * per-respondent rows: this is what keeps the analytics layer anonymized.
 */
class NormSample extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /**
     * Fold one scoring run's observations into the aggregate counts.
     *
     * @param  list<array{scale: int, raw: float}>  $observations
     */
    public static function record(string $language, ?string $gender, array $observations): void
    {
        if ($observations === []) {
            return;
        }

        // Collapse duplicate (scale, raw) pairs within the run first.
        $increments = [];
        foreach ($observations as $o) {
            $key = $o['scale'].'|'.(float) $o['raw'];
            $increments[$key] = [
                'scale' => $o['scale'],
                'raw' => (float) $o['raw'],
                'count' => ($increments[$key]['count'] ?? 0) + 1,
            ];
        }

        foreach ($increments as $inc) {
            $updated = self::query()
                ->where('language', $language)
                ->where('gender', $gender)
                ->where('tool_scale_detail_key', $inc['scale'])
                ->where('raw', $inc['raw'])
                ->update(['count' => DB::raw('count + '.$inc['count'])]);
            if ($updated === 0) {
                self::create([
                    'language' => $language,
                    'gender' => $gender,
                    'tool_scale_detail_key' => $inc['scale'],
                    'raw' => $inc['raw'],
                    'count' => $inc['count'],
                ]);
            }
        }
    }
}
