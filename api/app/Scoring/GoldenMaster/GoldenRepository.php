<?php

namespace App\Scoring\GoldenMaster;

final class GoldenRepository
{
    public function __construct(private readonly ?string $path = null) {}

    public function path(): string
    {
        return $this->path ?? config('scoring.goldens_path');
    }

    public function available(): bool
    {
        return is_dir($this->path());
    }

    /** @return list<GoldenSession> ordered by session key */
    public function all(): array
    {
        if (! $this->available()) {
            return [];
        }

        $sessions = [];
        foreach (glob($this->path().'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $key = basename($dir);
            if (is_file($dir.'/request.json') && is_file($dir.'/results_keys.json')) {
                $sessions[] = new GoldenSession($key, $dir);
            }
        }
        usort($sessions, fn ($a, $b) => (int) $a->sessionKey <=> (int) $b->sessionKey);

        return $sessions;
    }

    public function find(string $sessionKey): ?GoldenSession
    {
        foreach ($this->all() as $session) {
            if ($session->sessionKey === $sessionKey) {
                return $session;
            }
        }

        return null;
    }
}
