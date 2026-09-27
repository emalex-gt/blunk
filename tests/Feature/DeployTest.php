<?php

namespace Tests\Feature;

use App\Jobs\RunProductionDeployJob;
use App\Models\DeployRun;
use App\Models\User;
use App\Services\System\DeployService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

class DeployTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config([
            'deploy.timeout' => 900,
            'queue.default' => 'database',
            'queue.connections.database.retry_after' => 1200,
        ]);
    }

    public function test_deploy_screen_is_restricted_to_super_admins(): void
    {
        $tenantUser = User::factory()->create(['is_super_admin' => false]);
        $superAdmin = User::factory()->create(['is_super_admin' => true]);
        $this->mockStatus();

        $this->actingAs($tenantUser)
            ->get('/super-admin/system/deploy')
            ->assertForbidden();

        $response = $this->actingAs($superAdmin)
            ->get('/super-admin/system/deploy');

        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('SuperAdmin/System/Deploy')
                ->where('queueConnection', config('queue.default'))
                ->where('queueWorkerEnabled', false)
                ->where('status.branch', 'main')
                ->where('status.is_clean', true));
    }

    public function test_deploy_run_requires_exact_confirmation_and_an_enabled_worker_gate(): void
    {
        $superAdmin = User::factory()->create(['is_super_admin' => true]);
        Queue::fake();
        $this->mockStatus();

        $this->actingAs($superAdmin)
            ->post(route('super-admin.system.deploy.run'), ['confirmation' => 'actualizar'])
            ->assertSessionHasErrors('confirmation');

        $this->actingAs($superAdmin)
            ->post(route('super-admin.system.deploy.run'), ['confirmation' => 'ACTUALIZAR'])
            ->assertSessionHasErrors('deploy');

        $this->assertDatabaseCount('deploy_runs', 0);
        Queue::assertNothingPushed();
    }

    public function test_deploy_run_rejects_a_dirty_checkout_and_an_active_deploy(): void
    {
        $superAdmin = User::factory()->create(['is_super_admin' => true]);
        Queue::fake();
        config(['deploy.queue_worker_enabled' => true]);
        $this->mockStatus(['is_clean' => false, 'working_tree' => ' M app/Example.php']);

        $this->actingAs($superAdmin)
            ->post(route('super-admin.system.deploy.run'), ['confirmation' => 'ACTUALIZAR'])
            ->assertSessionHasErrors('deploy');

        $this->mockStatus();
        DeployRun::query()->create(['status' => DeployRun::STATUS_RUNNING, 'branch' => 'main']);

        $this->actingAs($superAdmin)
            ->post(route('super-admin.system.deploy.run'), ['confirmation' => 'ACTUALIZAR'])
            ->assertSessionHasErrors('deploy');

        Queue::assertNothingPushed();
    }

    public function test_deploy_run_rejects_an_unsafe_queue_retry_window(): void
    {
        $superAdmin = User::factory()->create(['is_super_admin' => true]);
        Queue::fake();
        config([
            'deploy.queue_worker_enabled' => true,
            'queue.default' => 'database',
            'queue.connections.database.retry_after' => 900,
        ]);
        $this->mockStatus();

        $this->actingAs($superAdmin)
            ->post(route('super-admin.system.deploy.run'), ['confirmation' => 'ACTUALIZAR'])
            ->assertSessionHasErrors('deploy');

        $this->assertDatabaseCount('deploy_runs', 0);
        Queue::assertNothingPushed();
    }

    public function test_super_admin_can_queue_a_fixed_deploy_without_request_commands(): void
    {
        $superAdmin = User::factory()->create(['is_super_admin' => true]);
        Queue::fake();
        config(['deploy.queue_worker_enabled' => true, 'deploy.queue' => 'deploys']);
        $this->mockStatus();

        $this->actingAs($superAdmin)
            ->post(route('super-admin.system.deploy.run'), [
                'confirmation' => 'ACTUALIZAR',
                'command' => 'rm -rf /',
                'branch' => 'another-branch',
            ])
            ->assertRedirect(route('super-admin.system.deploy.index'));

        $run = DeployRun::query()->sole();
        $this->assertSame(DeployRun::STATUS_PENDING, $run->status);
        $this->assertSame('main', $run->branch);
        $this->assertSame($superAdmin->id, $run->user_id);
        $this->assertSame(str_repeat('b', 40), $run->remote_commit_target);
        Queue::assertPushed(RunProductionDeployJob::class, fn (RunProductionDeployJob $job) =>
            $job->deployRunId === $run->id && $job->queue === 'deploys');
    }

    public function test_deploy_job_uses_the_dedicated_queue_without_retries(): void
    {
        config(['deploy.queue' => 'deploys']);

        $job = new RunProductionDeployJob(123);

        $this->assertSame('deploys', $job->queue);
        $this->assertSame(900, $job->timeout);
        $this->assertSame(1, $job->tries);
    }

    public function test_pending_job_is_cancelled_when_the_worker_gate_is_disabled(): void
    {
        config(['deploy.queue_worker_enabled' => false]);
        $run = DeployRun::query()->create(['status' => DeployRun::STATUS_PENDING, 'branch' => 'main']);
        $service = Mockery::mock(DeployService::class);
        $service->shouldNotReceive('runDeploy');

        (new RunProductionDeployJob($run->id))->handle($service);

        $this->assertSame(DeployRun::STATUS_CANCELLED, $run->refresh()->status);
    }

    public function test_deploy_service_pins_the_approved_target_and_supplies_safe_environment(): void
    {
        $target = str_repeat('b', 40);
        config([
            'deploy.script_path' => base_path('scripts/deploy-production.sh'),
            'deploy.home' => '/srv/blunk',
            'deploy.composer_home' => '/srv/blunk/.composer',
            'deploy.composer_path' => '/usr/local/lib/composer.phar',
            'deploy.pause_workers_hook' => '/usr/local/sbin/blunk-pause-workers',
            'deploy.resume_workers_hook' => '/usr/local/sbin/blunk-resume-workers',
        ]);
        Process::fake(function (PendingProcess $process) use ($target) {
            $command = implode(' ', $process->command ?? []);

            return match ($command) {
                'git branch --show-current' => Process::result('main'),
                'git status --short', 'git fetch origin' => Process::result(''),
                'git rev-parse HEAD' => Process::result($target),
                'git rev-parse origin/main' => Process::result($target),
                'git log --oneline -1 HEAD' => Process::result($target.' Local commit'),
                'git log --oneline -1 origin/main' => Process::result($target.' Remote commit'),
                default => Process::result('Deploy completed.'),
            };
        });

        $service = app(DeployService::class);
        $status = $service->status();
        $run = DeployRun::query()->create([
            'status' => DeployRun::STATUS_PENDING,
            'branch' => 'main',
            'local_commit_before' => str_repeat('a', 40),
            'remote_commit_target' => $target,
        ]);
        $service->runDeploy($run);

        $this->assertTrue($status['is_clean']);
        $this->assertFalse($status['has_pending_updates']);
        $this->assertSame(DeployRun::STATUS_SUCCEEDED, $run->refresh()->status);
        $this->assertSame($target, $run->remote_commit_target);
        $this->assertSame($target, $run->local_commit_after);
        Process::assertRan(fn (PendingProcess $process) =>
            $process->command === ['bash', config('deploy.script_path'), $target]
            && $process->environment['DEPLOY_HOME'] === '/srv/blunk'
            && $process->environment['DEPLOY_COMPOSER_HOME'] === '/srv/blunk/.composer'
            && $process->environment['DEPLOY_COMPOSER_PATH'] === '/usr/local/lib/composer.phar'
            && $process->environment['DEPLOY_PAUSE_WORKERS_HOOK'] === '/usr/local/sbin/blunk-pause-workers'
            && $process->environment['DEPLOY_RESUME_WORKERS_HOOK'] === '/usr/local/sbin/blunk-resume-workers');
        $this->assertFileExists(storage_path("logs/deploys/deploy-{$run->id}.log"));
    }

    public function test_deploy_service_stops_before_claim_and_process_when_execution_lock_is_held(): void
    {
        Process::fake();
        $run = DeployRun::query()->create([
            'status' => DeployRun::STATUS_PENDING,
            'branch' => 'main',
            'local_commit_before' => str_repeat('a', 40),
            'remote_commit_target' => str_repeat('b', 40),
        ]);
        $lock = Cache::lock('system:production-deploy:execution', 1200);
        $this->assertTrue($lock->get());

        try {
            $this->assertFalse(app(DeployService::class)->runDeploy($run));
        } finally {
            $lock->release();
        }

        $run->refresh();
        $this->assertSame(DeployRun::STATUS_CANCELLED, $run->status);
        $this->assertStringContainsString('lock exclusivo', $run->error_message);
        Process::assertNothingRan();
    }

    public function test_deploy_service_does_not_replace_the_approved_target_when_origin_main_moves(): void
    {
        $approvedTarget = str_repeat('b', 40);
        $newerRemote = str_repeat('c', 40);
        $scriptTargets = [];

        Process::fake(function (PendingProcess $process) use ($approvedTarget, $newerRemote, &$scriptTargets) {
            if (($process->command[0] ?? null) === 'bash') {
                $scriptTargets[] = $process->command[2] ?? null;

                return Process::result('Deploy completed.');
            }

            $command = implode(' ', $process->command ?? []);

            return match ($command) {
                'git rev-parse HEAD' => Process::result($approvedTarget),
                'git rev-parse origin/main' => Process::result($newerRemote),
                default => Process::result(''),
            };
        });

        $run = DeployRun::query()->create([
            'status' => DeployRun::STATUS_PENDING,
            'branch' => 'main',
            'local_commit_before' => str_repeat('a', 40),
            'remote_commit_target' => $approvedTarget,
        ]);

        app(DeployService::class)->runDeploy($run);

        $this->assertSame([$approvedTarget], $scriptTargets);
        $this->assertSame($approvedTarget, $run->refresh()->remote_commit_target);
        Process::assertNotRan(fn (PendingProcess $process) =>
            $process->command === ['git', 'rev-parse', 'origin/main']);
    }

    public function test_deploy_service_claims_a_pending_run_only_once_before_starting_processes(): void
    {
        $target = str_repeat('b', 40);
        $scriptRuns = 0;
        Process::fake(function (PendingProcess $process) use ($target, &$scriptRuns) {
            if (($process->command[0] ?? null) === 'bash') {
                $scriptRuns++;
            }

            return ($process->command ?? []) === ['git', 'rev-parse', 'HEAD']
                ? Process::result($target)
                : Process::result('Deploy completed.');
        });

        $run = DeployRun::query()->create([
            'status' => DeployRun::STATUS_PENDING,
            'branch' => 'main',
            'local_commit_before' => str_repeat('a', 40),
            'remote_commit_target' => $target,
        ]);

        $service = app(DeployService::class);

        $this->assertTrue($service->runDeploy($run));
        $this->assertFalse($service->runDeploy($run));
        $this->assertSame(1, $scriptRuns);
    }

    public function test_deploy_service_marks_process_failures_failed_without_changing_target(): void
    {
        $target = str_repeat('b', 40);
        Process::fake(fn (PendingProcess $process) =>
            ($process->command[0] ?? null) === 'bash'
                ? Process::result(output: 'maintenance enabled', errorOutput: 'composer failed', exitCode: 1)
                : Process::result(str_repeat('a', 40)));

        $run = DeployRun::query()->create([
            'status' => DeployRun::STATUS_PENDING,
            'branch' => 'main',
            'local_commit_before' => str_repeat('a', 40),
            'remote_commit_target' => $target,
        ]);

        try {
            app(DeployService::class)->runDeploy($run);
            $this->fail('Expected the failed deploy process to throw.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('composer failed', $exception->getMessage());
        }

        $run->refresh();
        $this->assertSame(DeployRun::STATUS_FAILED, $run->status);
        $this->assertSame($target, $run->remote_commit_target);
        $this->assertSame(1, $run->exit_code);
        $this->assertStringContainsString('composer failed', File::get(storage_path("logs/deploys/deploy-{$run->id}.log")));
    }

    public function test_deploy_service_fails_closed_when_the_final_sha_does_not_match_target(): void
    {
        $target = str_repeat('b', 40);
        Process::fake(fn (PendingProcess $process) =>
            ($process->command[0] ?? null) === 'bash'
                ? Process::result('Deploy completed.')
                : Process::result(str_repeat('c', 40)));

        $run = DeployRun::query()->create([
            'status' => DeployRun::STATUS_PENDING,
            'branch' => 'main',
            'local_commit_before' => str_repeat('a', 40),
            'remote_commit_target' => $target,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SHA final');

        try {
            app(DeployService::class)->runDeploy($run);
        } finally {
            $this->assertSame(DeployRun::STATUS_FAILED, $run->refresh()->status);
            $this->assertSame(str_repeat('c', 40), $run->local_commit_after);
        }
    }

    public function test_deploy_log_is_restricted_to_super_admins(): void
    {
        $tenantUser = User::factory()->create(['is_super_admin' => false]);
        $superAdmin = User::factory()->create(['is_super_admin' => true]);
        $run = DeployRun::query()->create([
            'status' => DeployRun::STATUS_FAILED,
            'branch' => 'main',
            'output_log_path' => 'deploys/deploy-1.log',
        ]);

        File::ensureDirectoryExists(storage_path('logs/deploys'));
        File::put(storage_path('logs/deploys/deploy-1.log'), 'safe deploy output');

        $this->actingAs($tenantUser)
            ->get(route('super-admin.system.deploy.log', $run))
            ->assertForbidden();

        $this->actingAs($superAdmin)
            ->get(route('super-admin.system.deploy.log', $run))
            ->assertOk()
            ->assertHeader('content-type', 'text/plain; charset=UTF-8');

        $this->assertSame('safe deploy output', File::get(storage_path('logs/deploys/deploy-1.log')));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('logs/deploys'));

        parent::tearDown();
    }

    private function mockStatus(array $overrides = []): void
    {
        $status = array_merge([
            'environment' => 'testing',
            'branch' => 'main',
            'working_tree' => '',
            'is_clean' => true,
            'local_commit' => str_repeat('a', 40),
            'local_commit_subject' => str_repeat('a', 40).' Local commit',
            'remote_commit' => str_repeat('b', 40),
            'remote_commit_subject' => str_repeat('b', 40).' Remote commit',
            'has_pending_updates' => true,
        ], $overrides);

        $service = Mockery::mock(DeployService::class);
        $service->shouldReceive('status')->zeroOrMoreTimes()->andReturn($status);
        $service->shouldReceive('queueRetryWindowIsSafe')->zeroOrMoreTimes()->andReturnUsing(
            fn (): bool => (int) config('queue.connections.'.config('queue.default').'.retry_after', 0)
                > (int) config('deploy.timeout', 900),
        );
        $this->app->instance(DeployService::class, $service);
    }
}
