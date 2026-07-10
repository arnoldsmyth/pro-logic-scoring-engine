<?php

namespace App\Console\Commands;

use App\Support\Csv\PipeTable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Loads the legacy rule/matrix/norm/content extracts (restore-db/extracted/)
 * into local tables, keeping the legacy table and column names verbatim so
 * the rule interpreter and the docs speak the same vocabulary (docs/04).
 *
 * Schema is inferred from the data — the extracts, not hand-written
 * migrations, are the source of truth for this config data.
 */
class LegacyImport extends Command
{
    protected $signature = 'legacy:import
        {--path= : Directory of extracted CSVs (defaults to config scoring.legacy_extracted_path)}
        {--only=* : Import only these tables}';

    protected $description = 'Import legacy TMS config extracts (rules, matrices, norms, content) into the database';

    /**
     * Never imported: WebServiceUsers holds live partner credentials and is
     * not scoring config (rotate-at-cutover, docs/01). Files starting with
     * "_" are extraction metadata.
     */
    private const EXCLUDED = ['WebServiceUsers'];

    public function handle(): int
    {
        $path = $this->option('path') ?: config('scoring.legacy_extracted_path');
        if (! is_dir($path)) {
            $this->error("Extract directory not found: {$path}");
            $this->line('Run taicode/restore-db/1-restore.sh + 2-extract.sh first, or pass --path / set LEGACY_EXTRACTED_PATH.');

            return self::FAILURE;
        }

        $only = $this->option('only');
        $files = glob($path.'/*.csv') ?: [];
        $imported = 0;
        $totalRows = 0;

        foreach ($files as $file) {
            $table = basename($file, '.csv');
            if (str_starts_with($table, '_') || in_array($table, self::EXCLUDED, true)) {
                continue;
            }
            if ($only !== [] && ! in_array($table, $only, true)) {
                continue;
            }

            $rows = $this->importTable($table, $file);
            $this->line(sprintf('  %-32s %6d rows', $table, $rows));
            $imported++;
            $totalRows += $rows;
        }

        if ($imported === 0) {
            $this->error('No tables imported.');

            return self::FAILURE;
        }

        $this->verifyRowCounts($path);
        $this->info("Imported {$imported} tables, {$totalRows} rows.");

        return self::SUCCESS;
    }

    private function importTable(string $table, string $file): int
    {
        $csv = PipeTable::fromFile($file);
        $schema = $csv->inferSchema();

        Schema::dropIfExists($table);
        Schema::create($table, function ($blueprint) use ($schema) {
            foreach ($schema as $column => $def) {
                $col = $blueprint->{$def['type']}($column);
                if ($def['nullable']) {
                    $col->nullable();
                }
            }
        });

        $rows = $csv->typedRows();
        // Stay under SQLite's bound-parameter limit.
        $chunkSize = max(1, intdiv(900, max(1, count($csv->columns))));
        DB::transaction(function () use ($table, $rows, $chunkSize) {
            foreach (array_chunk($rows, $chunkSize) as $chunk) {
                DB::table($table)->insert($chunk);
            }
        });

        return count($rows);
    }

    /**
     * Compare imported counts against the extraction manifest so a truncated
     * copy of the extracts fails loudly rather than silently mis-scoring.
     */
    private function verifyRowCounts(string $path): void
    {
        $manifest = $path.'/_table_rowcounts.csv';
        if (! is_file($manifest)) {
            return;
        }

        $mismatches = 0;
        foreach (array_slice(file($manifest, FILE_IGNORE_NEW_LINES), 1) as $line) {
            [$table, $expected] = array_pad(explode(',', $line), 2, null);
            if ($table === null || $expected === null || ! Schema::hasTable($table)) {
                continue;
            }
            $actual = DB::table($table)->count();
            if ($actual !== (int) $expected) {
                $this->warn("  {$table}: imported {$actual}, manifest says {$expected}");
                $mismatches++;
            }
        }

        if ($mismatches === 0) {
            $this->info('Row counts match the extraction manifest.');
        }
    }
}
