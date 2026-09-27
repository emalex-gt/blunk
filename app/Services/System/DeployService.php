<?php

namespace App\Services\System;

use App\Models\DeployRun;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

class DeployService
{
    public function status(): array
    {
        $branch = $this->readCommand(['git', 'branch', '--show-current']);
        $workingTree = $this->readCommand(['git', 'status', '--short']);
        $localCommit = $this->readCommand(['git', 'rev-parse', 'HEAD']);
        $this->readCommand(['git', 'fetch', 'origin']);
        $remoteCommit = $this->readCommand(['git', 'rev-parse', 'origin/main']);

        return [
            'environment' => app()->environment(),
            'branch' => $branch,
            'working_tree' => $workingTree,
            'is_clean' => $workingTree === '',
            'local_commit' => $localCommit,
            'local_commit_subject' => $this->readCommand(['git', 'log', '--oneline', '-1', 'HEAD']),
            'remote_commit' => $remoteCommit,
            'remote_commit_subject' => $this->readCommand(['git', 'log', '--oneline', '-1', 'origin/main']),
            'has_pending_updates' => $localCommit !== $remoteCommit,
        ];
    }

    public function runDeploy(DeployRun $run): bool
    {
        $lock = Cache::lock(
            'system:production-deploy:execution',
            (int) config('deploy.lock_seconds', 1200),
        );

        if (! $lock->get()) {
            $freshRun = DeployRun::query()->find($run->id);

            if ($freshRun?->status === DeployRun::STATUS_PENDING) {
                $freshRun->forceFill([
                    'status' => DeployRun::STATUS_CANCELLED,
                    'finished_at' => now(),
                    'error_message' => 'La ejecución fue cancelada porque otro despliegue ya posee el lock exclusivo.',
                ])->save();
            }

            return false;
        }

        try {
            return $this->runClaimedDeploy($run);
        } finally {
            $lock->release();
        }
    }

    public function queueRetryWindowIsSafe(): bool
    {
        $connection = (string) config('queue.default');
        $retryAfter = config("queue.connections.{$connection}.retry_after");

        return is_numeric($retryAfter)
            && (int) $retryAfter > (int) config('deploy.timeout', 900);
    }

    public function logPath(DeployRun $run): ?string
    {
        $relativePath = (string) $run->output_log_path;

        if (! preg_match('/^deploys\/deploy-\d+\.log$/', $relativePath)) {
            return null;
        }

        return storage_path("logs/{$relativePath}");
    }

    private function runClaimedDeploy(DeployRun $run): bool
    {
        $relativeLogPath = "deploys/deploy-{$run->id}.log";

        $claimed = DeployRun::query()
            ->whereKey($run->id)
            ->where('status', DeployRun::STATUS_PENDING)
            ->update([
            'status' => DeployRun::STATUS_RUNNING,
            'started_at' => now(),
            'output_log_path' => $relativeLogPath,
            'error_message' => null,
            'exit_code' => null,
            'updated_at' => now(),
        ]);

        if ($claimed !== 1) {
            return false;
        }

        $run->refresh();
        $logPath = storage_path("logs/{$relativeLogPath}");

        try {
            File::ensureDirectoryExists((string) config('deploy.log_directory'));
            File::put($logPath, '');
            $this->assertExecutionConfiguration($run);
            $scriptPath = (string) config('deploy.script_path');
            $target = (string) $run->remote_commit_target;

            $result = Process::timeout((int) config('deploy.timeout', 900))
                ->env($this->processEnvironment())
                ->run(['bash', $scriptPath, $target]);

            $this->writeLog($logPath, $result);
            $localCommitAfter = $this->tryReadCurrentCommit();
            $successful = $result->successful() && hash_equals($target, (string) $localCommitAfter);
            $errorMessage = null;

            if (! $result->successful()) {
                $errorMessage = $this->readableError($result);
            } elseif (! $localCommitAfter || ! hash_equals($target, $localCommitAfter)) {
                $errorMessage = 'El SHA final no coincide con el target aprobado.';
                File::append($logPath, '[error] '.$errorMessage.PHP_EOL);
            }

            $run->forceFill([
                'status' => $successful ? DeployRun::STATUS_SUCCEEDED : DeployRun::STATUS_FAILED,
                'finished_at' => now(),
                'local_commit_after' => $localCommitAfter,
                'exit_code' => $result->exitCode(),
                'error_message' => $errorMessage,
            ])->save();

            if (! $successful) {
                throw new RuntimeException($run->error_message ?: 'La actualización terminó con error.');
            }

            return true;
        } catch (Throwable $exception) {
            try {
                if (File::isDirectory(dirname($logPath))) {
                    File::append($logPath, '[error] '.$exception->getMessage().PHP_EOL);
                }
            } catch (Throwable) {
                // The database state remains authoritative if the log path itself failed.
            }

            $run->forceFill([
                'status' => DeployRun::STATUS_FAILED,
                'finished_at' => now(),
                'error_message' => $exception->getMessage(),
            ])->save();

            throw $exception;
        }
    }

    private function assertExecutionConfiguration(DeployRun $run): void
    {
        if (! $this->queueRetryWindowIsSafe()) {
            throw new RuntimeException('DB_QUEUE_RETRY_AFTER debe ser mayor que el timeout del job de despliegue.');
        }

        if (! preg_match('/^[0-9a-f]{40}$/i', (string) $run->remote_commit_target)) {
            throw new RuntimeException('El target SHA aprobado no es válido.');
        }

        if (! is_file((string) config('deploy.script_path'))) {
            throw new RuntimeException('El script fijo de actualización no está disponible.');
        }
    }

    private function processEnvironment(): array
    {
        return array_filter([
            'DEPLOY_APP_PATH' => base_path(),
            'DEPLOY_HOME' => (string) config('deploy.home', ''),
            'DEPLOY_COMPOSER_HOME' => (string) config('deploy.composer_home', ''),
            'DEPLOY_COMPOSER_PATH' => (string) config('deploy.composer_path', ''),
            'DEPLOY_PAUSE_WORKERS_HOOK' => (string) config('deploy.pause_workers_hook', ''),
            'DEPLOY_RESUME_WORKERS_HOOK' => (string) config('deploy.resume_workers_hook', ''),
        ], fn (string $value): bool => $value !== '');
    }

    private function readCommand(array $command): string
    {
        $result = Process::path(base_path())->timeout(30)->run($command);

        if (! $result->successful()) {
            throw new RuntimeException('No se pudo consultar el estado del checkout de producción.');
        }

        return trim($result->output());
    }

    private function writeLog(string $logPath, ProcessResult $result): void
    {
        $output = $result->output();
        $errorOutput = $result->errorOutput();
        $content = $output;

        if ($errorOutput !== '') {
            $content .= ($content === '' || str_ends_with($content, PHP_EOL) ? '' : PHP_EOL)
                ."[stderr]{$errorOutput}";
        }

        File::put($logPath, $content);
    }

    private function readableError(ProcessResult $result): string
    {
        $message = trim($result->errorOutput() ?: $result->output());

        return $message !== '' ? mb_substr($message, 0, 4000) : 'La actualización terminó con error.';
    }

    private function tryReadCurrentCommit(): ?string
    {
        try {
            return $this->readCommand(['git', 'rev-parse', 'HEAD']);
        } catch (Throwable) {
            return null;
        }
    }
}
