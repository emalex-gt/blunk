<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\Business;
use App\Models\Sale;
use App\Models\Customer;
use App\Models\CustomerTaxLookup;
use App\Models\TenantFelSetting;
use App\Models\User;
use App\Services\Fel\Providers\Digifact\DigifactClient;
use App\Support\Ferrymas\CreditsFocusedAudit;
use App\Support\GuatemalaNitCustomerResolver;
use App\Support\IdempotencyHealthMonitor;
use App\Support\IdempotencyKeyPruner;
use App\Support\Products\MainPriceAuditor;
use App\Support\SalesDuplicateAuditor;
use App\Support\SystemIntegrityAuditor;
use App\Support\TextEncoding;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('app:diagnose-storage', function () {
    $tmpPath = storage_path('app/tmp');
    $currentUser = get_current_user();

    if (function_exists('exec')) {
        $output = [];
        @exec('whoami', $output);
        $currentUser = $output[0] ?? $currentUser;
    }

    $this->line('base_path: '.base_path());
    $this->line('storage_path: '.storage_path());
    $this->line('temp_path: '.$tmpPath);
    $this->line('sys_get_temp_dir: '.sys_get_temp_dir());
    $this->line('is_writable(storage/app/tmp): '.(is_writable(storage_path('app/tmp')) ? 'yes' : 'no'));
    $this->line('is_writable(storage/logs): '.(is_writable(storage_path('logs')) ? 'yes' : 'no'));
    $this->line('is_writable(bootstrap/cache): '.(is_writable(base_path('bootstrap/cache')) ? 'yes' : 'no'));
    $this->line('current_os_user: '.$currentUser);
})->purpose('Diagnose Laravel storage and temp path permissions');

Artisan::command('app:create-super-admin {email?} {--name=} {--password=}', function (?string $email = null) {
    $email ??= $this->ask('Email');
    $name = $this->option('name') ?: $this->ask('Nombre', 'Super Admin');
    $password = $this->option('password') ?: $this->secret('Contraseña');

    if (! $password) {
        $this->error('La contraseña es obligatoria.');

        return self::FAILURE;
    }

    $user = User::updateOrCreate(
        ['email' => $email],
        [
            'business_id' => null,
            'name' => $name,
            'password' => $password,
            'role' => 'super_admin',
            'is_super_admin' => true,
        ],
    );

    $this->info("Super admin listo: {$user->email}");

    return self::SUCCESS;
})->purpose('Create or update a platform super admin user');

Artisan::command('products:audit-main-prices {--business=} {--dry-run} {--confirm} {--branch=} {--include-branch} {--created-after=} {--created-before=} {--report} {--only-active}', function (MainPriceAuditor $auditor) {
    if ($this->option('confirm') && $this->option('dry-run')) {
        $this->error('No combines --confirm con --dry-run.');

        return self::FAILURE;
    }

    try {
        $result = $auditor->run([
            'business' => $this->option('business'),
            'confirm' => (bool) $this->option('confirm'),
            'branch' => $this->option('branch'),
            'include_branch' => (bool) $this->option('include-branch'),
            'created_after' => $this->option('created-after'),
            'created_before' => $this->option('created-before'),
            'report' => (bool) $this->option('report'),
            'only_active' => (bool) $this->option('only-active'),
        ]);
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());

        return self::FAILURE;
    }

    $summary = $result['summary'];

    $this->line('Auditoria de precios principales');
    $this->line('business_id: '.$summary['business_id']);
    $this->line('modo: '.$summary['mode']);
    $this->line("price_type principal: {$summary['price_type_id']} - {$summary['price_type_name']}");
    $this->line('determinacion: '.$summary['price_type_resolution']);
    $this->line('pricing_scope: '.$summary['pricing_scope']);
    $this->line('productos revisados: '.$summary['total_products_reviewed']);
    $this->line('coincidencias: '.$summary['matches']);
    $this->line('diferencias product_prices: '.$summary['differences_detected']);
    $this->line('product_prices creados: '.$summary['product_prices_created']);
    $this->line('product_prices actualizados: '.$summary['product_prices_updated']);
    $this->line('diferencias branch_product_prices: '.$summary['branch_price_differences_detected']);
    $this->line('branch_product_prices creados: '.$summary['branch_product_prices_created']);
    $this->line('branch_product_prices actualizados: '.$summary['branch_product_prices_updated']);
    $this->line('omitidos: '.$summary['omitted']);

    if ($summary['report_path']) {
        $this->line('reporte: '.$summary['report_path']);
    }

    return self::SUCCESS;
})->purpose('Audit and optionally synchronize main product prices for one business');

Artisan::command('sales:audit-duplicates {--business=} {--from=} {--to=} {--window-seconds=60} {--report} {--dry-run} {--confirm} {--keep-sale-id=} {--duplicate-sale-id=}', function (SalesDuplicateAuditor $auditor) {
    if ($this->option('confirm') && $this->option('dry-run')) {
        $this->error('No combines --confirm con --dry-run.');

        return self::FAILURE;
    }

    try {
        if ($this->option('keep-sale-id') || $this->option('duplicate-sale-id')) {
            $result = $auditor->repair([
                'business' => $this->option('business'),
                'keep_sale_id' => $this->option('keep-sale-id'),
                'duplicate_sale_id' => $this->option('duplicate-sale-id'),
                'confirm' => (bool) $this->option('confirm'),
            ]);

            $this->line('Reparacion de venta duplicada');
            $this->line('modo: '.$result['mode']);
            $this->line('conservar venta: '.$result['keep_sale_id']);
            $this->line('venta duplicada: '.$result['duplicate_sale_id']);
            $this->line('correlativo duplicado: '.$result['duplicate_business_number']);
            $this->line('reversas de stock: '.$result['stock_reversals']);
            $this->line('reversa caja: '.$result['cash_reversal_amount']);
            $this->line($result['recommendation']);

            return self::SUCCESS;
        }

        $result = $auditor->audit([
            'business' => $this->option('business'),
            'from' => $this->option('from'),
            'to' => $this->option('to'),
            'window_seconds' => $this->option('window-seconds'),
            'report' => (bool) $this->option('report'),
        ]);
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());

        return self::FAILURE;
    }

    $this->line('Auditoria de ventas duplicadas');
    $this->line('business_id: '.$result['business_id']);
    $this->line('ventana_segundos: '.$result['window_seconds']);
    $this->line('ventas_revisadas: '.$result['sales_reviewed']);
    $this->line('grupos_duplicados: '.$result['duplicate_groups']);

    foreach ($result['groups'] as $index => $group) {
        $this->line('Grupo '.($index + 1).': ventas '.implode(', ', $group['sale_ids']).' | correlativos '.implode(', ', $group['business_numbers']).' | total '.$group['total']);
        $this->line('  recomendacion: '.$group['recommendation']);
    }

    if ($result['report_path']) {
        $this->line('reporte: '.$result['report_path']);
    }

    return self::SUCCESS;
})->purpose('Audit and optionally repair duplicated POS sales safely');

Artisan::command('system:audit-integrity {--business=} {--branch=} {--from=} {--to=} {--report} {--section=} {--strict} {--dry-run}', function (SystemIntegrityAuditor $auditor) {
    try {
        $result = $auditor->audit([
            'business' => $this->option('business'),
            'branch' => $this->option('branch'),
            'from' => $this->option('from'),
            'to' => $this->option('to'),
            'report' => (bool) $this->option('report'),
            'section' => $this->option('section'),
            'strict' => (bool) $this->option('strict'),
        ]);
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());

        return self::FAILURE;
    }

    $this->line('Auditoría de integridad operativa');
    $this->line('business_id: '.$result['business_id']);
    if ($result['branch_id']) {
        $this->line('branch_id: '.$result['branch_id']);
    }
    foreach ($result['summary'] as $summary) {
        $this->line(sprintf('%s: %d críticos, %d warnings, %d info', $summary['section'], $summary['critical_count'], $summary['warning_count'], $summary['info_count']));
    }
    if ($result['report_path']) {
        $this->line('reporte: '.$result['report_path']);
    }
    if ($this->option('verbose')) {
        foreach ($result['results'] as $section => $issues) {
            foreach ($issues as $issue) {
                $this->line("[{$section}] {$issue['severity']} {$issue['issue_type']}: {$issue['notes']}");
            }
        }
    }

    return $result['has_critical'] ? self::FAILURE : self::SUCCESS;
})->purpose('Read-only operational integrity audit with optional CSV reports');

Artisan::command('system:idempotency-health {--business=} {--branch=} {--from=} {--to=} {--hours=24} {--report} {--operation=} {--stale-minutes=10}', function (IdempotencyHealthMonitor $monitor) {
    try {
        $result = $monitor->inspect([
            'business' => $this->option('business'),
            'branch' => $this->option('branch'),
            'from' => $this->option('from'),
            'to' => $this->option('to'),
            'hours' => $this->option('hours'),
            'operation' => $this->option('operation'),
            'stale_minutes' => $this->option('stale-minutes'),
            'report' => (bool) $this->option('report'),
        ]);
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());

        return self::FAILURE;
    }

    $summary = $result['summary'];
    $this->line('Salud de idempotencia');
    $this->line('business_id: '.$result['business_id']);
    if ($result['branch_id']) {
        $this->line('branch_id: '.$result['branch_id']);
    }
    $this->line('periodo: '.$result['from']?->toDateTimeString().' -> '.$result['to']?->toDateTimeString());
    $this->line('keys_totales: '.$summary['total_keys']);
    $this->line('completed: '.$summary['completed_count']);
    $this->line('processing: '.$summary['processing_count']);
    $this->line('failed: '.$summary['failed_count']);
    $this->line('replays: '.$summary['replay_count']);
    $this->line('conflicts: '.$summary['payload_conflict_count']);
    $this->line('processing_atrasados: '.$summary['stale_processing_count']);

    if ($this->getOutput()->isVerbose()) {
        foreach ($result['operations'] as $operation) {
            $this->line(sprintf(
                '%s: completed=%d processing=%d failed=%d stale=%d replays=%d conflicts=%d',
                $operation['operation_type'],
                $operation['completed_count'],
                $operation['processing_count'],
                $operation['failed_count'],
                $operation['stale_processing_count'],
                $operation['replay_count'],
                $operation['conflict_count'],
            ));
        }
    }

    if ($result['report_path']) {
        $this->line('reporte: '.$result['report_path']);
    }

    return $result['has_warnings'] ? self::FAILURE : self::SUCCESS;
})->purpose('Read-only idempotency health monitoring with optional CSV reports');

Artisan::command('system:idempotency-prune {--days=30} {--business=} {--dry-run} {--confirm}', function (IdempotencyKeyPruner $pruner) {
    try {
        $result = $pruner->prune([
            'days' => $this->option('days'),
            'business' => $this->option('business'),
            'dry_run' => (bool) $this->option('dry-run'),
            'confirm' => (bool) $this->option('confirm'),
        ]);
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());

        return self::FAILURE;
    }

    $this->line('Limpieza controlada de idempotencia');
    $this->line('modo: '.($result['confirmed'] ? 'confirmado' : 'dry-run'));
    $this->line('antiguedad_minima_dias: '.$result['days']);
    $this->line('candidatas: '.$result['eligible_count']);
    $this->line('processing_antiguas_no_borradas: '.$result['old_processing_count']);
    foreach ($result['counts'] as $count) {
        $this->line("{$count['status']} {$count['operation_type']}: {$count['count']}");
    }
    if ($result['confirmed']) {
        $this->line('eliminadas: '.$result['deleted']);
    } else {
        $this->line('No se borraron claves. Usa --confirm sin --dry-run para ejecutar la limpieza.');
    }

    return self::SUCCESS;
})->purpose('Prune old completed or failed idempotency keys only when confirmed');

Artisan::command('ferrymas:audit-credits {--business=1}', function (CreditsFocusedAudit $audit) {
    $businessId = (int) $this->option('business');

    $runId = DB::table('migration_runs')->insertGetId([
        'business_id' => $businessId,
        'type' => 'ferrymas_credits_focused_audit',
        'status' => 'running',
        'metadata' => json_encode([
            'sources' => ['legacy_main', 'legacy_branch'],
            'mode' => 'audit_only',
        ]),
        'started_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    try {
        $outputPath = $audit->run($businessId, $runId);

        DB::table('migration_runs')->where('id', $runId)->update([
            'status' => 'completed',
            'output_path' => $outputPath,
            'finished_at' => now(),
            'updated_at' => now(),
        ]);

        $this->info("Auditoría de créditos completada. run_id={$runId}");
        $this->line($outputPath);

        return self::SUCCESS;
    } catch (Throwable $exception) {
        DB::table('migration_runs')->where('id', $runId)->update([
            'status' => 'failed',
            'error_message' => $exception->getMessage(),
            'finished_at' => now(),
            'updated_at' => now(),
        ]);

        $this->error('Auditoría de créditos falló: '.$exception->getMessage());

        return self::FAILURE;
    }
})->purpose('Audit legacy Ferrymas credit reservations and possible real AR without importing data');

Artisan::command('customers:audit-encoding {--business=}', function () {
    $businessId = (int) $this->option('business');

    if ($businessId <= 0) {
        $this->error('Debes indicar --business=ID.');

        return self::FAILURE;
    }

    $affected = [];

    Customer::query()
        ->where('business_id', $businessId)
        ->orderBy('id')
        ->chunkById(200, function ($customers) use (&$affected) {
            foreach ($customers as $customer) {
                $fields = TextEncoding::fieldsWithMojibake([
                    'name' => $customer->name,
                    'commercial_name' => $customer->commercial_name,
                    'contact_name' => $customer->contact_name,
                    'address' => $customer->address,
                ]);

                if ($fields === []) {
                    continue;
                }

                $affected[] = [
                    'id' => $customer->id,
                    'nit' => $customer->doc_number ?: 'CF',
                    'name' => $customer->name,
                    'commercial_name' => $customer->commercial_name,
                    'contact_name' => $customer->contact_name,
                    'address' => $customer->address,
                    'fields' => implode(', ', $fields),
                ];
            }
        });

    $this->info('Clientes con posible mojibake: '.count($affected));

    if ($affected !== []) {
        $this->table(
            ['id', 'nit', 'name', 'commercial_name', 'contact_name', 'address', 'fields'],
            array_slice($affected, 0, 50),
        );
    }

    return self::SUCCESS;
})->purpose('Audit customers with possible text encoding issues without modifying data');

Artisan::command('customers:debug-nit-lookup {nit} {--business=} {--charset-test}', function (string $nit) {
    $businessId = (int) $this->option('business');

    if ($businessId <= 0) {
        $this->error('Debes indicar --business=ID.');

        return self::FAILURE;
    }

    $business = Business::query()->find($businessId);

    if (! $business) {
        $this->error("No existe business_id={$businessId}.");

        return self::FAILURE;
    }

    $normalizedNit = GuatemalaNitCustomerResolver::normalize($nit);
    $this->info("NIT normalizado: {$normalizedNit}");
    $printDiagnostics = function (string $label, ?string $value): void {
        $diagnostics = TextEncoding::stringDiagnostics($value);

        $this->line("{$label}.visible: ".$diagnostics['visible']);
        $this->line("{$label}.characters: ".($diagnostics['characters'] === null ? 'invalid-utf8' : (string) $diagnostics['characters']));
        $this->line("{$label}.bytes: ".$diagnostics['bytes']);
        $this->line("{$label}.codepoints: ".$diagnostics['codepoints']);
        $this->line("{$label}.hex_utf8: ".$diagnostics['hex_utf8']);
        $this->line("{$label}.contains_u_fffd: ".($diagnostics['contains_u_fffd'] ? 'yes' : 'no'));
        $this->line("{$label}.has_mojibake: ".($diagnostics['has_mojibake'] ? 'yes' : 'no'));
    };
    $printExternalDebug = function (array $debug, string $prefix = '') use ($printDiagnostics): void {
        $label = $prefix === '' ? 'external' : $prefix;

        $this->line("{$label}.called: yes");
        $this->line("{$label}.variant: ".((string) ($debug['variant'] ?? 'actual')));
        $this->line("{$label}.request_method: ".((string) ($debug['request_method'] ?? 'GET')));
        $this->line("{$label}.request_url: ".((string) ($debug['request_url'] ?? '-')));
        $this->line("{$label}.request_transport: ".((string) ($debug['request_transport'] ?? '-')));

        foreach (($debug['request_headers'] ?? []) as $header => $value) {
            $this->line("{$label}.request_header.{$header}: ".((string) $value));
        }

        $this->table(['field', 'value'], [
            ['http_status', (string) $debug['http_status']],
            ['successful', $debug['successful'] ? 'yes' : 'no'],
            ['content_type', (string) ($debug['content_type'] ?: '-')],
            ['body_encoding', (string) ($debug['body_encoding'] ?: '-')],
            ['body_converted', $debug['body_converted'] ? 'yes' : 'no'],
            ['body_valid_utf8_before', $debug['body_valid_utf8_before'] ? 'yes' : 'no'],
            ['json_error', (string) ($debug['json_error'] ?: '-')],
            ['response_has_data', $debug['response_has_data'] ? 'yes' : 'no'],
            ['extracted_name', (string) ($debug['extracted_name'] ?: '-')],
            ['extracted_name_has_mojibake', $debug['extracted_name_has_mojibake'] ? 'yes' : 'no'],
            ['payload_has_mojibake', $debug['payload_has_mojibake'] ? 'yes' : 'no'],
            ['body_preview', (string) ($debug['body_preview'] ?: '-')],
        ]);
        $printDiagnostics("{$label}.extracted_name", $debug['extracted_name']);
        $this->line("{$label}.raw_body.contains_u_fffd_bytes: ".($debug['raw_body_contains_u_fffd_bytes'] ? 'yes' : 'no'));
        $this->line("{$label}.raw_body.contains_literal_u00_escape: ".($debug['raw_body_contains_literal_u00_escape'] ? 'yes' : 'no'));
        $this->line("{$label}.raw_body.nombre_marker_position: ".($debug['nombre_marker_position'] === null ? '-' : (string) $debug['nombre_marker_position']));
        $this->line("{$label}.raw_body.nombre_fragment.text: ".((string) ($debug['nombre_raw_fragment_text'] ?: '-')));
        $this->line("{$label}.raw_body.nombre_fragment.hex: ".((string) ($debug['nombre_raw_fragment_hex'] ?: '-')));
        $this->line("{$label}.raw_body.nombre_fragment.codepoints: ".((string) ($debug['nombre_raw_fragment_codepoints'] ?: '-')));
    };

    $customers = Customer::query()
        ->where('business_id', $business->id)
        ->whereRaw("UPPER(REPLACE(REPLACE(COALESCE(doc_number, ''), '-', ''), ' ', '')) = ?", [$normalizedNit])
        ->orderBy('id')
        ->get(['id', 'name', 'commercial_name', 'contact_name', 'doc_type', 'doc_number', 'tax_lookup_verified_at']);

    $this->line('customer.exists: '.($customers->isNotEmpty() ? 'yes' : 'no'));

    foreach ($customers as $customer) {
        $fields = TextEncoding::fieldsWithMojibake([
            'name' => $customer->name,
            'commercial_name' => $customer->commercial_name,
            'contact_name' => $customer->contact_name,
        ]);

        $this->table(['field', 'value'], [
            ['customer_id', (string) $customer->id],
            ['doc_type', (string) ($customer->doc_type ?: '-')],
            ['doc_number', (string) ($customer->doc_number ?: '-')],
            ['name', (string) ($customer->name ?: '-')],
            ['tax_lookup_verified_at', $customer->tax_lookup_verified_at?->toDateTimeString() ?: '-'],
            ['has_mojibake', $fields === [] ? 'no' : 'yes: '.implode(', ', $fields)],
        ]);
        $printDiagnostics('customer.name', $customer->name);
    }

    $cache = CustomerTaxLookup::query()
        ->where('business_id', $business->id)
        ->where('country', 'GT')
        ->where('doc_type', 'NIT')
        ->where('doc_number', $normalizedNit)
        ->first();

    $this->line('cache.exists: '.($cache ? 'yes' : 'no'));

    if ($cache) {
        $this->table(['field', 'value'], [
            ['cache_id', (string) $cache->id],
            ['name', (string) ($cache->name ?: '-')],
            ['last_lookup_at', $cache->last_lookup_at?->toDateTimeString() ?: '-'],
            ['name_has_mojibake', TextEncoding::hasMojibake($cache->name) ? 'yes' : 'no'],
            ['payload_has_mojibake', TextEncoding::payloadHasMojibake($cache->raw_response) ? 'yes' : 'no'],
        ]);
        $printDiagnostics('cache.name', $cache->name);
    }

    DB::beginTransaction();

    try {
        $client = DigifactClient::forBusiness($business);
        $debug = $client->debugNitLookup($normalizedNit);
        $charsetResults = $this->option('charset-test')
            ? $client->debugNitLookupCharsetVariants($normalizedNit)
            : [];
        DB::rollBack();

        $printExternalDebug($debug);

        foreach ($charsetResults as $result) {
            $this->line('');
            $this->info('charset_test.variant: '.((string) ($result['variant'] ?? '-')));
            $printExternalDebug($result, 'charset_test');
        }

        if (! $debug['successful']) {
            $this->warn('final: proveedor respondió con error HTTP.');
        } elseif (! $debug['response_has_data']) {
            $this->warn('final: proveedor no devolvió datos para este NIT.');
        } elseif ($debug['extracted_name_has_mojibake'] || $debug['payload_has_mojibake']) {
            $this->warn('final: proveedor/decodificación produjo datos con mojibake; no se debe actualizar el cliente.');
        } else {
            $this->info('final: respuesta externa legible; refresh manual debería actualizar cliente y caché.');
        }

        return self::SUCCESS;
    } catch (Throwable $exception) {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        $this->line('external.called: yes');
        $this->error('external.error: '.$exception->getMessage());

        return self::FAILURE;
    }
})->purpose('Debug one Guatemala NIT lookup source without updating customers or lookup cache');

Artisan::command('fel:diagnose-speed {sale_id?}', function (?string $sale_id = null) {
    $query = Sale::query()
        ->where('document_type', 'invoice')
        ->with(['electronicDocument', 'felCertificationAttempts' => fn ($builder) => $builder->latest('id')]);

    $sale = $sale_id
        ? $query->find($sale_id)
        : $query->latest('id')->first();

    if (! $sale) {
        $this->error('No se encontró una venta FEL para diagnosticar.');

        return self::FAILURE;
    }

    $settings = TenantFelSetting::query()->where('business_id', $sale->business_id)->first();
    $attempt = $sale->felCertificationAttempts->first();
    $tokenExpiresAt = $settings?->token_expires_at;
    $tokenCached = filled($settings?->token) && $tokenExpiresAt?->gt(now()->addMinutes(2));

    $this->info("Diagnóstico FEL venta #{$sale->id}");
    $this->table(['Dato', 'Valor'], [
        ['business_id', (string) $sale->business_id],
        ['estado venta FEL', (string) ($sale->certification_status ?: $sale->fel_status ?: '-')],
        ['ambiente', (string) ($settings?->environment ?: '-')],
        ['base_url activa', (string) ($settings?->baseUrl() ?: '-')],
        ['token en cache vigente', $tokenCached ? 'sí' : 'no'],
        ['token expira', $tokenExpiresAt?->toIso8601String() ?: '-'],
        ['intento', $attempt ? '#'.$attempt->id.' / '.$attempt->status : '-'],
    ]);

    if (! Schema::hasColumn('fel_certification_attempts', 'timings')) {
        $this->warn('Ejecuta las migraciones para habilitar métricas persistidas.');

        return self::SUCCESS;
    }

    if (! $attempt || ! $attempt->timings) {
        $this->warn('La venta no tiene métricas FEL registradas.');

        return self::SUCCESS;
    }

    $this->table(['Métrica', 'Valor'], collect($attempt->timings)
        ->map(fn ($value, $key) => [(string) $key, is_scalar($value) ? (string) $value : json_encode($value)])
        ->values()
        ->all());

    return self::SUCCESS;
})->purpose('Show stored performance timings for the latest or selected FEL sale');
