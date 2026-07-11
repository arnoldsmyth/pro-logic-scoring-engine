<?php

namespace App\Console\Commands;

use App\Models\AccessCode;
use App\Scoring\Engine\ProductCatalog;
use App\Scoring\Scopes;
use Illuminate\Console\Command;

class AccessCodeIssue extends Command
{
    protected $signature = 'code:issue {--type=training : training|bizdev|derivative (docs/07)}
        {--product=VC18 : ProductCatalog entry this code scores}
        {--scopes=full : Comma-separated allowed scopes}
        {--max-uses= : Optional usage cap}
        {--expires= : Optional expiry (any strtotime-parsable date)}
        {--issued-to= : Client/org the code is issued to}
        {--notes=}';

    protected $description = 'Issue an access code (royalty terms are added separately — a code can carry several)';

    public function handle(): int
    {
        $type = $this->option('type');
        if (! in_array($type, ['training', 'bizdev', 'derivative'], true)) {
            $this->error("type must be training, bizdev or derivative — got '{$type}'.");

            return self::FAILURE;
        }

        if (! isset(ProductCatalog::PRODUCTS[$this->option('product')])) {
            $this->error("Unknown product '{$this->option('product')}'. Known: ".implode(', ', array_keys(ProductCatalog::PRODUCTS)));

            return self::FAILURE;
        }

        $scopes = array_map(trim(...), explode(',', $this->option('scopes')));
        [, $unknown] = Scopes::expand($scopes);
        if ($unknown !== []) {
            $this->error('Unknown scope(s): '.implode(', ', $unknown));

            return self::FAILURE;
        }

        $code = AccessCode::create([
            'code' => AccessCode::generateCode(),
            'type' => $type,
            'product_code' => $this->option('product'),
            'allowed_scopes' => $scopes,
            'max_uses' => $this->option('max-uses') !== null ? (int) $this->option('max-uses') : null,
            'expires_at' => $this->option('expires') !== null ? new \DateTimeImmutable($this->option('expires')) : null,
            'issued_to' => $this->option('issued-to'),
            'notes' => $this->option('notes'),
            'created_by' => get_current_user(),
        ]);

        $this->info("Access code #{$code->id} ({$code->type}, product {$code->product_code}) issued:");
        $this->line("  {$code->code}");
        $this->line('  Allowed scopes: '.implode(', ', $scopes));

        return self::SUCCESS;
    }
}
