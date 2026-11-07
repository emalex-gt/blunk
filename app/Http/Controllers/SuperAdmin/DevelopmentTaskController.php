<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\DevelopmentTaskRequest;
use App\Models\{Business, DevelopmentTask, User};
use App\Services\DevelopmentTaskService;
use App\Support\DevelopmentTaskOptions;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class DevelopmentTaskController extends Controller
{
    public function index(Request $request)
    {
        $rules = ['search' => ['nullable', 'string', 'max:200'], 'client_name' => ['nullable', 'string', 'max:160'], 'assigned_to' => ['nullable', 'integer'], 'archived' => ['nullable', Rule::in(['active', 'archived', 'all'])], 'view' => ['nullable', Rule::in(['dashboard', 'list', 'kanban'])], 'order' => ['nullable', Rule::in(['priority', 'manual'])]];
        foreach (DevelopmentTaskOptions::all() as $field => $options) { $rules[$field] = ['nullable', Rule::in(array_keys($options))]; }
        $filters = $request->validate($rules);
        $query = DevelopmentTask::query();
        $archived = $filters['archived'] ?? 'active';
        if ($archived === 'active') { $query->whereNull('archived_at'); }
        if ($archived === 'archived') { $query->whereNotNull('archived_at'); }
        foreach ([...array_keys(DevelopmentTaskOptions::all()), 'client_name', 'assigned_to'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') { $query->where($field, $filters[$field]); }
        }
        if (!empty($filters['search'])) {
            $needle = '%'.mb_strtolower($filters['search']).'%';
            $query->where(fn ($q) => $q->whereRaw('LOWER(title) LIKE ?', [$needle])->orWhereRaw('LOWER(code) LIKE ?', [$needle])->orWhereRaw('LOWER(description) LIKE ?', [$needle]));
        }
        $counts = ['total' => (clone $query)->count()];
        foreach (array_keys(DevelopmentTaskOptions::all()['work_status']) as $status) { $counts[$status] = (clone $query)->where('work_status', $status)->count(); }
        foreach (['P0', 'P1'] as $priority) { $counts[$priority] = (clone $query)->where('priority', $priority)->where('work_status', '!=', 'closed')->count(); }
        $counts['awaiting_deploy'] = (clone $query)->where('implementation_status', 'implemented')->where('production_status', 'not_deployed')->count();
        $counts['awaiting_validation'] = (clone $query)->where('production_status', 'deployed')->count();
        $columns = ['id', 'code', 'title', 'priority', 'work_status', 'implementation_status', 'production_status', 'module', 'client_name', 'assigned_to', 'sort_order', 'updated_at', 'archived_at'];
        $cards = fn ($q) => $q->select($columns)->with('assignee:id,name');
        $ordered = $cards(clone $query);
        if (($filters['order'] ?? 'priority') === 'priority') { $ordered->orderBy('priority'); }
        $ordered->orderBy('sort_order')->orderBy('id');
        $view = $filters['view'] ?? 'dashboard';
        $kanban = [];
        if ($view === 'kanban') {
            foreach (array_keys(DevelopmentTaskOptions::all()['work_status']) as $status) {
                $kanban[$status] = (clone $ordered)->where('work_status', $status)->paginate(12, ['*'], 'page_'.$status)->withQueryString();
            }
        }
        return Inertia::render('SuperAdmin/Development/Index', [
            ...$this->options(), 'filters' => $filters, 'counts' => $counts, 'view' => $view,
            'tasks' => $view === 'list' ? $ordered->paginate(25)->withQueryString() : null,
            'kanban' => $kanban,
            'critical' => $view === 'dashboard' ? $cards(clone $query)->whereIn('priority', ['P0', 'P1'])->where('work_status', '!=', 'closed')->orderBy('priority')->orderBy('sort_order')->orderBy('id')->limit(8)->get() : [],
            'recent' => $view === 'dashboard' ? $cards(clone $query)->latest('updated_at')->orderByDesc('id')->limit(8)->get() : [],
        ]);
    }

    public function create() { return Inertia::render('SuperAdmin/Development/Form', [...$this->options(), 'task' => null, 'activities' => null]); }

    public function show(DevelopmentTask $task)
    {
        return Inertia::render('SuperAdmin/Development/Form', [...$this->options(), 'task' => $task->load(['checklist', 'assignee:id,name', 'business:id,name']), 'activities' => $task->activities()->with('user:id,name')->orderByDesc('id')->paginate(20)->withQueryString()]);
    }

    public function store(DevelopmentTaskRequest $request, DevelopmentTaskService $service)
    {
        $task = $service->save($request->validated(), $request->user()->id);
        return to_route('super-admin.development.tasks.show', $task)->with('success', 'Tarea creada.');
    }

    public function update(DevelopmentTaskRequest $request, DevelopmentTask $task, DevelopmentTaskService $service)
    {
        $service->save($request->validated(), $request->user()->id, $task);
        return back()->with('success', 'Tarea actualizada.');
    }

    public function archive(Request $request, DevelopmentTask $task, DevelopmentTaskService $service)
    {
        $data = $request->validate(['archived' => ['required', 'boolean'], 'version' => ['required', 'integer', 'min:1']]);
        $service->archive($task, $data['archived'], $data['version'], $request->user()->id);
        return back()->with('success', $data['archived'] ? 'Tarea archivada.' : 'Tarea restaurada.');
    }

    public function comment(Request $request, DevelopmentTask $task)
    {
        $data = $request->validate(['comment' => ['required', 'string', 'max:5000']]);
        $task->activities()->create([...$data, 'activity_type' => 'comment', 'user_id' => $request->user()->id]);
        return back()->with('success', 'Comentario registrado.');
    }

    private function options(): array
    {
        return ['options' => DevelopmentTaskOptions::all(), 'users' => User::query()->where('is_super_admin', true)->orderBy('name')->get(['id', 'name']), 'businesses' => Business::query()->orderBy('name')->get(['id', 'name']),
            'clients' => collect(['Blunk Core / General', 'Ferrymas', 'MYJ', 'Don Panchito'])->merge(DevelopmentTask::query()->whereNotNull('client_name')->distinct()->pluck('client_name'))->unique()->sort()->values()];
    }
}
