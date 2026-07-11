<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScoredResult extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'results' => 'array',
            'audit' => 'array',
            'scored_at' => 'datetime',
        ];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function accessCode(): BelongsTo
    {
        return $this->belongsTo(AccessCode::class);
    }
}
