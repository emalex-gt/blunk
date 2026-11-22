<?php

namespace Tests\Unit;

use App\Services\SuperAdmin\PosFelTraceReport;
use Tests\TestCase;

class PosFelTraceReportTest extends TestCase
{
    public function test_it_groups_complete_and_incomplete_traces_without_inventing_missing_durations(): void
    {
        $trace = '11111111-1111-4111-8111-111111111111';
        $events = collect([
            'submit_clicked', 'post_finished', 'destination_get_finished', 'inertia_on_success', 'destination_first_frame', 'print_open_started',
        ])->map(fn (string $phase, int $index) => [
            'trace_id' => $trace,
            'phase' => $phase,
            'duration_ms' => ($index + 1) * 100,
            'business_id' => 9,
            'environment' => 'production',
        ])->all();
        $events[] = [
            'trace_id' => '22222222-2222-4222-8222-222222222222',
            'phase' => 'submit_clicked',
            'duration_ms' => 25,
            'business_id' => 9,
            'environment' => 'test',
        ];

        $report = app(PosFelTraceReport::class)->summarize($events);

        $this->assertCount(2, $report['traces']);
        $this->assertEquals(600.0, $report['traces'][0]['click_to_print_ms']);
        $this->assertFalse($report['traces'][0]['incomplete']);
        $this->assertTrue($report['traces'][1]['incomplete']);
        $this->assertContains('post_finished', $report['traces'][1]['missing_phases']);
        $this->assertSame('production', $report['percentiles'][0]['environment']);
    }
}
