<?php

namespace Tests\Feature;

use App\Models\DeployRun;
use App\Services\System\PostgresPreDeployBackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\TestCase;

class PostgresPreDeployBackupServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $backupDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->backupDirectory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'blunk-pre-deploy-backups';
        File::deleteDirectory($this->backupDirectory);

        config([
            'deploy.backup_directory' => $this->backupDirectory,
            'deploy.pg_dump_path' => PHP_BINARY,
            'deploy.pg_restore_path' => PHP_BINARY,
            'deploy.backup_retention' => 30,
            'deploy.backup_timeout' => 60,
            'database.connections.pgsql.password' => 'must-not-appear-in-output',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->backupDirectory);

        parent::tearDown();
    }

    public function test_it_creates_validates_and_records_a_custom_pre_deploy_backup(): void
    {
        Process::fake(function (PendingProcess $process) {
            foreach ($process->command ?? [] as $argument) {
                if (str_starts_with($argument, '--file=')) {
                    File::put(substr($argument, 7), 'PGDMP'.str_repeat('x', 64));
                }
            }

            return Process::result('ok');
        });

        $run = DeployRun::query()->create(['status' => DeployRun::STATUS_RUNNING, 'branch' => 'main']);
        $target = str_repeat('a', 40);

        $backup = app(PostgresPreDeployBackupService::class)->createAndValidate($run, $target);

        $run->refresh();
        $this->assertFileExists($backup['path']);
        $this->assertMatchesRegularExpression('/blunk-pre-deploy-aaaaaaa-\d{8}T\d{6}Z\.dump$/', $backup['path']);
        $this->assertGreaterThan(0, $backup['size_bytes']);
        $this->assertSame($backup['path'], $run->backup_path);
        $this->assertSame($backup['size_bytes'], $run->backup_size_bytes);
        $this->assertNotNull($run->backup_created_at);
        $this->assertNotNull($run->backup_validated_at);
        Process::assertRan(fn (PendingProcess $process) => in_array('--format=custom', $process->command ?? [], true)
            && ($process->environment['PGPASSWORD'] ?? null) === 'must-not-appear-in-output');
        Process::assertRan(fn (PendingProcess $process) => in_array('--list', $process->command ?? [], true));
    }

    public function test_it_aborts_without_recording_metadata_when_pg_dump_fails(): void
    {
        Process::fake(function (PendingProcess $process) {
            foreach ($process->command ?? [] as $argument) {
                if (str_starts_with($argument, '--file=')) {
                    File::put(substr($argument, 7), 'partial backup');
                }
            }

            return Process::result(errorOutput: 'pg_dump failed', exitCode: 1);
        });
        $run = DeployRun::query()->create(['status' => DeployRun::STATUS_RUNNING, 'branch' => 'main']);

        try {
            app(PostgresPreDeployBackupService::class)->createAndValidate($run, str_repeat('b', 40));
            $this->fail('Expected backup failure.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('respaldo PostgreSQL', $exception->getMessage());
        }

        $run->refresh();
        $this->assertNull($run->backup_path);
        $this->assertNull($run->backup_validated_at);
        $this->assertCount(0, File::files($this->backupDirectory));
        Process::assertNotRan(fn (PendingProcess $process) => in_array('--list', $process->command ?? [], true));
    }

    public function test_it_aborts_without_recording_metadata_when_validation_fails(): void
    {
        Process::fake(function (PendingProcess $process) {
            foreach ($process->command ?? [] as $argument) {
                if (str_starts_with($argument, '--file=')) {
                    File::put(substr($argument, 7), 'PGDMP'.str_repeat('x', 64));
                }
            }

            return in_array('--list', $process->command ?? [], true)
                ? Process::result(errorOutput: 'invalid archive', exitCode: 1)
                : Process::result('ok');
        });
        $run = DeployRun::query()->create(['status' => DeployRun::STATUS_RUNNING, 'branch' => 'main']);

        try {
            app(PostgresPreDeployBackupService::class)->createAndValidate($run, str_repeat('c', 40));
            $this->fail('Expected validation failure.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('validar', $exception->getMessage());
        }

        $run->refresh();
        $this->assertNull($run->backup_path);
        $this->assertNull($run->backup_validated_at);
    }

    public function test_it_keeps_only_the_configured_automatic_backups_and_never_prunes_other_files(): void
    {
        config(['deploy.backup_retention' => 2]);
        File::ensureDirectoryExists($this->backupDirectory);

        foreach ([1, 2, 3] as $index) {
            $path = $this->backupDirectory.DIRECTORY_SEPARATOR."blunk-pre-deploy-abcdef{$index}-2026010{$index}T000000Z.dump";
            File::put($path, 'old backup');
            touch($path, time() - (100 + $index));
        }

        $manualBackup = $this->backupDirectory.DIRECTORY_SEPARATOR.'manual-operator-backup.dump';
        File::put($manualBackup, 'manual backup');

        Process::fake(function (PendingProcess $process) {
            foreach ($process->command ?? [] as $argument) {
                if (str_starts_with($argument, '--file=')) {
                    File::put(substr($argument, 7), 'PGDMP'.str_repeat('x', 64));
                }
            }

            return Process::result('ok');
        });

        $run = DeployRun::query()->create(['status' => DeployRun::STATUS_RUNNING, 'branch' => 'main']);
        $backup = app(PostgresPreDeployBackupService::class)->createAndValidate($run, str_repeat('d', 40));

        $automaticBackups = array_map(
            static fn (\SplFileInfo $file): string => $file->getPathname(),
            array_filter(File::files($this->backupDirectory), fn (\SplFileInfo $file): bool => str_starts_with($file->getFilename(), 'blunk-pre-deploy-')),
        );
        $this->assertCount(2, $automaticBackups);
        $this->assertFileExists($backup['path']);
        $this->assertFileExists($manualBackup);
    }

    public function test_it_records_a_valid_backup_when_retention_cleanup_fails(): void
    {
        Log::spy();
        Process::fake(function (PendingProcess $process) {
            foreach ($process->command ?? [] as $argument) {
                if (str_starts_with($argument, '--file=')) {
                    File::put(substr($argument, 7), 'PGDMP'.str_repeat('x', 64));
                }
            }

            return Process::result('ok');
        });
        $run = DeployRun::query()->create(['status' => DeployRun::STATUS_RUNNING, 'branch' => 'main']);
        $service = new class extends PostgresPreDeployBackupService
        {
            protected function prune(string $directory, int $retention, string $currentPath): void
            {
                throw new RuntimeException('simulated retention deletion failure');
            }
        };

        $backup = $service->createAndValidate($run, str_repeat('e', 40));

        $run->refresh();
        $this->assertSame($backup['path'], $run->backup_path);
        $this->assertSame($backup['size_bytes'], $run->backup_size_bytes);
        $this->assertNotNull($run->backup_created_at);
        $this->assertNotNull($run->backup_validated_at);
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'No se pudo aplicar la retención de respaldos pre-deploy.'
                && $context['backup_path'] === $backup['path']);
    }
}
