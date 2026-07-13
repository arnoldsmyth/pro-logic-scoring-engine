<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ApiKey extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function defaultAccessCode(): BelongsTo
    {
        return $this->belongsTo(AccessCode::class, 'default_access_code_id');
    }

    public function usageEvents(): HasMany
    {
        return $this->hasMany(UsageEvent::class);
    }

    /**
     * Generate a bearer token and its persistable key row attributes. The
     * plaintext token is shown once at issue time and only its sha256 hash
     * is stored (docs/05: hashed at rest).
     *
     * @return array{token: string, attributes: array{key_hash: string, key_prefix: string}}
     */
    public static function generate(): array
    {
        $token = 'sk_'.Str::random(40);

        return [
            'token' => $token,
            'attributes' => [
                'key_hash' => hash('sha256', $token),
                'key_prefix' => substr($token, 0, 8),
            ],
        ];
    }

    public static function findByToken(string $token): ?self
    {
        return self::query()
            ->where('key_hash', hash('sha256', $token))
            ->where('active', true)
            ->first();
    }
}
