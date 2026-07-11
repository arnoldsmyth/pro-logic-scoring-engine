<?php

namespace App\Console\Commands;

use App\Models\AccessCode;
use App\Models\ApiKey;
use Illuminate\Console\Command;

class ApiKeyIssue extends Command
{
    protected $signature = 'apikey:issue {name : Client/integration name}
        {--rate=60 : Requests per minute}
        {--code= : Default access code (the code string) for scoring calls}';

    protected $description = 'Issue an API key; the plaintext token is shown once and only its hash is stored';

    public function handle(): int
    {
        $defaultCode = null;
        if ($this->option('code') !== null) {
            $defaultCode = AccessCode::query()->where('code', $this->option('code'))->first();
            if ($defaultCode === null) {
                $this->error("Access code '{$this->option('code')}' not found.");

                return self::FAILURE;
            }
        }

        ['token' => $token, 'attributes' => $attributes] = ApiKey::generate();
        $key = ApiKey::create([
            ...$attributes,
            'name' => $this->argument('name'),
            'rate_limit_per_minute' => (int) $this->option('rate'),
            'default_access_code_id' => $defaultCode?->id,
        ]);

        $this->info("API key #{$key->id} issued for '{$key->name}'.");
        $this->line('');
        $this->line('  Bearer token (shown ONCE, store it now):');
        $this->line("  {$token}");

        return self::SUCCESS;
    }
}
