<?php

namespace App\Support\Csv;

use RuntimeException;

/**
 * Parser for the pipe-delimited extracts produced by restore-db/2-extract.sh.
 *
 * Format: first line is the header, `NULL` is a SQL NULL, values carry the
 * trailing-space padding of SQL Server CHAR columns (stripped here — SQL
 * Server ignores trailing spaces in comparisons, so this is lossless for
 * scoring purposes). Embedded pipes/newlines do not occur in the extracts;
 * any row with the wrong field count is treated as corruption and rejected.
 */
final class PipeTable
{
    /**
     * @param  list<string>  $columns
     * @param  list<array<string, string|null>>  $rows
     */
    public function __construct(
        public readonly array $columns,
        public readonly array $rows,
    ) {}

    public static function fromFile(string $path): self
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Cannot read {$path}");
        }

        return self::fromString($contents, basename($path));
    }

    public static function fromString(string $contents, string $source = '<string>'): self
    {
        $lines = preg_split('/\r\n|\n/', rtrim($contents, "\r\n"));
        if ($lines === false || $lines === [] || $lines[0] === '') {
            throw new RuntimeException("{$source}: empty extract");
        }

        $columns = array_map(trim(...), explode('|', $lines[0]));
        $width = count($columns);

        $rows = [];
        foreach (array_slice($lines, 1) as $i => $line) {
            $fields = explode('|', $line);
            if (count($fields) !== $width) {
                $lineNo = $i + 2;
                throw new RuntimeException("{$source}: line {$lineNo} has ".count($fields)." fields, expected {$width}");
            }
            $row = [];
            foreach ($fields as $j => $value) {
                $value = rtrim($value, ' ');
                $row[$columns[$j]] = $value === 'NULL' ? null : $value;
            }
            $rows[] = $row;
        }

        return new self($columns, $rows);
    }

    /**
     * Infer a storage type per column from the data itself.
     *
     * @return array<string, array{type: 'integer'|'bigInteger'|'double'|'text', nullable: bool}>
     */
    public function inferSchema(): array
    {
        $schema = [];
        foreach ($this->columns as $column) {
            $isInt = true;
            $isNumeric = true;
            $maxDigits = 0;
            $nullable = false;

            foreach ($this->rows as $row) {
                $value = $row[$column];
                if ($value === null) {
                    $nullable = true;

                    continue;
                }
                if ($isInt && preg_match('/^-?\d+$/', $value)) {
                    $maxDigits = max($maxDigits, strlen(ltrim($value, '-')));
                } else {
                    $isInt = false;
                    if ($isNumeric && ! is_numeric($value)) {
                        $isNumeric = false;
                    }
                }
            }

            $schema[$column] = [
                'type' => match (true) {
                    $isInt && $maxDigits <= 9 => 'integer',
                    $isInt && $maxDigits <= 18 => 'bigInteger',
                    $isNumeric => 'double',
                    default => 'text',
                },
                'nullable' => $nullable,
            ];
        }

        return $schema;
    }

    /**
     * Rows with numeric strings cast to int/float per the inferred schema,
     * ready for DB insertion.
     *
     * @return list<array<string, int|float|string|null>>
     */
    public function typedRows(): array
    {
        $schema = $this->inferSchema();

        return array_map(function (array $row) use ($schema) {
            foreach ($row as $column => $value) {
                if ($value === null) {
                    continue;
                }
                $row[$column] = match ($schema[$column]['type']) {
                    'integer', 'bigInteger' => (int) $value,
                    'double' => (float) $value,
                    'text' => $value,
                };
            }

            return $row;
        }, $this->rows);
    }
}
