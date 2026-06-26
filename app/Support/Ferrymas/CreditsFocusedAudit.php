<?php

namespace App\Support\Ferrymas;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class CreditsFocusedAudit
{
    private const SOURCES = [
        ['source_system' => 'ferrymas_main', 'connection' => 'legacy_main'],
        ['source_system' => 'ferry_tramp', 'connection' => 'legacy_branch'],
    ];

    private const DISCOVERY_TABLES = [
        'credito_venta',
        'credito_venta_linea',
        'cotizacion',
        'cotizacion_linea',
        'cliente',
        'producto',
        'deuda',
        'saldos',
        'abonos',
        'venta',
    ];

    public function run(int $businessId, int $runId): string
    {
        $directory = storage_path("app/ferrymas-audits/{$runId}/credits-focused");
        File::ensureDirectoryExists($directory);

        $snapshots = collect(self::SOURCES)
            ->map(fn (array $source) => $this->sourceSnapshot($source))
            ->values();

        $this->writeSchemaDiscovery($directory, $snapshots);
        $pending = $this->writeCreditReservationReports($directory, $snapshots);
        $this->writeCotizacionLinks($directory, $pending);
        $this->writeFacturadoStatus($directory, $snapshots);
        $realArSummaries = $this->writeRealArReports($directory, $snapshots);
        $this->writeAbonosReports($directory, $snapshots);
        $this->writeCustomerMappingSummary($directory, $pending);
        $this->writeProductMappingSummary($directory, $pending);
        $this->writeAuditSummary($directory, $pending, $realArSummaries);

        return $directory;
    }

    private function sourceSnapshot(array $source): array
    {
        $connectionName = $source['connection'];

        try {
            DB::connection($connectionName)->getPdo();
            $tables = collect(self::DISCOVERY_TABLES)
                ->mapWithKeys(fn (string $table) => [$table => $this->tableSnapshot($connectionName, $table)])
                ->all();

            return [
                ...$source,
                'available' => true,
                'error' => null,
                'tables' => $tables,
            ];
        } catch (\Throwable $exception) {
            return [
                ...$source,
                'available' => false,
                'error' => $exception->getMessage(),
                'tables' => collect(self::DISCOVERY_TABLES)
                    ->mapWithKeys(fn (string $table) => [$table => [
                        'exists' => false,
                        'columns' => [],
                    ]])
                    ->all(),
            ];
        }
    }

    private function tableSnapshot(string $connectionName, string $table): array
    {
        try {
            $exists = Schema::connection($connectionName)->hasTable($table);

            return [
                'exists' => $exists,
                'columns' => $exists ? Schema::connection($connectionName)->getColumnListing($table) : [],
            ];
        } catch (\Throwable) {
            return [
                'exists' => false,
                'columns' => [],
            ];
        }
    }

    private function writeSchemaDiscovery(string $directory, Collection $snapshots): void
    {
        $rows = [];

        foreach ($snapshots as $source) {
            foreach (self::DISCOVERY_TABLES as $table) {
                $columns = $source['tables'][$table]['columns'] ?? [];
                $rows[] = [
                    'source_system' => $source['source_system'],
                    'table_name' => $table,
                    'exists' => $source['tables'][$table]['exists'] ? 'yes' : 'no',
                    'columns_detected' => implode('|', $columns),
                    'has_id_cliente' => $this->hasAny($columns, ['id_cliente', 'cliente_id']) ? 'yes' : 'no',
                    'has_id_venta' => $this->hasAny($columns, ['id_venta', 'venta_id']) ? 'yes' : 'no',
                    'has_id_credito_venta' => $this->hasAny($columns, ['id_credito_venta', 'credito_venta_id']) ? 'yes' : 'no',
                    'has_id_cotizacion_linea' => $this->hasAny($columns, ['id_cotizacion_linea', 'cotizacion_linea_id']) ? 'yes' : 'no',
                    'has_facturado' => in_array('facturado', $columns, true) ? 'yes' : 'no',
                    'has_saldo' => in_array('saldo', $columns, true) ? 'yes' : 'no',
                    'has_saldo_linea' => in_array('saldo_linea', $columns, true) ? 'yes' : 'no',
                    'has_precio_total' => in_array('precio_total', $columns, true) ? 'yes' : 'no',
                    'has_viene_credito' => in_array('viene_credito', $columns, true) ? 'yes' : 'no',
                    'has_estado' => in_array('estado', $columns, true) ? 'yes' : 'no',
                    'has_fecha' => $this->hasAny($columns, ['fecha', 'created_at', 'fecha_creacion']) ? 'yes' : 'no',
                ];
            }
        }

        $this->writeCsv($directory.'/credits_schema_discovery.csv', [
            'source_system',
            'table_name',
            'exists',
            'columns_detected',
            'has_id_cliente',
            'has_id_venta',
            'has_id_credito_venta',
            'has_id_cotizacion_linea',
            'has_facturado',
            'has_saldo',
            'has_saldo_linea',
            'has_precio_total',
            'has_viene_credito',
            'has_estado',
            'has_fecha',
        ], $rows);
    }

    private function writeCreditReservationReports(string $directory, Collection $snapshots): Collection
    {
        $allLines = collect();
        $summaryRows = [];
        $customerRows = [];

        foreach ($snapshots as $source) {
            $lines = $this->pendingCreditLines($source);
            $allLines = $allLines->merge($lines);

            $summaryRows[] = [
                'source_system' => $source['source_system'],
                'total_creditos_with_pending_lines' => $lines->pluck('credito_id')->unique()->count(),
                'total_pending_lines' => $lines->count(),
                'total_pending_amount' => $this->money($lines->sum('line_total')),
                'total_pending_quantity' => $this->money($lines->sum('quantity')),
                'distinct_customers' => $lines->pluck('legacy_customer_id')->filter(fn ($value) => $value !== null)->unique()->count(),
                'distinct_products' => $lines->pluck('legacy_product_id')->filter(fn ($value) => $value !== null)->unique()->count(),
            ];

            $customerRows = [
                ...$customerRows,
                ...$lines
                    ->groupBy(fn (array $line) => (string) ($line['legacy_customer_id'] ?? 'missing'))
                    ->map(fn (Collection $group) => [
                        'source_system' => $source['source_system'],
                        'legacy_customer_id' => $group->first()['legacy_customer_id'],
                        'customer_name' => $group->first()['customer_name'],
                        'customer_nit' => $group->first()['customer_nit'],
                        'pending_lines' => $group->count(),
                        'pending_amount' => $this->money($group->sum('line_total')),
                        'first_credit_date' => $group->pluck('fecha_creacion')->filter()->sort()->first(),
                        'last_credit_date' => $group->pluck('fecha_creacion')->filter()->sort()->last(),
                        'mapping_status' => $group->first()['customer_mapping_status'],
                        'mapping_reason' => $group->first()['customer_mapping_reason'],
                    ])
                    ->values()
                    ->all(),
            ];
        }

        $this->writeCsv($directory.'/credit_reservations_pending_summary.csv', [
            'source_system',
            'total_creditos_with_pending_lines',
            'total_pending_lines',
            'total_pending_amount',
            'total_pending_quantity',
            'distinct_customers',
            'distinct_products',
        ], $summaryRows);

        $this->writeCsv($directory.'/credit_reservations_pending_by_customer.csv', [
            'source_system',
            'legacy_customer_id',
            'customer_name',
            'customer_nit',
            'pending_lines',
            'pending_amount',
            'first_credit_date',
            'last_credit_date',
            'mapping_status',
            'mapping_reason',
        ], $customerRows);

        $this->writeCsv($directory.'/credit_reservations_pending_lines.csv', [
            'source_system',
            'credito_id',
            'linea_id',
            'legacy_customer_id',
            'customer_name',
            'customer_nit',
            'legacy_product_id',
            'legacy_main_product_id',
            'product_name',
            'product_code',
            'quantity',
            'unit_price',
            'line_total',
            'saldo_linea',
            'facturado',
            'id_cotizacion_linea',
            'fecha_creacion',
            'estado',
            'mapping_status',
            'mapping_reason',
        ], $allLines->map(fn (array $line) => [
            'source_system' => $line['source_system'],
            'credito_id' => $line['credito_id'],
            'linea_id' => $line['linea_id'],
            'legacy_customer_id' => $line['legacy_customer_id'],
            'customer_name' => $line['customer_name'],
            'customer_nit' => $line['customer_nit'],
            'legacy_product_id' => $line['legacy_product_id'],
            'legacy_main_product_id' => $line['legacy_main_product_id'],
            'product_name' => $line['product_name'],
            'product_code' => $line['product_code'],
            'quantity' => $line['quantity'],
            'unit_price' => $line['unit_price'],
            'line_total' => $line['line_total'],
            'saldo_linea' => $line['saldo_linea'],
            'facturado' => $line['facturado'],
            'id_cotizacion_linea' => $line['id_cotizacion_linea'],
            'fecha_creacion' => $line['fecha_creacion'],
            'estado' => $line['estado'],
            'mapping_status' => $line['mapping_status'],
            'mapping_reason' => $line['mapping_reason'],
        ])->all());

        return $allLines;
    }

    private function pendingCreditLines(array $source): Collection
    {
        if (! $this->sourceHas($source, 'credito_venta_linea', ['facturado', 'id_credito_venta']) || ! $this->tableExists($source, 'credito_venta')) {
            return collect();
        }

        $lineColumns = $source['tables']['credito_venta_linea']['columns'];
        $creditColumns = $source['tables']['credito_venta']['columns'];
        $customerColumns = $source['tables']['cliente']['columns'] ?? [];
        $productColumns = $source['tables']['producto']['columns'] ?? [];
        $connection = DB::connection($source['connection']);

        if (! in_array('id', $creditColumns, true)) {
            return collect();
        }

        $customerIdColumn = $this->pick($creditColumns, ['id_cliente', 'cliente_id']);
        $lineProductColumn = $this->pick($lineColumns, ['id_producto', 'producto_id']);
        $lineIdColumn = $this->pick($lineColumns, ['id']);
        $quantityColumn = $this->pick($lineColumns, ['cantidad', 'qty', 'quantity']);
        $unitPriceColumn = $this->pick($lineColumns, ['precio', 'precio_unitario', 'unit_price']);
        $lineTotalColumn = $this->pick($lineColumns, ['precio_total', 'total', 'line_total']);
        $lineBalanceColumn = $this->pick($lineColumns, ['saldo_linea', 'saldo']);
        $cotizacionLineColumn = $this->pick($lineColumns, ['id_cotizacion_linea', 'cotizacion_linea_id']);
        $creditDateColumn = $this->pick($creditColumns, ['fecha', 'created_at', 'fecha_creacion']);
        $creditStatusColumn = $this->pick($creditColumns, ['estado', 'activo']);

        $query = $connection->table('credito_venta_linea as l')
            ->join('credito_venta as cv', 'l.id_credito_venta', '=', 'cv.id')
            ->where('l.facturado', 0);

        $customerJoined = $customerIdColumn && $this->tableExists($source, 'cliente') && in_array('id', $customerColumns, true);
        $productJoined = $lineProductColumn && $this->tableExists($source, 'producto') && in_array('id', $productColumns, true);

        if ($customerJoined) {
            $query->leftJoin('cliente as c', "cv.{$customerIdColumn}", '=', 'c.id');
        }

        if ($productJoined) {
            $query->leftJoin('producto as p', "l.{$lineProductColumn}", '=', 'p.id');
        }

        $select = [
            'cv.id as credito_id',
            $lineIdColumn ? "l.{$lineIdColumn} as linea_id" : DB::raw('NULL as linea_id'),
            'l.facturado as facturado',
            $customerIdColumn ? "cv.{$customerIdColumn} as legacy_customer_id" : DB::raw('NULL as legacy_customer_id'),
            $lineProductColumn ? "l.{$lineProductColumn} as legacy_product_id" : DB::raw('NULL as legacy_product_id'),
            $quantityColumn ? "l.{$quantityColumn} as quantity" : DB::raw('0 as quantity'),
            $unitPriceColumn ? "l.{$unitPriceColumn} as unit_price" : DB::raw('0 as unit_price'),
            $lineTotalColumn ? "l.{$lineTotalColumn} as line_total" : DB::raw('0 as line_total'),
            $lineBalanceColumn ? "l.{$lineBalanceColumn} as saldo_linea" : DB::raw('NULL as saldo_linea'),
            $cotizacionLineColumn ? "l.{$cotizacionLineColumn} as id_cotizacion_linea" : DB::raw('NULL as id_cotizacion_linea'),
            $creditDateColumn ? "cv.{$creditDateColumn} as fecha_creacion" : DB::raw('NULL as fecha_creacion'),
            $creditStatusColumn ? "cv.{$creditStatusColumn} as estado" : DB::raw('NULL as estado'),
        ];

        if ($customerJoined) {
            $select[] = $this->selectAlias('c', $customerColumns, ['nombre', 'name', 'cliente'], 'customer_name');
            $select[] = $this->selectAlias('c', $customerColumns, ['nit', 'nif', 'doc_number', 'documento'], 'customer_nit');
        } else {
            $select[] = DB::raw('NULL as customer_name');
            $select[] = DB::raw('NULL as customer_nit');
        }

        if ($productJoined) {
            $select[] = $this->selectAlias('p', $productColumns, ['nombre', 'name', 'descripcion'], 'product_name');
            $select[] = $this->selectAlias('p', $productColumns, ['codigo', 'code', 'sku', 'barra', 'barcode'], 'product_code');
            $select[] = $this->selectAlias('p', $productColumns, ['main_id'], 'legacy_main_product_id');
        } else {
            $select[] = DB::raw('NULL as product_name');
            $select[] = DB::raw('NULL as product_code');
            $select[] = DB::raw('NULL as legacy_main_product_id');
        }

        $mainProductIds = $source['source_system'] === 'ferry_tramp'
            ? $this->mainProductIds()
            : collect();

        return $query
            ->get($select)
            ->map(function (object $row) use ($source, $mainProductIds) {
                $customerMapping = $this->customerMapping($source['source_system'], $row->legacy_customer_id ?? null, $row->customer_nit ?? null);
                $productMapping = $this->productMapping($source['source_system'], $row->legacy_product_id ?? null, $row->legacy_main_product_id ?? null, $mainProductIds);
                $mappingStatus = $customerMapping['status'] === 'conflict' || $productMapping['status'] === 'conflict' ? 'conflict' : 'mapped';
                $mappingReasons = array_filter([$customerMapping['reason'], $productMapping['reason']]);

                return [
                    'source_system' => $source['source_system'],
                    'credito_id' => $row->credito_id ?? null,
                    'linea_id' => $row->linea_id ?? null,
                    'legacy_customer_id' => $row->legacy_customer_id ?? null,
                    'customer_name' => $row->customer_name ?? null,
                    'customer_nit' => $row->customer_nit ?? null,
                    'customer_mapping_status' => $customerMapping['status'],
                    'customer_mapping_reason' => $customerMapping['reason'],
                    'legacy_product_id' => $row->legacy_product_id ?? null,
                    'legacy_main_product_id' => $productMapping['legacy_main_product_id'],
                    'product_name' => $row->product_name ?? null,
                    'product_code' => $row->product_code ?? null,
                    'quantity' => (float) ($row->quantity ?? 0),
                    'unit_price' => (float) ($row->unit_price ?? 0),
                    'line_total' => (float) ($row->line_total ?? 0),
                    'saldo_linea' => $row->saldo_linea ?? null,
                    'facturado' => $row->facturado ?? null,
                    'id_cotizacion_linea' => $row->id_cotizacion_linea ?? null,
                    'fecha_creacion' => $row->fecha_creacion ?? null,
                    'estado' => $row->estado ?? null,
                    'mapping_status' => $mappingStatus,
                    'mapping_reason' => implode('; ', $mappingReasons),
                ];
            });
    }

    private function writeCotizacionLinks(string $directory, Collection $pending): void
    {
        $rows = $pending->map(function (array $line) {
            $source = $this->sourceSnapshotByName($line['source_system']);
            $link = $this->cotizacionLineLink($source, $line);

            return [
                'source_system' => $line['source_system'],
                'linea_id' => $line['linea_id'],
                'id_cotizacion_linea' => $line['id_cotizacion_linea'],
                ...$link,
            ];
        })->all();

        $this->writeCsv($directory.'/credit_reservation_line_cotizacion_links.csv', [
            'source_system',
            'linea_id',
            'id_cotizacion_linea',
            'cotizacion_line_exists',
            'cotizacion_id',
            'cotizacion_estado',
            'cotizacion_sucursal',
            'cotizacion_bodega',
            'cotizacion_line_product_id',
            'cotizacion_line_quantity',
            'cotizacion_line_price',
            'cotizacion_line_total',
            'matches_credit_line_product',
            'matches_credit_line_quantity',
            'matches_credit_line_total',
        ], $rows);
    }

    private function cotizacionLineLink(array $source, array $line): array
    {
        $empty = [
            'cotizacion_line_exists' => 'no',
            'cotizacion_id' => null,
            'cotizacion_estado' => null,
            'cotizacion_sucursal' => null,
            'cotizacion_bodega' => null,
            'cotizacion_line_product_id' => null,
            'cotizacion_line_quantity' => null,
            'cotizacion_line_price' => null,
            'cotizacion_line_total' => null,
            'matches_credit_line_product' => 'unknown',
            'matches_credit_line_quantity' => 'unknown',
            'matches_credit_line_total' => 'unknown',
        ];

        if (! $line['id_cotizacion_linea'] || ! $this->tableExists($source, 'cotizacion_linea')) {
            return $empty;
        }

        $lineColumns = $source['tables']['cotizacion_linea']['columns'];

        if (! in_array('id', $lineColumns, true)) {
            return $empty;
        }
        $cotColumns = $source['tables']['cotizacion']['columns'] ?? [];
        $cotIdColumn = $this->pick($lineColumns, ['id_cotizacion', 'cotizacion_id']);
        $productColumn = $this->pick($lineColumns, ['id_producto', 'producto_id']);
        $quantityColumn = $this->pick($lineColumns, ['cantidad', 'qty', 'quantity']);
        $priceColumn = $this->pick($lineColumns, ['precio', 'precio_unitario', 'unit_price']);
        $totalColumn = $this->pick($lineColumns, ['precio_total', 'total', 'line_total']);

        $query = DB::connection($source['connection'])->table('cotizacion_linea as cl');

        if ($cotIdColumn && $this->tableExists($source, 'cotizacion')) {
            $query->leftJoin('cotizacion as c', "cl.{$cotIdColumn}", '=', 'c.id');
        }

        $row = $query
            ->where('cl.id', $line['id_cotizacion_linea'])
            ->first([
                $cotIdColumn ? "cl.{$cotIdColumn} as cotizacion_id" : DB::raw('NULL as cotizacion_id'),
                $productColumn ? "cl.{$productColumn} as product_id" : DB::raw('NULL as product_id'),
                $quantityColumn ? "cl.{$quantityColumn} as quantity" : DB::raw('NULL as quantity'),
                $priceColumn ? "cl.{$priceColumn} as price" : DB::raw('NULL as price'),
                $totalColumn ? "cl.{$totalColumn} as total" : DB::raw('NULL as total'),
                $this->selectAlias('c', $cotColumns, ['estado'], 'estado'),
                $this->selectAlias('c', $cotColumns, ['sucursal', 'id_sucursal', 'sucursal_id'], 'sucursal'),
                $this->selectAlias('c', $cotColumns, ['bodega', 'id_bodega', 'bodega_id'], 'bodega'),
            ]);

        if (! $row) {
            return $empty;
        }

        return [
            'cotizacion_line_exists' => 'yes',
            'cotizacion_id' => $row->cotizacion_id ?? null,
            'cotizacion_estado' => $row->estado ?? null,
            'cotizacion_sucursal' => $row->sucursal ?? null,
            'cotizacion_bodega' => $row->bodega ?? null,
            'cotizacion_line_product_id' => $row->product_id ?? null,
            'cotizacion_line_quantity' => $row->quantity ?? null,
            'cotizacion_line_price' => $row->price ?? null,
            'cotizacion_line_total' => $row->total ?? null,
            'matches_credit_line_product' => (string) ($row->product_id ?? '') === (string) ($line['legacy_product_id'] ?? '') ? 'yes' : 'no',
            'matches_credit_line_quantity' => (float) ($row->quantity ?? 0) === (float) ($line['quantity'] ?? 0) ? 'yes' : 'no',
            'matches_credit_line_total' => abs((float) ($row->total ?? 0) - (float) ($line['line_total'] ?? 0)) < 0.01 ? 'yes' : 'no',
        ];
    }

    private function writeFacturadoStatus(string $directory, Collection $snapshots): void
    {
        $rows = [];

        foreach ($snapshots as $source) {
            if (! $this->sourceHas($source, 'credito_venta_linea', ['facturado'])) {
                continue;
            }

            $columns = $source['tables']['credito_venta_linea']['columns'];
            $totalColumn = $this->pick($columns, ['precio_total', 'total', 'line_total']);
            $saldoColumn = $this->pick($columns, ['saldo_linea', 'saldo']);

            $rows = [
                ...$rows,
                ...DB::connection($source['connection'])
                    ->table('credito_venta_linea')
                    ->selectRaw('facturado as facturado_value, COUNT(*) as line_count')
                    ->when($totalColumn, fn ($query) => $query->selectRaw("SUM({$totalColumn}) as sum_precio_total"))
                    ->when(! $totalColumn, fn ($query) => $query->selectRaw('0 as sum_precio_total'))
                    ->when($saldoColumn, fn ($query) => $query->selectRaw("SUM({$saldoColumn}) as sum_saldo_linea"))
                    ->when(! $saldoColumn, fn ($query) => $query->selectRaw('0 as sum_saldo_linea'))
                    ->groupBy('facturado')
                    ->get()
                    ->map(fn (object $row) => [
                        'source_system' => $source['source_system'],
                        'facturado_value' => $row->facturado_value,
                        'line_count' => $row->line_count,
                        'sum_precio_total' => $this->money($row->sum_precio_total),
                        'sum_saldo_linea' => $this->money($row->sum_saldo_linea),
                    ])
                    ->all(),
            ];
        }

        $this->writeCsv($directory.'/credit_reservations_facturado_status.csv', [
            'source_system',
            'facturado_value',
            'line_count',
            'sum_precio_total',
            'sum_saldo_linea',
        ], $rows);
    }

    private function writeRealArReports(string $directory, Collection $snapshots): Collection
    {
        $definitions = [
            'cotizacion_saldo_positive' => ['table' => 'cotizacion', 'sample' => 'real_ar_cotizacion_saldo_positive.csv', 'saldo' => true, 'viene_credito' => false],
            'cotizacion_viene_credito_saldo_positive' => ['table' => 'cotizacion', 'sample' => 'real_ar_cotizacion_credito_saldo_positive.csv', 'saldo' => true, 'viene_credito' => true],
            'deuda_saldo_positive' => ['table' => 'deuda', 'sample' => 'real_ar_deuda_saldo_positive.csv', 'saldo' => true, 'activo' => true],
            'saldos_saldo_positive' => ['table' => 'saldos', 'sample' => 'real_ar_saldos_saldo_positive.csv', 'saldo' => true, 'pagado' => true],
            'credito_linea_facturada_saldo_positive' => ['table' => 'credito_venta_linea', 'sample' => 'real_ar_credito_linea_facturada_saldo_positive.csv', 'saldo_linea' => true, 'facturado' => true],
        ];
        $summary = collect();

        foreach ($definitions as $sourceName => $definition) {
            $sampleRows = [];

            foreach ($snapshots as $source) {
                $result = $this->realArRows($source, $sourceName, $definition);
                $summary->push($result['summary']);
                $sampleRows = [...$sampleRows, ...$result['rows']];
            }

            $this->writeCsv($directory.'/'.$definition['sample'], $this->realArDetailHeaders(), $sampleRows);
        }

        $this->writeCsv($directory.'/real_ar_sources_summary.csv', [
            'source_system',
            'source_name',
            'exists',
            'total_rows',
            'positive_balance_rows',
            'positive_balance_sum',
            'distinct_customers',
            'notes',
        ], $summary->all());

        return $summary;
    }

    private function realArRows(array $source, string $sourceName, array $definition): array
    {
        $table = $definition['table'];

        if (! $this->tableExists($source, $table)) {
            return [
                'summary' => $this->realArSummaryRow($source['source_system'], $sourceName, 'no', 0, 0, 0, 0, 'table_missing'),
                'rows' => [],
            ];
        }

        $columns = $source['tables'][$table]['columns'];
        $saldoColumn = isset($definition['saldo_linea']) ? $this->pick($columns, ['saldo_linea']) : $this->pick($columns, ['saldo']);

        if (! $saldoColumn) {
            return [
                'summary' => $this->realArSummaryRow($source['source_system'], $sourceName, 'yes', $this->countRows($source['connection'], $table), 0, 0, 0, 'saldo_column_missing'),
                'rows' => [],
            ];
        }

        $query = DB::connection($source['connection'])->table($table);
        $customerColumn = $this->pick($columns, ['id_cliente', 'cliente_id']);

        if ($table === 'credito_venta_linea' && $this->sourceHas($source, 'credito_venta_linea', ['id_credito_venta']) && $this->tableExists($source, 'credito_venta')) {
            $creditColumns = $source['tables']['credito_venta']['columns'];
            $creditCustomer = $this->pick($creditColumns, ['id_cliente', 'cliente_id']);
            $query->join('credito_venta as cv', "{$table}.id_credito_venta", '=', 'cv.id');
            $customerColumn = $creditCustomer ? "cv.{$creditCustomer}" : null;
        }

        $positive = (clone $query)->where($saldoColumn, '>', 0);

        if (($definition['viene_credito'] ?? false) && in_array('viene_credito', $columns, true)) {
            $positive->where('viene_credito', 1);
        }

        if (($definition['facturado'] ?? false) && in_array('facturado', $columns, true)) {
            $positive->where('facturado', 1);
        }

        if (in_array('estado', $columns, true) && in_array($table, ['cotizacion'], true)) {
            $positive->where('estado', 1);
        }

        if (($definition['activo'] ?? false) && in_array('activo', $columns, true)) {
            $positive->where('activo', 1);
        }

        if (($definition['pagado'] ?? false) && in_array('pagado', $columns, true)) {
            $positive->where(function ($query) {
                $query->where('pagado', 0)->orWhereNull('pagado');
            });
        }

        $count = (clone $positive)->count();
        $sum = (clone $positive)->sum($saldoColumn);
        $distinctCustomers = $customerColumn ? (clone $positive)->distinct()->count($customerColumn) : 0;
        $rows = $positive
            ->limit(500)
            ->get()
            ->map(fn (object $row) => $this->realArDetailRow($source, $sourceName, $table, $columns, $row))
            ->all();

        return [
            'summary' => $this->realArSummaryRow($source['source_system'], $sourceName, 'yes', $this->countRows($source['connection'], $table), $count, $sum, $distinctCustomers, ''),
            'rows' => $rows,
        ];
    }

    private function realArSummaryRow(string $sourceSystem, string $sourceName, string $exists, int $totalRows, int $positiveRows, float $sum, int $distinctCustomers, string $notes): array
    {
        return [
            'source_system' => $sourceSystem,
            'source_name' => $sourceName,
            'exists' => $exists,
            'total_rows' => $totalRows,
            'positive_balance_rows' => $positiveRows,
            'positive_balance_sum' => $this->money($sum),
            'distinct_customers' => $distinctCustomers,
            'notes' => $notes,
        ];
    }

    private function realArDetailRow(array $source, string $sourceName, string $table, array $columns, object $row): array
    {
        $customerId = $this->valueFromRow($row, $this->pick($columns, ['id_cliente', 'cliente_id']));
        $nit = $this->valueFromRow($row, $this->pick($columns, ['nit', 'nif', 'doc_number']));
        $mapping = $this->customerMapping($source['source_system'], $customerId, $nit);

        return [
            'source_system' => $source['source_system'],
            'source_name' => $sourceName,
            'legacy_id' => $this->valueFromRow($row, 'id'),
            'legacy_customer_id' => $customerId,
            'customer_name' => $this->valueFromRow($row, $this->pick($columns, ['nombre', 'cliente', 'name'])),
            'customer_nit' => $nit,
            'fecha' => $this->valueFromRow($row, $this->pick($columns, ['fecha', 'created_at', 'fecha_creacion'])),
            'estado' => $this->valueFromRow($row, 'estado'),
            'total' => $this->valueFromRow($row, $this->pick($columns, ['total', 'precio_total'])),
            'saldo' => $this->valueFromRow($row, $this->pick($columns, ['saldo', 'saldo_linea'])),
            'pagado' => $this->valueFromRow($row, 'pagado'),
            'activo' => $this->valueFromRow($row, 'activo'),
            'viene_credito' => $this->valueFromRow($row, 'viene_credito'),
            'document_reference' => $this->valueFromRow($row, $this->pick($columns, ['numero', 'documento', 'referencia', 'serie'])),
            'mapping_status' => $mapping['status'],
            'mapping_reason' => $mapping['reason'],
        ];
    }

    private function realArDetailHeaders(): array
    {
        return [
            'source_system',
            'source_name',
            'legacy_id',
            'legacy_customer_id',
            'customer_name',
            'customer_nit',
            'fecha',
            'estado',
            'total',
            'saldo',
            'pagado',
            'activo',
            'viene_credito',
            'document_reference',
            'mapping_status',
            'mapping_reason',
        ];
    }

    private function writeAbonosReports(string $directory, Collection $snapshots): void
    {
        $summary = [];
        $sample = [];

        foreach ($snapshots as $source) {
            if (! $this->tableExists($source, 'abonos')) {
                $summary[] = [
                    'source_system' => $source['source_system'],
                    'exists' => 'no',
                    'total_rows' => 0,
                    'sum_amount_detected' => 0,
                    'detected_amount_column' => null,
                    'detected_customer_column' => null,
                    'detected_credit_column' => null,
                    'detected_sale_column' => null,
                    'date_column' => null,
                    'columns_detected' => '',
                ];
                continue;
            }

            $columns = $source['tables']['abonos']['columns'];
            $amount = $this->pick($columns, ['monto', 'abono', 'cantidad', 'total', 'importe']);
            $customer = $this->pick($columns, ['id_cliente', 'cliente_id']);
            $credit = $this->pick($columns, ['id_credito_venta', 'credito_venta_id']);
            $sale = $this->pick($columns, ['id_venta', 'venta_id']);
            $date = $this->pick($columns, ['fecha', 'created_at', 'fecha_creacion']);

            $summary[] = [
                'source_system' => $source['source_system'],
                'exists' => 'yes',
                'total_rows' => $this->countRows($source['connection'], 'abonos'),
                'sum_amount_detected' => $amount ? $this->money(DB::connection($source['connection'])->table('abonos')->sum($amount)) : 0,
                'detected_amount_column' => $amount,
                'detected_customer_column' => $customer,
                'detected_credit_column' => $credit,
                'detected_sale_column' => $sale,
                'date_column' => $date,
                'columns_detected' => implode('|', $columns),
            ];

            $sample = [
                ...$sample,
                ...DB::connection($source['connection'])
                    ->table('abonos')
                    ->limit(200)
                    ->get()
                    ->map(fn (object $row) => [
                        'source_system' => $source['source_system'],
                        'raw_json' => json_encode($row, JSON_UNESCAPED_UNICODE),
                    ])
                    ->all(),
            ];
        }

        $this->writeCsv($directory.'/abonos_summary.csv', [
            'source_system',
            'exists',
            'total_rows',
            'sum_amount_detected',
            'detected_amount_column',
            'detected_customer_column',
            'detected_credit_column',
            'detected_sale_column',
            'date_column',
            'columns_detected',
        ], $summary);

        $this->writeCsv($directory.'/abonos_sample.csv', ['source_system', 'raw_json'], $sample);
    }

    private function writeCustomerMappingSummary(string $directory, Collection $pending): void
    {
        $rows = [];

        foreach ($pending->groupBy('source_system') as $sourceSystem => $lines) {
            $customerGroups = $lines->groupBy(fn (array $line) => (string) ($line['legacy_customer_id'] ?? 'missing'));
            $validNit = $customerGroups->filter(fn (Collection $group) => $this->isValidNit($group->first()['customer_nit'] ?? null));
            $missing = $customerGroups->reject(fn (Collection $group) => $this->isValidNit($group->first()['customer_nit'] ?? null));
            $duplicateNit = $lines
                ->filter(fn (array $line) => $this->isValidNit($line['customer_nit'] ?? null))
                ->groupBy(fn (array $line) => $this->normalizeNit($line['customer_nit']))
                ->filter(fn (Collection $group) => $group->pluck('legacy_customer_id')->unique()->count() > 1);

            foreach ([
                'valid_nit' => $validNit,
                'missing_nit_or_cf' => $missing,
                'missing_nit_or_cf_with_pending_amount' => $missing->filter(fn (Collection $group) => $group->sum('line_total') > 0),
                'duplicate_nit' => $duplicateNit,
            ] as $category => $groups) {
                $rows[] = [
                    'source_system' => $sourceSystem,
                    'category' => $category,
                    'customer_count' => $groups->count(),
                    'pending_amount' => $this->money($groups->sum(fn (Collection $group) => $group->sum('line_total'))),
                    'notes' => $category === 'missing_nit_or_cf_with_pending_amount'
                        ? 'Do not merge by name; recommend CF-LEGACY-'.$sourceSystem.'-{legacy_customer_id} for positive pending balances.'
                        : '',
                ];
            }
        }

        $this->writeCsv($directory.'/credits_customers_mapping_summary.csv', [
            'source_system',
            'category',
            'customer_count',
            'pending_amount',
            'notes',
        ], $rows);
    }

    private function writeProductMappingSummary(string $directory, Collection $pending): void
    {
        $rows = $pending
            ->groupBy('source_system')
            ->map(fn (Collection $lines, string $sourceSystem) => [
                'source_system' => $sourceSystem,
                'total_lines' => $lines->count(),
                'mapped_lines' => $lines->where('mapping_status', 'mapped')->count(),
                'conflict_lines' => $lines->where('mapping_status', 'conflict')->count(),
                'missing_product_lines' => $lines->filter(fn (array $line) => str_contains((string) $line['mapping_reason'], 'missing_product'))->count(),
                'missing_main_id_lines' => $lines->filter(fn (array $line) => str_contains((string) $line['mapping_reason'], 'missing_main_id'))->count(),
                'missing_main_product_lines' => $lines->filter(fn (array $line) => str_contains((string) $line['mapping_reason'], 'missing_main_product'))->count(),
            ])
            ->values()
            ->all();

        $this->writeCsv($directory.'/credit_reservation_product_mapping_summary.csv', [
            'source_system',
            'total_lines',
            'mapped_lines',
            'conflict_lines',
            'missing_product_lines',
            'missing_main_id_lines',
            'missing_main_product_lines',
        ], $rows);
    }

    private function writeAuditSummary(string $directory, Collection $pending, Collection $realArSummaries): void
    {
        $rows = [];

        foreach (collect(self::SOURCES)->pluck('source_system') as $sourceSystem) {
            $sourcePending = $pending->where('source_system', $sourceSystem);
            $conflicts = $sourcePending->where('mapping_status', 'conflict')->count();
            $positiveAr = $realArSummaries
                ->where('source_system', $sourceSystem)
                ->filter(fn (array $row) => (float) $row['positive_balance_sum'] > 0 || (int) $row['positive_balance_rows'] > 0);

            $rows[] = [
                'source_system' => $sourceSystem,
                'metric' => 'pending_credit_reservations',
                'value' => $this->money($sourcePending->sum('line_total')),
                'classification' => $sourcePending->isEmpty()
                    ? 'no_pending_rows_found'
                    : ($conflicts > 0 ? 'importable_with_warnings' : 'importable'),
                'recommendation' => 'If credito_venta_linea.facturado = 0 rows exist, migrate to credit_receipts and credit_receipt_lines, not customer_credit_accounts.',
            ];

            $rows[] = [
                'source_system' => $sourceSystem,
                'metric' => 'pending_credit_reservation_lines',
                'value' => $sourcePending->count(),
                'classification' => $conflicts > 0 ? 'importable_with_warnings' : ($sourcePending->isEmpty() ? 'no_pending_rows_found' : 'importable'),
                'recommendation' => 'Review product/customer mapping summaries before migration.',
            ];

            $rows[] = [
                'source_system' => $sourceSystem,
                'metric' => 'real_accounts_receivable',
                'value' => $this->money($positiveAr->sum('positive_balance_sum')),
                'classification' => $positiveAr->isNotEmpty() ? 'source_identified' : 'no_positive_balances_found',
                'recommendation' => $positiveAr->isNotEmpty()
                    ? 'Evaluate cotizacion.viene_credito/saldo, deuda and saldos as real AR opening balances; compare sources before importing.'
                    : 'No positive balances found in inspected AR candidates.',
            ];

            $rows[] = [
                'source_system' => $sourceSystem,
                'metric' => 'manual_decision',
                'value' => $positiveAr->pluck('source_name')->implode('|'),
                'classification' => $positiveAr->isNotEmpty() ? 'manual_decision_required' : 'not_required',
                'recommendation' => 'Do not calculate final balances from abonos until relationships are confirmed.',
            ];
        }

        $this->writeCsv($directory.'/credits_audit_summary.csv', [
            'source_system',
            'metric',
            'value',
            'classification',
            'recommendation',
        ], $rows);
    }

    private function writeCsv(string $path, array $headers, array $rows): void
    {
        File::ensureDirectoryExists(dirname($path));
        $handle = fopen($path, 'wb');

        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn (string $header) => $row[$header] ?? null, $headers));
        }

        fclose($handle);
    }

    private function sourceSnapshotByName(string $sourceSystem): array
    {
        $source = collect(self::SOURCES)->firstWhere('source_system', $sourceSystem);

        return $source ? $this->sourceSnapshot($source) : ['source_system' => $sourceSystem, 'connection' => null, 'tables' => []];
    }

    private function mainProductIds(): Collection
    {
        try {
            if (! Schema::connection('legacy_main')->hasTable('producto')) {
                return collect();
            }

            return DB::connection('legacy_main')->table('producto')->pluck('id')->map(fn ($id) => (string) $id);
        } catch (\Throwable) {
            return collect();
        }
    }

    private function productMapping(string $sourceSystem, mixed $legacyProductId, mixed $legacyMainProductId, Collection $mainProductIds): array
    {
        if (! $legacyProductId) {
            return ['status' => 'conflict', 'legacy_main_product_id' => null, 'reason' => 'missing_product'];
        }

        if ($sourceSystem === 'ferrymas_main') {
            return ['status' => 'mapped', 'legacy_main_product_id' => $legacyProductId, 'reason' => 'legacy_main_product_id'];
        }

        if (! $legacyMainProductId) {
            return ['status' => 'conflict', 'legacy_main_product_id' => null, 'reason' => 'missing_main_id'];
        }

        if ($mainProductIds->isNotEmpty() && ! $mainProductIds->contains((string) $legacyMainProductId)) {
            return ['status' => 'conflict', 'legacy_main_product_id' => $legacyMainProductId, 'reason' => 'missing_main_product'];
        }

        return ['status' => 'mapped', 'legacy_main_product_id' => $legacyMainProductId, 'reason' => 'branch_main_id'];
    }

    private function customerMapping(string $sourceSystem, mixed $legacyCustomerId, mixed $nit): array
    {
        if (! $legacyCustomerId) {
            return ['status' => 'conflict', 'reason' => 'missing_customer_record'];
        }

        if ($this->isValidNit($nit)) {
            return ['status' => 'mapped', 'reason' => 'valid_nit:'.$this->normalizeNit($nit)];
        }

        return ['status' => 'mapped', 'reason' => "cf_legacy:CF-LEGACY-{$sourceSystem}-{$legacyCustomerId}"];
    }

    private function isValidNit(mixed $nit): bool
    {
        $normalized = $this->normalizeNit($nit);

        return $normalized !== '' && $normalized !== 'CF' && $normalized !== 'C/F';
    }

    private function normalizeNit(mixed $nit): string
    {
        return strtoupper(str_replace([' ', '-'], '', trim((string) $nit)));
    }

    private function tableExists(array $source, string $table): bool
    {
        return (bool) ($source['tables'][$table]['exists'] ?? false);
    }

    private function sourceHas(array $source, string $table, array $columns): bool
    {
        if (! $this->tableExists($source, $table)) {
            return false;
        }

        $existing = $source['tables'][$table]['columns'] ?? [];

        return collect($columns)->every(fn (string $column) => in_array($column, $existing, true));
    }

    private function pick(array $columns, array|string $candidates): ?string
    {
        foreach ((array) $candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return null;
    }

    private function hasAny(array $columns, array $candidates): bool
    {
        return $this->pick($columns, $candidates) !== null;
    }

    private function selectAlias(string $tableAlias, array $columns, array $candidates, string $alias): mixed
    {
        $column = $this->pick($columns, $candidates);

        return $column ? "{$tableAlias}.{$column} as {$alias}" : DB::raw("NULL as {$alias}");
    }

    private function valueFromRow(object $row, ?string $column): mixed
    {
        if (! $column) {
            return null;
        }

        $key = str_contains($column, '.') ? substr($column, strrpos($column, '.') + 1) : $column;

        return $row->{$key} ?? null;
    }

    private function countRows(string $connection, string $table): int
    {
        return (int) DB::connection($connection)->table($table)->count();
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
