<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Assessment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'dob' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $assessment) {
            $assessment->public_id ??= (string) Str::ulid();
        });
    }

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }

    public function tools(): HasMany
    {
        return $this->hasMany(AssessmentTool::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(ScoredResult::class);
    }

    /** @return array<string, array<int, int|string>> tool => {q: a} map for the engine */
    public function toolResponses(): array
    {
        return $this->tools->mapWithKeys(fn (AssessmentTool $t) => [$t->tool => $t->responses])->all();
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
