<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Jobs\RunProductionDeployJob;
use App\Models\DeployRun;
use App\Services\System\DeployService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class DeployController extends Controller
{
    public function __construct(private readonly DeployService $service)
    {
    }

    public function index(): Response
    {
        return Inertia::render('SuperAdmin/System/Deploy', $this->pageProps());
    }

    public function check(): RedirectResponse
    {
        return back();
    }

    public function run(Request $request): RedirectResponse
    {
        $request->validate([
            'confirmation' => ['required', 'string', 'in:ACTUALIZAR'],
        ], [
            'confirmation.in' => 'Escribe ACTUALIZAR para confirmar la actualización.',
        ]);

        if (! config('deploy.queue_worker_enabled')) {
            throw ValidationException::withMessages([
                'deploy' => 'La actualización está bloqueada hasta confirmar Supervisor y habilitar el worker de despliegues.',
            ]);
        }

        $rateKey = 'production-deploy:'.(int) $request->user()->id;

        if (RateLimiter::tooManyAttempts($rateKey, 3)) {
            throw ValidationException::withMessages([
                'deploy' => 'Se alcanzó el límite de intentos de actualización. Intenta nuevamente más tarde.',
            ]);
        }

        $status = $this->service->status();

        if (($status['branch'] ?? null) !== 'main') {
            throw ValidationException::withMessages(['deploy' => 'La actualización requiere que el checkout esté en main.']);
        }

        if (! ($status['is_clean'] ?? false)) {
            throw ValidationException::withMessages(['deploy' => 'La actualización requiere un working tree limpio.']);
        }

        $lock = Cache::lock('system:production-deploy:dispatch', 10);

        if (! $lock->get()) {
            throw ValidationException::withMessages(['deploy' => 'Ya se está registrando una actualización. Intenta nuevamente.']);
        }

        try {
            if (DeployRun::query()->active()->exists()) {
                throw ValidationException::withMessages(['deploy' => 'Ya hay una actualización en curso.']);
            }

            $run = DeployRun::query()->create([
                'user_id' => $request->user()->id,
                'status' => DeployRun::STATUS_PENDING,
                'branch' => 'main',
                'local_commit_before' => $status['local_commit'] ?? null,
                'remote_commit_target' => $status['remote_commit'] ?? null,
            ]);

            RunProductionDeployJob::dispatch($run->id);
            RateLimiter::hit($rateKey, 3600);
        } finally {
            $lock->release();
        }

        return redirect()
            ->route('super-admin.system.deploy.index')
            ->with('success', 'Actualización registrada en la cola deploys.');
    }

    public function show(DeployRun $deployRun): Response
    {
        return Inertia::render('SuperAdmin/System/Deploy', $this->pageProps($deployRun));
    }

    public function log(DeployRun $deployRun)
    {
        $path = $this->service->logPath($deployRun);
        abort_unless($path && File::exists($path), 404);

        return response()->file($path, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => "inline; filename=deploy-{$deployRun->id}.log",
        ]);
    }

    private function pageProps(?DeployRun $selectedRun = null): array
    {
        try {
            $status = $this->service->status();
            $statusError = null;
        } catch (Throwable) {
            $status = null;
            $statusError = 'No se pudo consultar el estado del checkout. Revisa Git y los permisos del usuario web.';
        }

        $history = DeployRun::query()
            ->with('user:id,name')
            ->latest()
            ->limit(25)
            ->get()
            ->map(fn (DeployRun $run) => $this->runPayload($run))
            ->all();

        $activeRun = DeployRun::query()->active()->latest()->first();

        return [
            'status' => $status,
            'statusError' => $statusError,
            'queueConnection' => (string) config('queue.default'),
            'queueWorkerEnabled' => (bool) config('deploy.queue_worker_enabled'),
            'activeDeploy' => $activeRun ? $this->runPayload($activeRun) : null,
            'history' => $history,
            'selectedRun' => $selectedRun ? $this->runPayload($selectedRun) : null,
        ];
    }

    private function runPayload(DeployRun $run): array
    {
        return [
            'id' => $run->id,
            'status' => $run->status,
            'branch' => $run->branch,
            'user' => $run->user ? ['id' => $run->user->id, 'name' => $run->user->name] : null,
            'started_at' => $run->started_at?->format('Y-m-d H:i:s'),
            'finished_at' => $run->finished_at?->format('Y-m-d H:i:s'),
            'created_at' => $run->created_at?->format('Y-m-d H:i:s'),
            'local_commit_before' => $run->local_commit_before,
            'remote_commit_target' => $run->remote_commit_target,
            'local_commit_after' => $run->local_commit_after,
            'exit_code' => $run->exit_code,
            'error_message' => $run->error_message,
            'show_url' => route('super-admin.system.deploy.show', $run),
            'log_url' => $run->output_log_path ? route('super-admin.system.deploy.log', $run) : null,
        ];
    }
}
