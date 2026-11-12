<?php

namespace Tests\Unit;

use App\Support\Exports\TableExporter;
use Tests\TestCase;

class TableExporterTest extends TestCase
{
    public function test_quantity_values_remain_canonical_decimal_strings(): void
    {
        $this->assertSame('0.0625', TableExporter::value('0.0625', 'quantity'));
        $this->assertSame('1.125', TableExporter::value('1.1250', 'quantity'));
        $this->assertSame('2', TableExporter::value('2.0000', 'quantity'));
    }

    public function test_money_and_integer_values_keep_their_existing_contracts(): void
    {
        $this->assertSame(7, TableExporter::value('7', 'integer'));
        $this->assertSame(12.5, TableExporter::value('12.50', 'money'));
    }
}
