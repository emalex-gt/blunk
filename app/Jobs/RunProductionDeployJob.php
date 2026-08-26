<?php

namespace App\Jobs;

use App\Models\DeployRun;
use App\Services\System\DeployService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class RunProductionDeployJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;
    public int $tries = 1;

    public function __construct(public readonly int $deployRunId)
    {
        $this->onQueue((string) config('deploy.queue', 'deploys'));
    }

    public function handle(DeployService $service): void
    {
        $run = DeployRun::query()->findOrFail($this->deployRunId);

        if ($run->status !== DeployRun::STATUS_PENDING) {
            return;
        }

        if (! config('deploy.queue_worker_enabled')) {
            $run->forceFill([
                'status' => DeployRun::STATUS_CANCELLED,
                'finished_at' => now(),
                'error_message' => 'La ejecución fue bloqueada porque el worker de despliegues no está habilitado.',
            ])->save();

            return;
        }

        $service->runDeploy($run);
    }

    public function failed(?Throwable $exception): void
    {
        DeployRun::query()
            ->whereKey($this->deployRunId)
            ->whereNotIn('status', [DeployRun::STATUS_SUCCEEDED, DeployRun::STATUS_FAILED])
            ->update([
                'status' => DeployRun::STATUS_FAILED,
                'finished_at' => now(),
                'error_message' => $exception?->getMessage() ?: 'El job de actualización no pudo completarse.',
                'updated_at' => now(),
            ]);
    }
}
