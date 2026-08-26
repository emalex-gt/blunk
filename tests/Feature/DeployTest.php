<?php

namespace Tests\Feature;

use App\Jobs\RunProductionDeployJob;
use App\Models\DeployRun;
use App\Models\User;
use App\Services\System\DeployService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
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

    public function test_deploy_service_parses_status_and_runs_only_the_fixed_script(): void
    {
        config(['deploy.script_path' => base_path('scripts/deploy-production.sh')]);
        Process::fake(function (PendingProcess $process) {
            $command = implode(' ', $process->command ?? []);

            return match ($command) {
                'git branch --show-current' => Process::result('main'),
                'git status --short', 'git fetch origin' => Process::result(''),
                'git rev-parse HEAD' => Process::result('local-sha'),
                'git rev-parse origin/main' => Process::result('remote-sha'),
                'git log --oneline -1 HEAD' => Process::result('local-sha Local commit'),
                'git log --oneline -1 origin/main' => Process::result('remote-sha Remote commit'),
                default => Process::result('Deploy completed.'),
            };
        });

        $service = app(DeployService::class);
        $status = $service->status();
        $run = DeployRun::query()->create(['status' => DeployRun::STATUS_PENDING, 'branch' => 'main']);
        $service->runDeploy($run);

        $this->assertTrue($status['is_clean']);
        $this->assertTrue($status['has_pending_updates']);
        $this->assertSame(DeployRun::STATUS_SUCCEEDED, $run->refresh()->status);
        Process::assertRan(fn (PendingProcess $process) => $process->command === ['bash', config('deploy.script_path')]);
        $this->assertFileExists(storage_path("logs/deploys/deploy-{$run->id}.log"));
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
            'local_commit' => 'local-sha',
            'local_commit_subject' => 'local-sha Local commit',
            'remote_commit' => 'remote-sha',
            'remote_commit_subject' => 'remote-sha Remote commit',
            'has_pending_updates' => true,
        ], $overrides);

        $service = Mockery::mock(DeployService::class);
        $service->shouldReceive('status')->zeroOrMoreTimes()->andReturn($status);
        $this->app->instance(DeployService::class, $service);
    }
}
