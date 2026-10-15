<?php

namespace App\Services\System;

use App\Models\DeployRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

class PostgresPreDeployBackupService
{
    /**
     * @return array{path: string, size_bytes: int, created_at: CarbonImmutable, validated_at: CarbonImmutable}
     */
    public function createAndValidate(DeployRun $run, string $targetSha): array
    {
        $configuration = $this->configuration();
        $this->assertTargetSha($targetSha);

        $createdAt = CarbonImmutable::now('UTC');
        $path = $configuration['directory'].DIRECTORY_SEPARATOR.sprintf(
            'blunk-pre-deploy-%s-%s.dump',
            substr(strtolower($targetSha), 0, 7),
            $createdAt->format('Ymd\\THis\\Z'),
        );

        $dump = Process::timeout($configuration['timeout'])
            ->env($this->postgresEnvironment($configuration['connection']))
            ->run([
                $configuration['pg_dump_path'],
                '--format=custom',
                '--no-owner',
                '--no-privileges',
                '--file='.$path,
                '--host='.$configuration['connection']['host'],
                '--port='.$configuration['connection']['port'],
                '--username='.$configuration['connection']['username'],
                '--dbname='.$configuration['connection']['database'],
            ]);

        if (! $dump->successful()) {
            $this->deletePartialDump($path);

            throw new RuntimeException('No se pudo crear el respaldo PostgreSQL previo al despliegue.');
        }

        clearstatcache(true, $path);

        if (! is_file($path) || filesize($path) === false || filesize($path) <= 0) {
            throw new RuntimeException('El respaldo PostgreSQL previo al despliegue no fue creado o está vacío.');
        }

        $validation = Process::timeout($configuration['timeout'])
            ->env($this->postgresEnvironment($configuration['connection']))
            ->run([$configuration['pg_restore_path'], '--list', $path]);

        if (! $validation->successful()) {
            throw new RuntimeException('No se pudo validar el respaldo PostgreSQL previo al despliegue.');
        }

        $validatedAt = CarbonImmutable::now('UTC');
        $sizeBytes = filesize($path);

        if ($sizeBytes === false || $sizeBytes <= 0) {
            throw new RuntimeException('El respaldo PostgreSQL previo al despliegue no es legible.');
        }

        $run->forceFill([
            'backup_path' => $path,
            'backup_size_bytes' => $sizeBytes,
            'backup_created_at' => $createdAt,
            'backup_validated_at' => $validatedAt,
        ])->save();

        try {
            $this->prune($configuration['directory'], $configuration['retention'], $path);
        } catch (Throwable $exception) {
            Log::warning('No se pudo aplicar la retención de respaldos pre-deploy.', [
                'backup_path' => $path,
                'error' => $exception->getMessage(),
            ]);
        }

        return [
            'path' => $path,
            'size_bytes' => $sizeBytes,
            'created_at' => $createdAt,
            'validated_at' => $validatedAt,
        ];
    }

    public function validateConfiguration(): void
    {
        $this->configuration();
    }

    /**
     * @return array{directory: string, pg_dump_path: string, pg_restore_path: string, retention: int, timeout: int, connection: array{host: string, port: string, database: string, username: string, password: string, sslmode: ?string}}
     */
    private function configuration(): array
    {
        $directory = trim((string) config('deploy.backup_directory'));

        if ($directory === '' || ! $this->isAbsolutePath($directory)) {
            throw new RuntimeException('DEPLOY_BACKUP_DIRECTORY debe ser una ruta absoluta fuera del repositorio.');
        }

        File::ensureDirectoryExists($directory);
        $directory = realpath($directory) ?: throw new RuntimeException('No se pudo resolver DEPLOY_BACKUP_DIRECTORY.');
        $appPath = realpath(base_path());

        if ($appPath !== false && ($directory === $appPath || str_starts_with($directory.DIRECTORY_SEPARATOR, $appPath.DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('DEPLOY_BACKUP_DIRECTORY debe estar fuera del repositorio de la aplicación.');
        }

        if (! is_writable($directory)) {
            throw new RuntimeException('DEPLOY_BACKUP_DIRECTORY debe ser escribible por el worker de despliegues.');
        }

        $connection = $this->postgresConnection();

        return [
            'directory' => $directory,
            'pg_dump_path' => $this->configuredExecutable('deploy.pg_dump_path', 'DEPLOY_PG_DUMP_PATH'),
            'pg_restore_path' => $this->configuredExecutable('deploy.pg_restore_path', 'DEPLOY_PG_RESTORE_PATH'),
            'retention' => max(1, (int) config('deploy.backup_retention', 30)),
            'timeout' => max(1, (int) config('deploy.backup_timeout', 900)),
            'connection' => $connection,
        ];
    }

    /**
     * @return array{host: string, port: string, database: string, username: string, password: string, sslmode: ?string}
     */
    private function postgresConnection(): array
    {
        $connection = config('database.connections.'.config('database.default'));

        if (! is_array($connection) || ($connection['driver'] ?? null) !== 'pgsql') {
            throw new RuntimeException('El respaldo automático requiere que la conexión predeterminada de Laravel sea PostgreSQL.');
        }

        $details = [
            'host' => (string) ($connection['host'] ?? ''),
            'port' => (string) ($connection['port'] ?? ''),
            'database' => (string) ($connection['database'] ?? ''),
            'username' => (string) ($connection['username'] ?? ''),
            'password' => (string) ($connection['password'] ?? ''),
            'sslmode' => filled($connection['sslmode'] ?? null) ? (string) $connection['sslmode'] : null,
        ];

        if (filled($connection['url'] ?? null)) {
            $url = parse_url((string) $connection['url']);

            if ($url === false) {
                throw new RuntimeException('La URL de conexión PostgreSQL configurada por Laravel no es válida para el respaldo.');
            }

            $details['host'] = isset($url['host']) ? (string) $url['host'] : $details['host'];
            $details['port'] = isset($url['port']) ? (string) $url['port'] : $details['port'];
            $details['database'] = isset($url['path']) ? ltrim((string) $url['path'], '/') : $details['database'];
            $details['username'] = isset($url['user']) ? rawurldecode((string) $url['user']) : $details['username'];
            $details['password'] = isset($url['pass']) ? rawurldecode((string) $url['pass']) : $details['password'];

            parse_str((string) ($url['query'] ?? ''), $query);
            $details['sslmode'] = isset($query['sslmode']) ? (string) $query['sslmode'] : $details['sslmode'];
        }

        foreach (['host', 'port', 'database', 'username'] as $key) {
            if ($details[$key] === '') {
                throw new RuntimeException('La conexión PostgreSQL configurada por Laravel no tiene '.$key.' para el respaldo.');
            }
        }

        return $details;
    }

    /** @param array{host: string, port: string, database: string, username: string, password: string, sslmode: ?string} $connection */
    private function postgresEnvironment(array $connection): array
    {
        return array_filter([
            'PGPASSWORD' => $connection['password'],
            'PGSSLMODE' => $connection['sslmode'],
        ], static fn (?string $value): bool => $value !== null && $value !== '');
    }

    private function configuredExecutable(string $configKey, string $environmentName): string
    {
        $path = trim((string) config($configKey));

        if ($path === '' || ! $this->isAbsolutePath($path) || ! is_file($path) || ! is_executable($path)) {
            throw new RuntimeException($environmentName.' debe ser una ruta absoluta a un ejecutable disponible.');
        }

        return $path;
    }

    private function assertTargetSha(string $targetSha): void
    {
        if (! preg_match('/^[0-9a-f]{40}$/i', $targetSha)) {
            throw new RuntimeException('El SHA target para el respaldo no es válido.');
        }
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    protected function prune(string $directory, int $retention, string $currentPath): void
    {
        $currentPath = realpath($currentPath) ?: $currentPath;
        $backups = array_values(array_filter(array_map(
            static fn (\SplFileInfo $file): string => $file->getPathname(),
            File::files($directory),
        ), function (string $path): bool {
            return is_file($path) && preg_match('/^blunk-pre-deploy-[0-9a-f]{7,40}-\d{8}T\d{6}Z\.dump$/i', basename($path)) === 1;
        }));

        usort($backups, static function (string $left, string $right): int {
            $timeOrder = filemtime($right) <=> filemtime($left);

            return $timeOrder !== 0 ? $timeOrder : strcmp($right, $left);
        });

        $otherBackups = array_values(array_filter(
            $backups,
            static fn (string $path): bool => (realpath($path) ?: $path) !== $currentPath,
        ));

        foreach (array_slice($otherBackups, max(0, $retention - 1)) as $path) {
            if (! unlink($path)) {
                throw new RuntimeException('No se pudo aplicar la retención de respaldos pre-deploy.');
            }
        }
    }

    private function deletePartialDump(string $path): void
    {
        clearstatcache(true, $path);

        if (is_file($path)) {
            @unlink($path);
        }
    }
}
