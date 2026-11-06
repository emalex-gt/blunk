<?php

namespace Tests\Unit;

use App\Services\SuperAdmin\OperationsLogReader;
use Tests\TestCase;

class OperationsLogReaderTest extends TestCase
{
    public function test_initial_request_without_optional_filters_uses_normalized_defaults(): void
    {
        $filters = app(OperationsLogReader::class)->normalizeFilters([]);

        $this->assertSame('all', $filters['category']);
        $this->assertSame('all', $filters['result']);
        $this->assertSame('', $filters['event']);
    }

    public function test_missing_category_result_and_event_are_safe(): void
    {
        $filters = app(OperationsLogReader::class)->normalizeFilters(['date_from' => now()->toDateString(), 'date_to' => now()->toDateString()]);

        $this->assertSame('all', $filters['category']);
        $this->assertSame('all', $filters['result']);
        $this->assertSame('', $filters['event']);
    }

    public function test_valid_and_invalid_operation_filters_are_normalized_without_changing_date_limits(): void
    {
        $reader = app(OperationsLogReader::class);
        $date = now()->toDateString();
        $valid = $reader->normalizeFilters(['date_from' => $date, 'date_to' => $date, 'category' => 'fel', 'result' => 'warning', 'event' => 'fel.certification.unknown']);
        $invalid = $reader->normalizeFilters(['date_from' => $date, 'date_to' => $date, 'category' => 'invalid', 'result' => 'invalid', 'event' => 'invalid']);

        $this->assertSame(['fel', 'warning', 'fel.certification.unknown'], [$valid['category'], $valid['result'], $valid['event']]);
        $this->assertSame(['all', 'all', ''], [$invalid['category'], $invalid['result'], $invalid['event']]);
        $this->assertSame($date, $valid['date_from']);
        $this->assertSame($date, $invalid['date_to']);
    }
}
