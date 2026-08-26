<?php

namespace App\Services\System;

use App\Models\DeployRun;
use Illuminate\Contracts\Process\ProcessResult;
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

    public function runDeploy(DeployRun $run): void
    {
        $scriptPath = (string) config('deploy.script_path');

        if (! is_file($scriptPath)) {
            throw new RuntimeException('El script fijo de actualización no está disponible.');
        }

        File::ensureDirectoryExists((string) config('deploy.log_directory'));
        $relativeLogPath = "deploys/deploy-{$run->id}.log";
        $logPath = storage_path("logs/{$relativeLogPath}");

        $run->forceFill([
            'status' => DeployRun::STATUS_RUNNING,
            'started_at' => now(),
            'output_log_path' => $relativeLogPath,
            'error_message' => null,
            'exit_code' => null,
        ])->save();
        File::put($logPath, '');

        try {
            $before = $this->status();
            $run->forceFill([
                'local_commit_before' => $before['local_commit'],
                'remote_commit_target' => $before['remote_commit'],
            ])->save();

            $result = Process::timeout((int) config('deploy.timeout', 900))
                ->run(['bash', $scriptPath]);

            $this->writeLog($logPath, $result);
            $localCommitAfter = $this->readCommand(['git', 'rev-parse', 'HEAD']);

            $run->forceFill([
                'status' => $result->successful() ? DeployRun::STATUS_SUCCEEDED : DeployRun::STATUS_FAILED,
                'finished_at' => now(),
                'local_commit_after' => $localCommitAfter,
                'exit_code' => $result->exitCode(),
                'error_message' => $result->successful() ? null : $this->readableError($result),
            ])->save();

            if (! $result->successful()) {
                throw new RuntimeException($run->error_message ?: 'La actualización terminó con error.');
            }
        } catch (Throwable $exception) {
            File::append($logPath, '[error] '.$exception->getMessage().PHP_EOL);

            $run->forceFill([
                'status' => DeployRun::STATUS_FAILED,
                'finished_at' => now(),
                'error_message' => $exception->getMessage(),
            ])->save();

            throw $exception;
        }
    }

    public function logPath(DeployRun $run): ?string
    {
        $relativePath = (string) $run->output_log_path;

        if (! preg_match('/^deploys\/deploy-\d+\.log$/', $relativePath)) {
            return null;
        }

        return storage_path("logs/{$relativePath}");
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
}
