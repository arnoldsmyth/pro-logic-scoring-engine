<?php

namespace App\Console\Commands;

use App\Models\AccessCode;
use App\Scoring\Engine\ProductCatalog;
use App\Scoring\Scopes;
use Illuminate\Console\Command;

class AccessCodeIssue extends Command
{
    protected $signature = 'code:issue {--name= : Human-readable display name for reporting (required)}
        {--order-type=training : training|complimentary|lead|sale — reporting dimension, never a gate (charges-payouts-data-model.md)}
        {--charge=0 : Charge amount the client owes per order under this code}
        {--currency=USD : Charge currency}
        {--product=VC18 : ProductCatalog entry this code scores}
        {--scopes=full : Comma-separated allowed scopes}
        {--max-uses= : Optional usage cap}
        {--expires= : Optional expiry (any strtotime-parsable date)}
        {--issued-to= : Client/org the code is issued to}
        {--notes=}';

    protected $description = 'Issue an access code (royalty terms are added separately — a code can carry several)';

    public function handle(): int
    {
        if (! $this->option('name')) {
            $this->error('--name is required (decided 2026-07-11: codes carry a display name for royalty reporting).');

            return self::FAILURE;
        }

        $type = $this->option('order-type');
        if (! in_array($type, ['training', 'complimentary', 'lead', 'sale'], true)) {
            $this->error("order-type must be training, complimentary, lead or sale — got '{$type}'.");

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
            'name' => $this->option('name'),
            'order_type' => $type,
            'charge_amount' => (float) $this->option('charge'),
            'charge_currency' => strtoupper($this->option('currency')),
            'product_code' => $this->option('product'),
            'allowed_scopes' => $scopes,
            'max_uses' => $this->option('max-uses') !== null ? (int) $this->option('max-uses') : null,
            'expires_at' => $this->option('expires') !== null ? new \DateTimeImmutable($this->option('expires')) : null,
            'issued_to' => $this->option('issued-to'),
            'notes' => $this->option('notes'),
            'created_by' => get_current_user(),
        ]);

        $this->info("Access code #{$code->id} '{$code->name}' ({$code->order_type}, charge {$code->charge_amount} {$code->charge_currency}, product {$code->product_code}) issued:");
        $this->line("  {$code->code}");
        $this->line('  Allowed scopes: '.implode(', ', $scopes));

        return self::SUCCESS;
    }
}
