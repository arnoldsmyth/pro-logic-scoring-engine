<?php

namespace Tests\Unit\Support;

use App\Support\Csv\PipeTable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PipeTableTest extends TestCase
{
    public function test_parses_header_rows_nulls_and_char_padding(): void
    {
        $table = PipeTable::fromString(
            "Key|Name|Score\n".
            "1|Raw DISC Scales                |0.5\n".
            '2|NULL|NULL'
        );

        $this->assertSame(['Key', 'Name', 'Score'], $table->columns);
        $this->assertSame('Raw DISC Scales', $table->rows[0]['Name']);
        $this->assertNull($table->rows[1]['Name']);
        $this->assertNull($table->rows[1]['Score']);
    }

    public function test_rejects_rows_with_wrong_field_count(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('line 2');

        PipeTable::fromString("A|B\nonly-one-field");
    }

    public function test_schema_inference(): void
    {
        $table = PipeTable::fromString(
            "Id|Big|Ratio|Label|MixedNum\n".
            "1|12345678901|0.25|abc|7\n".
            '-2|NULL|3|x y|1.5'
        );

        $schema = $table->inferSchema();
        $this->assertSame(['type' => 'integer', 'nullable' => false], $schema['Id']);
        $this->assertSame(['type' => 'bigInteger', 'nullable' => true], $schema['Big']);
        $this->assertSame(['type' => 'double', 'nullable' => false], $schema['Ratio']);
        $this->assertSame(['type' => 'text', 'nullable' => false], $schema['Label']);
        $this->assertSame(['type' => 'double', 'nullable' => false], $schema['MixedNum']);
    }

    public function test_typed_rows_cast_numerics(): void
    {
        $table = PipeTable::fromString("Id|Ratio|Label\n7|0.5|abc");

        $this->assertSame([['Id' => 7, 'Ratio' => 0.5, 'Label' => 'abc']], $table->typedRows());
    }
}
