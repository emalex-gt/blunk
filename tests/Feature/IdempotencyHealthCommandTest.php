<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\OperationIdempotencyKey;
use App\Models\User;
use App\Support\IdempotencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class IdempotencyHealthCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_health_reports_statuses_by_operation_type(): void
    {
        [$business, $branch, $user] = $this->tenant();
        $this->key($business, $branch, $user, ['operation_type' => 'pos_sale', 'status' => 'completed', 'replay_count' => 2]);
        $this->key($business, $branch, $user, ['operation_type' => 'purchase_store', 'status' => 'processing']);
        $this->key($business, $branch, $user, ['operation_type' => 'purchase_store', 'status' => 'failed', 'last_error' => 'Proveedor no disponible']);

        $this->artisan('system:idempotency-health', ['--business' => $business->id, '--hours' => 24, '--verbose' => true])
            ->expectsOutputToContain('keys_totales: 3')
            ->expectsOutputToContain('completed: 1')
            ->expectsOutputToContain('processing: 1')
            ->expectsOutputToContain('failed: 1')
            ->expectsOutputToContain('pos_sale: completed=1')
            ->expectsOutputToContain('purchase_store: completed=0')
            ->assertExitCode(1);
    }

    public function test_health_detects_stale_processing_but_not_recent_processing(): void
    {
        [$business, $branch, $user] = $this->tenant();
        $this->key($business, $branch, $user, [
            'operation_type' => 'pos_sale',
            'status' => 'processing',
            'locked_at' => now()->subMinutes(31),
        ]);
        $this->key($business, $branch, $user, [
            'operation_type' => 'purchase_store',
            'status' => 'processing',
            'locked_at' => now()->subMinutes(5),
        ]);

        $this->artisan('system:idempotency-health', ['--business' => $business->id, '--stale-minutes' => 10])
            ->expectsOutputToContain('processing_atrasados: 1')
            ->assertExitCode(1);
    }

    public function test_health_writes_csv_reports_and_scopes_to_the_requested_business(): void
    {
        [$business, $branch, $user] = $this->tenant('Principal');
        [$otherBusiness, $otherBranch, $otherUser] = $this->tenant('Otro negocio');
        $this->key($business, $branch, $user, ['status' => 'completed', 'operation_type' => 'pos_sale']);
        $this->key($otherBusiness, $otherBranch, $otherUser, ['status' => 'failed', 'operation_type' => 'purchase_store']);

        $this->artisan('system:idempotency-health', ['--business' => $business->id, '--report' => true])
            ->expectsOutputToContain('keys_totales: 1')
            ->expectsOutputToContain('reporte: ')
            ->assertExitCode(0);

        $files = Storage::disk('local')->allFiles('idempotency-health');
        $this->assertTrue(collect($files)->contains(fn (string $path) => str_ends_with($path, 'summary.csv')));
        $this->assertTrue(collect($files)->contains(fn (string $path) => str_ends_with($path, 'idempotency_summary_by_operation.csv')));
        $this->assertTrue(collect($files)->contains(fn (string $path) => str_ends_with($path, 'stale_processing_keys.csv')));
        $this->assertTrue(collect($files)->contains(fn (string $path) => str_ends_with($path, 'failed_idempotency_keys.csv')));
    }

    public function test_prune_dry_run_does_not_delete_and_confirm_only_deletes_old_terminal_keys(): void
    {
        [$business, $branch, $user] = $this->tenant();
        $completed = $this->key($business, $branch, $user, ['status' => 'completed', 'operation_type' => 'pos_sale', 'created_at' => now()->subDays(31)]);
        $failed = $this->key($business, $branch, $user, ['status' => 'failed', 'operation_type' => 'purchase_store', 'created_at' => now()->subDays(31)]);
        $processing = $this->key($business, $branch, $user, ['status' => 'processing', 'operation_type' => 'pos_sale', 'created_at' => now()->subDays(31)]);

        $this->artisan('system:idempotency-prune', ['--days' => 30, '--business' => $business->id, '--dry-run' => true])
            ->expectsOutputToContain('candidatas: 2')
            ->expectsOutputToContain('No se borraron claves.')
            ->assertExitCode(0);
        $this->assertDatabaseCount('operation_idempotency_keys', 3);

        $this->artisan('system:idempotency-prune', ['--days' => 30, '--business' => $business->id, '--confirm' => true])
            ->expectsOutputToContain('eliminadas: 2')
            ->assertExitCode(0);
        $this->assertDatabaseMissing('operation_idempotency_keys', ['id' => $completed->id]);
        $this->assertDatabaseMissing('operation_idempotency_keys', ['id' => $failed->id]);
        $this->assertDatabaseHas('operation_idempotency_keys', ['id' => $processing->id, 'status' => 'processing']);
    }

    public function test_service_tracks_replays_conflicts_and_failed_operation_retries(): void
    {
        [$business, $branch, $user] = $this->tenant();
        $service = app(IdempotencyService::class);

        $service->run($business->id, $branch->id, $user->id, 'pos_sale', 'health-replay-key', ['total' => 10], fn () => 101, 'sale');
        $replay = $service->run($business->id, $branch->id, $user->id, 'pos_sale', 'health-replay-key', ['total' => 10], fn () => 999, 'sale');
        $this->assertTrue($replay->replayed);
        $this->assertDatabaseHas('operation_idempotency_keys', ['idempotency_key' => 'health-replay-key', 'replay_count' => 1]);

        try {
            $service->run($business->id, $branch->id, $user->id, 'pos_sale', 'health-replay-key', ['total' => 11], fn () => 102, 'sale');
            $this->fail('Expected a payload conflict.');
        } catch (ConflictHttpException) {
            $this->assertDatabaseHas('operation_idempotency_keys', ['idempotency_key' => 'health-replay-key', 'conflict_count' => 1]);
        }

        try {
            $service->run($business->id, $branch->id, $user->id, 'purchase_store', 'health-failed-key', ['supplier_id' => 1], fn () => throw new RuntimeException('Proveedor no disponible'), 'purchase');
            $this->fail('Expected the operation to fail.');
        } catch (RuntimeException) {
            $this->assertDatabaseHas('operation_idempotency_keys', ['idempotency_key' => 'health-failed-key', 'status' => 'failed']);
        }

        $retry = $service->run($business->id, $branch->id, $user->id, 'purchase_store', 'health-failed-key', ['supplier_id' => 1], fn () => 202, 'purchase');
        $this->assertFalse($retry->replayed);
        $this->assertDatabaseHas('operation_idempotency_keys', ['idempotency_key' => 'health-failed-key', 'status' => 'completed', 'result_id' => 202]);
    }

    private function tenant(string $name = 'Negocio'): array
    {
        $business = Business::query()->create([
            'name' => $name,
            'country' => 'GT',
            'currency' => 'GTQ',
            'is_active' => true,
        ]);
        $branch = Branch::query()->create([
            'business_id' => $business->id,
            'name' => 'Principal',
            'code' => 'MAIN-'.uniqid(),
            'is_active' => true,
        ]);
        $user = User::factory()->create(['business_id' => $business->id]);

        return [$business, $branch, $user];
    }

    private function key(Business $business, Branch $branch, User $user, array $attributes = []): OperationIdempotencyKey
    {
        $createdAt = $attributes['created_at'] ?? now();
        unset($attributes['created_at']);

        $key = OperationIdempotencyKey::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'operation_type' => 'pos_sale',
            'idempotency_key' => 'key-'.uniqid(),
            'request_hash' => hash('sha256', uniqid('', true)),
            'status' => 'completed',
            'result_type' => 'sale',
            'result_id' => random_int(1, 9999),
            'completed_at' => now(),
            'expires_at' => now()->addDay(),
            ...$attributes,
        ]);

        $key->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        return $key->refresh();
    }
}
