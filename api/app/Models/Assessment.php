<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Assessments belonging to the same person under the same API key
     * (decided 2026-07-11): caller-supplied external_id is the primary
     * identity signal, exact email match is the fallback. No fuzzy
     * matching. Includes this assessment itself.
     *
     * @return Builder<self>
     */
    public function samePersonQuery(): Builder
    {
        $query = self::query()->where('api_key_id', $this->api_key_id);

        if ($this->external_id !== null && $this->external_id !== '') {
            return $query->where('external_id', $this->external_id);
        }

        return $query->where('email', $this->email)
            ->where(fn ($q) => $q->whereNull('external_id')->orWhere('external_id', ''));
    }
}
