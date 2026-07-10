<?php

namespace App\Scoring\GoldenMaster;

use RuntimeException;

/**
 * One extracted legacy session: the production register payload plus the
 * results the legacy engine actually returned (docs/10).
 */
final class GoldenSession
{
    public function __construct(
        public readonly string $sessionKey,
        public readonly string $directory,
    ) {}

    /** @return array<string, mixed> */
    public function request(): array
    {
        return $this->json('request.json');
    }

    /** Expected results body, keys format. @return array<string, mixed> */
    public function expectedKeys(): array
    {
        return $this->json('results_keys.json');
    }

    /** Expected results body, strings format. @return array<string, mixed> */
    public function expectedStrings(): array
    {
        return $this->json('results_strings.json');
    }

    /** Registration info as the engine wants it. @return array<string, mixed> */
    public function registration(): array
    {
        return $this->request()['registrationinfo'] ?? [];
    }

    /**
     * Tool responses keyed by tool name: [q => a], q 1-based.
     *
     * @return array<string, array<int, int|string>>
     */
    public function tools(): array
    {
        $tools = [];
        foreach ($this->request()['assessment']['tools'] ?? [] as $tool) {
            $responses = [];
            foreach ($tool['responses'] ?? [] as $response) {
                $responses[(int) $response['q']] = $response['a'];
            }
            $tools[$tool['tool']] = $responses;
        }

        return $tools;
    }

    /** Per-field legacy output rows for debugging mismatches (may be absent on old sessions). */
    public function outstringsPath(): ?string
    {
        $path = $this->directory.'/outstrings.csv';

        return is_file($path) ? $path : null;
    }

    private function json(string $file): array
    {
        $path = $this->directory.'/'.$file;
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Golden {$this->sessionKey}: cannot read {$file}");
        }

        return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    }
}
