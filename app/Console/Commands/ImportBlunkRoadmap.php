<?php

namespace App\Console\Commands;

use App\Models\DevelopmentTask;
use App\Services\DevelopmentTaskService;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;

class ImportBlunkRoadmap extends Command
{
    protected $signature = 'blunk:roadmap-import {--dry-run : Simular sin escribir (predeterminado)} {--apply : Crear las tareas ausentes}';
    protected $description = 'Importa el inventario inicial de Roadmap sin sobrescribir tareas existentes';

    public function handle(DevelopmentTaskService $service): int
    {
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('Elige --dry-run o --apply, no ambos.');
            return self::INVALID;
        }
        $apply = (bool) $this->option('apply');
        $rows = require database_path('seeders/data/blunk_roadmap.php');
        $created = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            [$key, $title, $module] = $row;
            $source = ['source_reference' => 'blunk-roadmap-1.0', 'source_key' => $key];
            if (DevelopmentTask::query()->where($source)->exists()) { $skipped++; continue; }
            $implementation = $row[3] ?? 'not_started';
            $evidence = $row[4] ?? 'Sin evidencia suficiente en esta revisión acotada. Verificar el repositorio antes de iniciar desarrollo; «No iniciado» es provisional.';
            $type = str_contains(mb_strtolower($title), 'auditoría') ? 'audit' : ($module === 'Migraciones' ? 'migration' : 'improvement');
            $data = [...$source, 'title' => $title, 'module' => $module, 'client_name' => $row[5] ?? 'Blunk Core / General',
                'task_type' => $type, 'priority' => 'P2', 'sort_order' => 0,
                'work_status' => $implementation === 'not_started' ? 'pending' : 'in_review',
                'implementation_status' => $implementation, 'production_status' => 'unverified',
                'technical_notes' => $evidence, 'description' => 'Inventario inicial Blunk Roadmap 1.0. Prioridad provisional: revisar en triaje.',
                'acceptance_criteria' => 'Confirmar alcance y evidencia en repositorio. Registrar comprobación de despliegue y validación operativa antes del cierre.',
                'checklist' => [['title' => 'Verificar alcance y estado real en repositorio', 'is_completed' => false], ['title' => 'Verificar despliegue y aceptación en producción', 'is_completed' => false]],
            ];
            if ($apply) {
                try { $service->save($data, null); }
                catch (UniqueConstraintViolationException $exception) {
                    // A concurrent importer may have inserted the same stable source key.
                    if (!DevelopmentTask::query()->where($source)->exists()) { throw $exception; }
                    $skipped++;
                    continue;
                }
            }
            $created++;
            $this->line(($apply ? 'CREADA' : 'CREARÍA')." [{$key}] {$title} ({$implementation}, sin verificar producción)");
        }
        $this->info(($apply ? 'Aplicado' : 'SIMULACIÓN, sin escrituras').": {$created} nuevas, {$skipped} existentes omitidas.");
        return self::SUCCESS;
    }
}
