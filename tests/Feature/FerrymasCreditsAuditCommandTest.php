<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class FerrymasCreditsAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $legacyMainPath;

    private string $legacyBranchPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->legacyMainPath = storage_path('framework/testing/legacy_main.sqlite');
        $this->legacyBranchPath = storage_path('framework/testing/legacy_branch.sqlite');

        File::ensureDirectoryExists(dirname($this->legacyMainPath));
        File::put($this->legacyMainPath, '');
        File::put($this->legacyBranchPath, '');

        config([
            'database.connections.legacy_main' => [
                'driver' => 'sqlite',
                'database' => $this->legacyMainPath,
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
            'database.connections.legacy_branch' => [
                'driver' => 'sqlite',
                'database' => $this->legacyBranchPath,
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);

        DB::purge('legacy_main');
        DB::purge('legacy_branch');
    }

    public function test_ferrymas_credits_audit_command_is_registered(): void
    {
        $this->assertArrayHasKey('ferrymas:audit-credits', Artisan::all());
    }

    public function test_ferrymas_credits_audit_writes_csv_files_with_missing_tables(): void
    {
        $this->artisan('ferrymas:audit-credits', ['--business' => 1])
            ->assertExitCode(0);

        $run = DB::table('migration_runs')
            ->where('type', 'ferrymas_credits_focused_audit')
            ->latest('id')
            ->first();

        $this->assertNotNull($run);
        $this->assertSame('completed', $run->status);
        $this->assertDirectoryExists($run->output_path);

        foreach ([
            'credits_schema_discovery.csv',
            'credit_reservations_pending_summary.csv',
            'credit_reservations_pending_lines.csv',
            'real_ar_sources_summary.csv',
            'abonos_summary.csv',
            'credits_audit_summary.csv',
        ] as $filename) {
            $this->assertFileExists($run->output_path.DIRECTORY_SEPARATOR.$filename);
        }

        $schemaDiscovery = File::get($run->output_path.DIRECTORY_SEPARATOR.'credits_schema_discovery.csv');
        $this->assertStringContainsString('ferrymas_main,credito_venta,no', $schemaDiscovery);
        $this->assertStringContainsString('ferry_tramp,credito_venta,no', $schemaDiscovery);

        $summary = File::get($run->output_path.DIRECTORY_SEPARATOR.'credit_reservations_pending_summary.csv');
        $this->assertStringContainsString('total_pending_lines', $summary);
        $this->assertStringContainsString('ferrymas_main,0,0,0.00,0.00,0,0', $summary);
    }
}
