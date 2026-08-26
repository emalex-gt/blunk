# Internal Production Deploy Design

## Goal

Provide a Super Admin-only internal deployment screen that can inspect the local
production checkout and, only after server-side queue-worker enablement, dispatch a
fixed deployment job. It is an alternative to GitHub Actions, not an arbitrary command
terminal and not a synchronous web request.

## Scope And Constraints

- Only authenticated `is_super_admin` users can access every deploy route and log.
- The web request never executes a deploy command. It creates an auditable `deploy_runs`
  record and dispatches `RunProductionDeployJob` to the `deploys` queue.
- `DEPLOY_QUEUE_WORKER_ENABLED` defaults to `false`. When false, status, history, and
  logs remain available, but the server rejects new deploy requests.
- No request field is used as a shell command, branch, path, PHP binary, or argument.
- The only execution command is `bash scripts/deploy-production.sh`.
- The fixed shell script targets `/home/kodbli-v2/htdocs/v2.kodbli.app`, requires a
  clean `main` checkout, uses `php8.5`, and restores application availability through
  an EXIT trap after maintenance mode begins.
- No production deploy is run during development or tests. POS and business workflows
  are outside scope.

## Configuration And Worker Gate

`config/deploy.php` exposes:

```php
'queue_worker_enabled' => (bool) env('DEPLOY_QUEUE_WORKER_ENABLED', false),
'queue' => 'deploys',
'script_path' => base_path('scripts/deploy-production.sh'),
'log_directory' => storage_path('logs/deploys'),
```

The setting is deliberately environment controlled. A Super Admin cannot enable it in
the UI. Production may set `DEPLOY_QUEUE_WORKER_ENABLED=true` only after Supervisor is
running the documented command and has processed a harmless queued job successfully.

## Persistence

`deploy_runs` records every accepted request and execution result:

- actor (`user_id`, nullable for future CLI use), `status`, and timestamps;
- `branch` fixed to `main`;
- local commit before, remote target commit, and local commit after;
- output log relative path, error message, and process exit code.

Status is one of `pending`, `running`, `succeeded`, `failed`, or `cancelled`. A database
query for `pending|running` serializes requests: only one deploy can exist in either
state at a time.

## Service And Job

`DeployService::status()` runs only a fixed allowlist of read-only git commands through
Symfony Process: branch, working-tree status, local/remote revisions, and one-line
commit subjects. `git fetch origin` is the only status command that writes Git metadata.

`DeployService::runDeploy()` creates `storage/logs/deploys` if necessary and invokes
only `bash scripts/deploy-production.sh`, with a 900-second timeout. Output is captured
in `deploy-{id}.log`; status, timestamps, exit code, error, and revisions are persisted
in a finally block.

`RunProductionDeployJob` uses `ShouldQueue`, `public $queue = 'deploys'`,
`public $timeout = 900`, and `public $tries = 1`. Its `failed()` method marks an
unfinished run as failed. It has no automatic retry configuration.

## HTTP And UI Flow

All `/super-admin/system/deploy*` routes use `auth` and `super.admin` middleware.
The controller validates the literal confirmation `ACTUALIZAR`, configuration gate,
clean checkout, and absence of a pending/running run before it creates a run and
dispatches the fixed job. The check endpoint refreshes display-only status. The log
endpoint streams or returns only the run's file and only to Super Admin users.

The Inertia page displays environment, `QUEUE_CONNECTION`,
`DEPLOY_QUEUE_WORKER_ENABLED`, current running deploy, branch, local/remote commits,
working tree state, update availability, history, and a monospace deploy-log view. It
requires typed confirmation in a modal. When the queue gate is false, the action is
disabled with the Supervisor prerequisite; it cannot be bypassed from the browser.

## Supervisor Prerequisite

Production must run a persistent worker under Supervisor before enabling the environment
gate:

```bash
php8.5 artisan queue:work database --queue=deploys,default --sleep=3 --tries=1 --timeout=900
```

The worker's operating-system user must be able to run `git`, `composer`, `npm`,
`php8.5`, and the fixed script in the application directory, and write to
`storage/logs/deploys`. No sudo rule or browser-supplied command is permitted.

## Verification

Feature tests cover Super Admin access, typed confirmation, queue gate, clean checkout,
duplicate-running protection, run creation/dispatch, status parsing, log authorization,
and the absence of any request-provided command execution. Process execution is mocked;
no test starts the shell script. Run the focused Deploy tests, the full Laravel suite,
the Vite build, and `git diff --check`.
