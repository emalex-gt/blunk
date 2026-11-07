<?php

namespace App\Services;

use App\Models\DevelopmentTask;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DevelopmentTaskService
{
    // Only classification and references are copied to the history, never free-text bodies.
    private const AUDITED = ['title', 'priority', 'task_type', 'work_status', 'implementation_status', 'production_status', 'module', 'client_name', 'business_id', 'assigned_to', 'sort_order', 'github_branch', 'github_commit'];

    public function save(array $data, ?int $userId, ?DevelopmentTask $existing = null): DevelopmentTask
    {
        return DB::transaction(function () use ($data, $userId, $existing) {
            $task = $existing ? DevelopmentTask::query()->lockForUpdate()->findOrFail($existing->id) : new DevelopmentTask;
            if ($existing) {
                $this->checkVersion($task, (int) ($data['version'] ?? 0));
                if ($task->archived_at) {
                    throw ValidationException::withMessages(['version' => 'Restaura la tarea antes de editarla.']);
                }
            }
            $before = $task->only(self::AUDITED);
            $checklist = $data['checklist'] ?? [];
            $task->fill(Arr::except($data, ['checklist', 'version']));
            foreach (['completed_at' => ['work_status', ['closed']], 'deployed_at' => ['production_status', ['deployed', 'validated']], 'production_verified_at' => ['production_status', ['validated']]] as $date => [$status, $values]) {
                $task->$date = in_array($task->$status, $values, true) ? ($task->$date ?? now()) : null;
            }
            $changedFields = array_keys($task->getDirty());
            $task->updated_by = $userId;
            if (!$existing) { $task->created_by = $userId; }
            $task->version = $existing ? $task->version + 1 : 1;
            $task->save();
            $oldItems = $task->checklist()->get()->keyBy('id');
            $checklistBefore = $oldItems->map(fn ($item) => $item->only(['id', 'title', 'is_completed', 'sort_order']))->values()->all();
            $kept = [];
            $checklistChanged = count($checklist) !== $oldItems->count();
            foreach ($checklist as $order => $item) {
                $row = !empty($item['id']) ? $oldItems->get($item['id']) : null;
                if (!empty($item['id']) && !$row) {
                    throw ValidationException::withMessages(['checklist' => 'Subtarea inválida para esta tarea.']);
                }
                $row ??= $task->checklist()->make();
                $row->fill(['title' => $item['title'], 'is_completed' => $item['is_completed'], 'sort_order' => $order,
                    'completed_at' => $item['is_completed'] ? ($row->completed_at ?? now()) : null]);
                $checklistChanged = $checklistChanged || $row->isDirty();
                $row->save();
                $kept[] = $row->id;
            }
            $task->checklist()->whereNotIn('id', $kept)->delete();
            $after = $task->only(self::AUDITED);
            $keys = array_keys(array_filter($after, fn ($value, $key) => ($before[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH));
            $task->activities()->create([
                'user_id' => $userId, 'activity_type' => $existing ? 'updated' : 'created',
                'previous_values' => $existing ? [...Arr::only($before, $keys), ...($checklistChanged ? ['checklist' => $checklistBefore] : [])] : null,
                'new_values' => [...Arr::only($after, $keys), 'changed_fields' => $changedFields, 'checklist_changed' => $checklistChanged,
                    ...($checklistChanged ? ['checklist' => $task->checklist()->get(['id', 'title', 'is_completed', 'sort_order'])->toArray()] : [])],
            ]);
            return $task;
        });
    }

    public function archive(DevelopmentTask $task, bool $archive, int $version, int $userId): void
    {
        DB::transaction(function () use ($task, $archive, $version, $userId) {
            $locked = DevelopmentTask::query()->lockForUpdate()->findOrFail($task->id);
            $this->checkVersion($locked, $version);
            $locked->update(['archived_at' => $archive ? now() : null, 'updated_by' => $userId, 'version' => $locked->version + 1]);
            $locked->activities()->create(['user_id' => $userId, 'activity_type' => $archive ? 'archived' : 'restored']);
        });
    }

    private function checkVersion(DevelopmentTask $task, int $version): void
    {
        if ($task->version !== $version) {
            throw ValidationException::withMessages(['version' => 'Otra sesión modificó esta tarea. Recarga la página antes de guardar.']);
        }
    }
}
