<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PanelUser extends Command
{
    protected $signature = 'panel:user {name} {email} {--role=viewer : admin|viewer} {--password= : Omit to generate one}';

    protected $description = 'Create a control-panel login (docs/08 roles: admin, viewer)';

    public function handle(): int
    {
        $role = $this->option('role');
        if (! in_array($role, ['admin', 'viewer'], true)) {
            $this->error("role must be admin or viewer, got '{$role}'.");

            return self::FAILURE;
        }

        $password = $this->option('password') ?? Str::random(20);
        User::create([
            'name' => $this->argument('name'),
            'email' => $this->argument('email'),
            'password' => Hash::make($password),
            'role' => $role,
        ]);

        $this->info("Panel {$role} '{$this->argument('email')}' created.");
        if ($this->option('password') === null) {
            $this->line("  Generated password (shown once): {$password}");
        }

        return self::SUCCESS;
    }
}
