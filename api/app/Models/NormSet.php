<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NormSet extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'provisional' => 'boolean',
            'provenance' => 'array',
            'impact' => 'array',
            'activated_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(NormSetEntry::class);
    }

    /** Conversion table in the engine's shape: [scaleKey][raw] → normed. */
    public function table(): array
    {
        $table = [];
        foreach ($this->entries()->get(['tool_scale_detail_key', 'raw', 'normed']) as $e) {
            $table[(int) $e->tool_scale_detail_key][(string) (float) $e->raw] = (float) $e->normed;
        }

        return $table;
    }

    /** The active set this one would replace (same population). */
    public function replaces(): ?self
    {
        return self::query()
            ->where('status', 'active')
            ->where('language', $this->language)
            ->where('gender', $this->gender)
            ->where('id', '!=', $this->id)
            ->first();
    }
}
