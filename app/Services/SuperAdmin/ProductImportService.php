<?php

namespace App\Services\SuperAdmin;

use App\Models\Branch;
use App\Models\BranchProductPrice;
use App\Models\Brand;
use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBranch;
use App\Models\ProductPrice;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Support\BranchInventory;
use App\Support\PriceLists;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ProductImportService
{
    public const MAX_FILE_SIZE_KB = 10240;

    public const TEMPLATE_COLUMNS = [
        'No.',
        'Nombre',
        'Cantidad',
        'Marca',
        'Proveedor',
        'Código de Barras',
        'Código',
        'Categoría',
        'Precio/Costo',
        'Precio/Venta',
        'Descripción',
    ];

    private const COLUMN_KEYS = [
        'no' => 'No.',
        'nombre' => 'Nombre',
        'cantidad' => 'Cantidad',
        'marca' => 'Marca',
        'proveedor' => 'Proveedor',
        'codigo_de_barras' => 'Código de Barras',
        'codigo' => 'Código',
        'categoria' => 'Categoría',
        'precio_costo' => 'Precio/Costo',
        'precio_venta' => 'Precio/Venta',
        'descripcion' => 'Descripción',
    ];

    private const REQUIRED_COLUMNS = [
        'nombre',
        'cantidad',
        'categoria',
        'precio_costo',
        'precio_venta',
    ];

    public function preview(Business $business, Branch $branch, string $path, ?string $token = null, ?string $filename = null): array
    {
        $parsed = $this->parse($path);

        if ($parsed['missing_columns'] !== []) {
            return [
                'token' => $token,
                'filename' => $filename,
                'business' => ['id' => $business->id, 'name' => $business->name],
                'branch' => ['id' => $branch->id, 'name' => $branch->name],
                'missing_columns' => $parsed['missing_columns'],
                'rows' => [],
                'summary' => $this->emptySummary(count($parsed['rows'])),
                'can_confirm' => false,
            ];
        }

        $analysis = $this->analyzeRows($business, $branch, $parsed['rows']);

        return [
            'token' => $token,
            'filename' => $filename,
            'business' => ['id' => $business->id, 'name' => $business->name],
            'branch' => ['id' => $branch->id, 'name' => $branch->name],
            'missing_columns' => [],
            'rows' => $analysis['rows'],
            'summary' => $analysis['summary'],
            'can_confirm' => $analysis['summary']['valid_rows'] > 0,
        ];
    }

    public function import(Business $business, Branch $branch, string $path, int $userId, ?string $filename = null): array
    {
        $preview = $this->preview($business, $branch, $path, filename: $filename);

        if ($preview['missing_columns'] !== []) {
            throw ValidationException::withMessages([
                'file' => 'Faltan columnas obligatorias: '.implode(', ', $preview['missing_columns']),
            ]);
        }

        if (! $preview['can_confirm']) {
            throw ValidationException::withMessages([
                'file' => 'No hay filas válidas para importar.',
            ]);
        }

        $result = [
            'summary' => [
                ...$preview['summary'],
                'business_name' => $business->name,
                'branch_name' => $branch->name,
                'filename' => $filename ?: basename($path),
                'generated_at' => now()->format('Y-m-d H:i'),
            ],
            'imported_new' => [],
            'inventory_added' => [],
            'rejected' => [],
            'price_warnings' => [],
            'allowed_duplicates' => [],
            'catalogs_created' => [],
        ];

        DB::transaction(function () use ($business, $branch, $preview, $userId, &$result) {
            $catalogs = $this->catalogResolver($business);
            $defaultPriceType = PriceLists::ensureDefaultPriceType((int) $business->id);

            foreach ($preview['rows'] as $row) {
                if ($row['status'] === 'error') {
                    $result['rejected'][] = [
                        $row['row_number'],
                        $row['name'],
                        $row['barcode'],
                        $row['code'],
                        implode(' | ', $row['messages']),
                    ];
                    continue;
                }

                if ($row['action'] === 'create') {
                    $categoryId = $catalogs('category', $row['category'], $result);
                    $brandId = $catalogs('brand', $row['brand'], $result);
                    $catalogs('supplier', $row['supplier'], $result);

                    $product = Product::query()->create([
                        'business_id' => $business->id,
                        'category_id' => $categoryId,
                        'brand_id' => $brandId,
                        'name' => $row['name'],
                        'description' => $row['description'],
                        'barcode' => $row['barcode'],
                        'code' => $row['code'],
                        'cost_price' => $row['cost_price'],
                        'sale_price' => $row['sale_price'],
                        'stock' => 0,
                        'min_stock' => 0,
                        'is_active' => true,
                    ]);

                    $this->ensureProductEnabledForBranch($product, $branch);
                    $this->syncDefaultPrice($business, $branch, $product, $defaultPriceType->id, (float) $row['sale_price']);
                    [$previousStock, $newStock] = BranchInventory::increase($product, $branch->id, (float) $row['quantity']);

                    if ((float) $row['quantity'] > 0) {
                        $this->stockMovement($business, $branch, $product, 'product_import_initial', (float) $row['quantity'], $previousStock, $newStock, 'Importación inicial desde Excel', $userId);
                    }

                    $result['imported_new'][] = [
                        $row['row_number'],
                        $row['name'],
                        $row['barcode'],
                        $row['code'],
                        (float) $row['quantity'],
                        $row['brand'],
                        $row['category'],
                        $row['supplier'],
                        (float) $row['cost_price'],
                        (float) $row['sale_price'],
                        $this->rowResultMessage($row, 'Producto creado.'),
                    ];
                } else {
                    $product = Product::query()
                        ->where('business_id', $business->id)
                        ->lockForUpdate()
                        ->findOrFail((int) $row['existing_product_id']);

                    $this->ensureProductEnabledForBranch($product, $branch);
                    [$previousStock, $newStock] = BranchInventory::increase($product, $branch->id, (float) $row['quantity']);

                    if ((float) $row['quantity'] > 0) {
                        $this->stockMovement($business, $branch, $product, 'product_import_increment', (float) $row['quantity'], $previousStock, $newStock, 'Inventario sumado desde importación Excel', $userId);
                    }

                    $result['inventory_added'][] = [
                        $row['row_number'],
                        $product->name,
                        $row['barcode'],
                        $row['code'],
                        (float) $row['quantity'],
                        $previousStock,
                        $newStock,
                        'Inventario sumado a producto existente.',
                    ];

                    if ($this->hasPriceWarning($product, (float) $row['cost_price'], (float) $row['sale_price'])) {
                        $result['price_warnings'][] = [
                            $row['row_number'],
                            $product->name,
                            $row['barcode'],
                            $row['code'],
                            (float) $product->cost_price,
                            (float) $row['cost_price'],
                            (float) $product->sale_price,
                            (float) $row['sale_price'],
                            'Se sumó inventario. No se actualizó costo/precio.',
                        ];
                    }
                }

                foreach ($row['duplicate_warnings'] as $warning) {
                    $result['allowed_duplicates'][] = [
                        $row['row_number'],
                        $row['name'],
                        $row['barcode'],
                        $row['code'],
                        $warning['type'],
                        $warning['message'],
                        'Producto creado porque la configuración del negocio permite este duplicado.',
                    ];
                }
            }

            $result['summary']['new_products_created'] = count($result['imported_new']);
            $result['summary']['existing_products_incremented'] = count($result['inventory_added']);
            $result['summary']['rejected_rows'] = count($result['rejected']);
            $result['summary']['price_warnings'] = count($result['price_warnings']);
            $result['summary']['allowed_duplicates'] = count($result['allowed_duplicates']);
            $result['summary']['categories_created'] = collect($result['catalogs_created'])->where(0, 'Categoría')->count();
            $result['summary']['brands_created'] = collect($result['catalogs_created'])->where(0, 'Marca')->count();
            $result['summary']['suppliers_created'] = collect($result['catalogs_created'])->where(0, 'Proveedor')->count();
        });

        return $result;
    }

    public function reportSheets(array $result): array
    {
        return [
            'Importados nuevos' => [
                ['Fila Excel', 'Nombre', 'Código de barras', 'Código', 'Cantidad', 'Marca', 'Categoría', 'Proveedor', 'Costo', 'Precio venta', 'Resultado'],
                ...$result['imported_new'],
            ],
            'Inventario sumado' => [
                ['Fila Excel', 'Producto existente', 'Código de barras', 'Código', 'Cantidad sumada', 'Stock anterior', 'Stock nuevo', 'Resultado'],
                ...$result['inventory_added'],
            ],
            'Rechazados' => [
                ['Fila Excel', 'Nombre', 'Código de barras', 'Código', 'Motivo'],
                ...$result['rejected'],
            ],
            'Advertencias precio costo' => [
                ['Fila Excel', 'Producto existente', 'Código de barras', 'Código', 'Costo actual', 'Costo en Excel', 'Precio actual', 'Precio en Excel', 'Acción tomada'],
                ...$result['price_warnings'],
            ],
            'Duplicados permitidos' => [
                ['Fila Excel', 'Nombre', 'Código de barras', 'Código', 'Tipo duplicado', 'Motivo', 'Acción tomada'],
                ...$result['allowed_duplicates'],
            ],
            'Catálogos creados' => [
                ['Tipo', 'Nombre', 'Resultado'],
                ...$result['catalogs_created'],
            ],
            'Resumen' => $this->summaryRows($result['summary']),
        ];
    }

    public function templateRows(): array
    {
        return [
            self::TEMPLATE_COLUMNS,
            [1, 'Producto de ejemplo', 10, 'Marca ejemplo', 'Proveedor ejemplo', '7401006400482', 'ABC-001', 'Categoría ejemplo', 25.50, 40.00, 'Descripción opcional'],
        ];
    }

    public function validateBranch(Business $business, int $branchId): Branch
    {
        return Branch::query()
            ->where('business_id', $business->id)
            ->where('is_active', true)
            ->findOrFail($branchId);
    }

    public function validateUploadedFile(UploadedFile $file): void
    {
        if (! in_array(mb_strtolower($file->getClientOriginalExtension()), ['xlsx'], true)) {
            throw ValidationException::withMessages(['file' => 'El archivo debe ser .xlsx.']);
        }
    }

    private function parse(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = $sheet->getHighestDataRow();
        $highestColumn = $sheet->getHighestDataColumn();
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);
        $headerRow = null;
        $headers = [];

        for ($row = 1; $row <= min($highestRow, 10); $row++) {
            $candidate = [];
            for ($column = 1; $column <= $highestColumnIndex; $column++) {
                $candidate[$column] = $this->normalizeHeader((string) $sheet->getCell([$column, $row])->getFormattedValue());
            }

            if (in_array('nombre', $candidate, true) && in_array('cantidad', $candidate, true)) {
                $headerRow = $row;
                $headers = $candidate;
                break;
            }
        }

        if ($headerRow === null) {
            return [
                'missing_columns' => array_values(self::COLUMN_KEYS),
                'rows' => [],
            ];
        }

        $columns = [];
        foreach ($headers as $column => $header) {
            foreach (self::COLUMN_KEYS as $key => $label) {
                if ($header === $this->normalizeHeader($label)) {
                    $columns[$key] = $column;
                }
            }
        }

        $missing = collect(self::REQUIRED_COLUMNS)
            ->reject(fn (string $key) => isset($columns[$key]))
            ->map(fn (string $key) => self::COLUMN_KEYS[$key])
            ->values()
            ->all();

        if (! isset($columns['codigo']) && ! isset($columns['codigo_de_barras'])) {
            $missing[] = 'Código o Código de Barras';
        }

        $rows = [];
        for ($row = $headerRow + 1; $row <= $highestRow; $row++) {
            $raw = [];
            $hasValue = false;

            foreach (self::COLUMN_KEYS as $key => $label) {
                $column = $columns[$key] ?? null;
                $value = $column ? $this->cellString($sheet->getCell([$column, $row])) : null;
                $raw[$key] = $value;
                $hasValue = $hasValue || $this->normalizeText($value) !== null;
            }

            if (! $hasValue) {
                continue;
            }

            $rows[] = $this->normalizeRow($row, $raw);
        }

        return [
            'missing_columns' => $missing,
            'rows' => $rows,
        ];
    }

    private function analyzeRows(Business $business, Branch $branch, array $rows): array
    {
        $allowDuplicateCodes = (bool) ($business->tenantSetting?->allow_duplicate_product_codes ?? false);
        $allowDuplicateBarcodes = (bool) ($business->tenantSetting?->allow_duplicate_product_barcodes ?? false);
        $barcodeCounts = collect($rows)->pluck('barcode')->filter()->map(fn (string $value) => $this->identityKey($value))->countBy();
        $codeCounts = collect($rows)->pluck('code')->filter()->map(fn (string $value) => $this->identityKey($value))->countBy();
        $existing = $this->existingProductMaps((int) $business->id, $rows);
        $validCatalogRows = [];

        foreach ($rows as &$row) {
            $row['messages'] = [];
            $row['duplicate_warnings'] = [];
            $row['status'] = 'ok';
            $row['action'] = 'create';
            $row['existing_product_id'] = null;

            $this->validateRowBasics($row);

            $barcodeKey = $row['barcode'] !== null ? $this->identityKey($row['barcode']) : null;
            $codeKey = $row['code'] !== null ? $this->identityKey($row['code']) : null;

            if (! $allowDuplicateBarcodes && $barcodeKey !== null && (int) ($barcodeCounts[$barcodeKey] ?? 0) > 1) {
                $row['messages'][] = 'Código de barras duplicado dentro del archivo.';
            }

            if (! $allowDuplicateCodes && $codeKey !== null && (int) ($codeCounts[$codeKey] ?? 0) > 1) {
                $row['messages'][] = 'Código interno duplicado dentro del archivo.';
            }

            if ($allowDuplicateBarcodes && $barcodeKey !== null && (int) ($barcodeCounts[$barcodeKey] ?? 0) > 1) {
                $row['duplicate_warnings'][] = ['type' => 'Código de barras', 'message' => 'Código de barras duplicado permitido por configuración del negocio.'];
            }

            if ($allowDuplicateCodes && $codeKey !== null && (int) ($codeCounts[$codeKey] ?? 0) > 1) {
                $row['duplicate_warnings'][] = ['type' => 'Código interno', 'message' => 'Código interno duplicado permitido por configuración del negocio.'];
            }

            $barcodeProduct = null;
            $codeProduct = null;

            if ($row['barcode'] !== null) {
                $barcodeMatches = $existing['barcode'][$barcodeKey] ?? collect();
                if (! $allowDuplicateBarcodes && $barcodeMatches->count() > 1) {
                    $row['messages'][] = 'Código de barras duplicado en productos existentes.';
                } elseif (! $allowDuplicateBarcodes && $barcodeMatches->count() === 1) {
                    $barcodeProduct = $barcodeMatches->first();
                } elseif ($allowDuplicateBarcodes && $barcodeMatches->isNotEmpty()) {
                    $row['duplicate_warnings'][] = ['type' => 'Código de barras', 'message' => 'Ya existe otro producto con este código de barras. Se creará uno nuevo por configuración del negocio.'];
                }
            }

            if ($row['code'] !== null) {
                $codeMatches = $existing['code'][$codeKey] ?? collect();
                if (! $allowDuplicateCodes && $codeMatches->count() > 1) {
                    $row['messages'][] = 'Código interno duplicado en productos existentes.';
                } elseif (! $allowDuplicateCodes && $codeMatches->count() === 1) {
                    $codeProduct = $codeMatches->first();
                } elseif ($allowDuplicateCodes && $codeMatches->isNotEmpty()) {
                    $row['duplicate_warnings'][] = ['type' => 'Código interno', 'message' => 'Ya existe otro producto con este código interno. Se creará uno nuevo por configuración del negocio.'];
                }
            }

            if ($barcodeProduct && $codeProduct && (int) $barcodeProduct->id !== (int) $codeProduct->id) {
                $row['messages'][] = 'Conflicto de identidad: el código de barras pertenece a un producto y el código interno a otro.';
            }

            $existingProduct = $barcodeProduct ?: $codeProduct;
            if ($existingProduct) {
                $row['action'] = 'increment';
                $row['existing_product_id'] = $existingProduct->id;
                $row['existing_product_name'] = $existingProduct->name;
                $row['messages'][] = 'Producto existente: se sumará inventario.';

                if ($this->hasPriceWarning($existingProduct, (float) ($row['cost_price'] ?? 0), (float) ($row['sale_price'] ?? 0))) {
                    $row['messages'][] = 'Costo/precio diferente: se reportará, no se actualizará.';
                }
            } elseif ($row['duplicate_warnings'] !== []) {
                $row['messages'][] = 'Duplicado permitido por configuración del negocio.';
            }

            if ($row['messages'] !== [] && $this->hasBlockingMessage($row['messages'])) {
                $row['status'] = 'error';
                $row['action'] = 'reject';
            } elseif ($row['duplicate_warnings'] !== [] || str_contains(implode(' ', $row['messages']), 'reportará')) {
                $row['status'] = 'warning';
            }

            if ($row['status'] !== 'error') {
                $validCatalogRows[] = $row;
            }
        }
        unset($row);

        $catalogPreview = $this->catalogPreview($business, $validCatalogRows);

        foreach ($rows as &$row) {
            if ($row['status'] === 'error') {
                continue;
            }

            foreach ([
                'category' => 'Categoría nueva',
                'brand' => 'Marca nueva',
                'supplier' => 'Proveedor nuevo',
            ] as $field => $label) {
                $value = $row[$field] ?? null;
                if ($value !== null && in_array($this->normalizeKey($value), $catalogPreview[$field], true)) {
                    $row['messages'][] = "{$label}: {$value}";
                    if ($row['status'] === 'ok') {
                        $row['status'] = 'warning';
                    }
                }
            }
        }
        unset($row);

        return [
            'rows' => $rows,
            'summary' => [
                'total_rows' => count($rows),
                'valid_rows' => collect($rows)->where('status', '!=', 'error')->count(),
                'new_products' => collect($rows)->where('action', 'create')->where('status', '!=', 'error')->count(),
                'inventory_increments' => collect($rows)->where('action', 'increment')->where('status', '!=', 'error')->count(),
                'rejected_rows' => collect($rows)->where('status', 'error')->count(),
                'price_warnings' => collect($rows)->filter(fn ($row) => str_contains(implode(' ', $row['messages']), 'Costo/precio diferente'))->count(),
                'allowed_duplicates' => collect($rows)->sum(fn ($row) => count($row['duplicate_warnings'])),
                'categories_new' => count($catalogPreview['category']),
                'brands_new' => count($catalogPreview['brand']),
                'suppliers_new' => count($catalogPreview['supplier']),
                'allow_duplicate_product_codes' => $allowDuplicateCodes,
                'allow_duplicate_product_barcodes' => $allowDuplicateBarcodes,
            ],
        ];
    }

    private function validateRowBasics(array &$row): void
    {
        if ($row['name'] === null) {
            $row['messages'][] = 'Nombre vacío.';
        }

        if ($row['barcode'] === null && $row['code'] === null) {
            $row['messages'][] = 'Producto sin código ni código de barras.';
        }

        if ($row['category'] === null) {
            $row['messages'][] = 'Categoría vacía.';
        }

        foreach (['quantity' => 'Cantidad inválida.', 'cost_price' => 'Precio costo inválido.', 'sale_price' => 'Precio venta inválido.'] as $key => $message) {
            if ($row[$key] === null || ! is_numeric($row[$key]) || (float) $row[$key] < 0) {
                $row['messages'][] = $message;
            }
        }

        if ((float) ($row['quantity'] ?? 0) === 0.0) {
            $row['messages'][] = 'Cantidad cero: se creará producto con stock 0.';
        }
    }

    private function hasBlockingMessage(array $messages): bool
    {
        foreach ($messages as $message) {
            if (str_contains($message, 'se sumará')
                || str_contains($message, 'reportará')
                || str_contains($message, 'permitido')
                || str_contains($message, 'Cantidad cero')
                || str_contains($message, 'nueva')) {
                continue;
            }

            return true;
        }

        return false;
    }

    private function normalizeRow(int $rowNumber, array $raw): array
    {
        return [
            'row_number' => $rowNumber,
            'reference' => $this->normalizeText($raw['no'] ?? null),
            'name' => $this->normalizeText($raw['nombre'] ?? null),
            'quantity' => $this->normalizeDecimal($raw['cantidad'] ?? null),
            'brand' => $this->normalizeText($raw['marca'] ?? null),
            'supplier' => $this->normalizeText($raw['proveedor'] ?? null),
            'barcode' => $this->normalizeIdentifier($raw['codigo_de_barras'] ?? null),
            'code' => $this->normalizeIdentifier($raw['codigo'] ?? null),
            'category' => $this->normalizeText($raw['categoria'] ?? null),
            'cost_price' => $this->normalizeDecimal($raw['precio_costo'] ?? null),
            'sale_price' => $this->normalizeDecimal($raw['precio_venta'] ?? null),
            'description' => $this->normalizeText($raw['descripcion'] ?? null),
        ];
    }

    private function existingProductMaps(int $businessId, array $rows): array
    {
        $products = Product::query()
            ->where('business_id', $businessId)
            ->where(fn ($query) => $query->whereNotNull('code')->orWhereNotNull('barcode'))
            ->get(['id', 'business_id', 'name', 'code', 'barcode', 'cost_price', 'sale_price']);

        return [
            'code' => $products->filter(fn (Product $product) => $product->code !== null)->groupBy(fn (Product $product) => $this->identityKey($product->code)),
            'barcode' => $products->filter(fn (Product $product) => $product->barcode !== null)->groupBy(fn (Product $product) => $this->identityKey($product->barcode)),
        ];
    }

    private function catalogPreview(Business $business, array $rows): array
    {
        $existing = [
            'category' => Category::query()->where('business_id', $business->id)->pluck('name')->map(fn ($name) => $this->normalizeKey($name))->all(),
            'brand' => Brand::query()->where('business_id', $business->id)->pluck('name')->map(fn ($name) => $this->normalizeKey($name))->all(),
            'supplier' => Supplier::query()->where('business_id', $business->id)->pluck('name')->map(fn ($name) => $this->normalizeKey($name))->all(),
        ];

        $preview = ['category' => [], 'brand' => [], 'supplier' => []];

        foreach ($rows as $row) {
            foreach (['category', 'brand', 'supplier'] as $field) {
                if ($row[$field] === null) {
                    continue;
                }
                $key = $this->normalizeKey($row[$field]);
                if (! in_array($key, $existing[$field], true) && ! in_array($key, $preview[$field], true)) {
                    $preview[$field][] = $key;
                }
            }
        }

        return $preview;
    }

    private function catalogResolver(Business $business): \Closure
    {
        $cache = [
            'category' => Category::query()->where('business_id', $business->id)->get()->keyBy(fn (Category $row) => $this->normalizeKey($row->name)),
            'brand' => Brand::query()->where('business_id', $business->id)->get()->keyBy(fn (Brand $row) => $this->normalizeKey($row->name)),
            'supplier' => Supplier::query()->where('business_id', $business->id)->get()->keyBy(fn (Supplier $row) => $this->normalizeKey($row->name)),
        ];

        return function (string $type, ?string $name, array &$result) use ($business, &$cache): ?int {
            if ($name === null) {
                return null;
            }

            $key = $this->normalizeKey($name);
            if ($cache[$type]->has($key)) {
                $record = $cache[$type]->get($key);
                if ($type !== 'category' && property_exists($record, 'is_active') && ! $record->is_active) {
                    $record->update(['is_active' => true]);
                }

                return $record->id;
            }

            $record = match ($type) {
                'category' => Category::query()->create(['business_id' => $business->id, 'name' => $name]),
                'brand' => Brand::query()->create(['business_id' => $business->id, 'name' => $name, 'is_active' => true]),
                'supplier' => Supplier::query()->create(['business_id' => $business->id, 'name' => $name, 'is_active' => true]),
            };

            $cache[$type]->put($key, $record);
            $result['catalogs_created'][] = [
                ['category' => 'Categoría', 'brand' => 'Marca', 'supplier' => 'Proveedor'][$type],
                $name,
                'Creado',
            ];

            return $record->id;
        };
    }

    private function ensureProductEnabledForBranch(Product $product, Branch $branch): void
    {
        if (BranchInventory::productsShared((int) $product->business_id)) {
            return;
        }

        ProductBranch::query()->updateOrCreate(
            [
                'business_id' => $product->business_id,
                'product_id' => $product->id,
                'branch_id' => $branch->id,
            ],
            ['is_active' => true],
        );
    }

    private function syncDefaultPrice(Business $business, Branch $branch, Product $product, int $priceTypeId, float $price): void
    {
        if (BranchInventory::pricingScope((int) $business->id) === 'branch') {
            BranchProductPrice::query()->updateOrCreate(
                [
                    'business_id' => $business->id,
                    'branch_id' => $branch->id,
                    'product_id' => $product->id,
                    'price_type_id' => $priceTypeId,
                ],
                ['price' => round($price, 2), 'is_active' => true],
            );

            return;
        }

        ProductPrice::query()->updateOrCreate(
            [
                'business_id' => $business->id,
                'product_id' => $product->id,
                'price_type_id' => $priceTypeId,
            ],
            ['price' => round($price, 2), 'is_active' => true],
        );
    }

    private function stockMovement(Business $business, Branch $branch, Product $product, string $type, float $quantity, float $previousStock, float $newStock, string $note, int $userId): void
    {
        StockMovement::query()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'type' => $type,
            'quantity' => $quantity,
            'previous_stock' => $previousStock,
            'new_stock' => $newStock,
            'note' => "{$note} (Super Admin)",
            'created_by' => $userId,
            'user_id' => $userId,
        ]);
    }

    private function hasPriceWarning(Product $product, float $cost, float $salePrice): bool
    {
        return abs((float) $product->cost_price - $cost) > 0.01
            || abs((float) $product->sale_price - $salePrice) > 0.01;
    }

    private function rowResultMessage(array $row, string $default): string
    {
        if ($row['duplicate_warnings'] === []) {
            return $default;
        }

        return $default.' Producto creado con código/código de barras duplicado permitido por configuración del negocio.';
    }

    private function summaryRows(array $summary): array
    {
        return [
            ['Negocio', $summary['business_name'] ?? ''],
            ['Sucursal destino', $summary['branch_name'] ?? ''],
            ['Archivo', $summary['filename'] ?? ''],
            ['Fecha/hora', $summary['generated_at'] ?? ''],
            ['Total filas leídas', $summary['total_rows'] ?? 0],
            ['Productos nuevos creados', $summary['new_products_created'] ?? $summary['new_products'] ?? 0],
            ['Productos existentes con inventario sumado', $summary['existing_products_incremented'] ?? $summary['inventory_increments'] ?? 0],
            ['Filas rechazadas', $summary['rejected_rows'] ?? 0],
            ['Advertencias precio/costo', $summary['price_warnings'] ?? 0],
            ['Duplicados permitidos', $summary['allowed_duplicates'] ?? 0],
            ['Categorías creadas', $summary['categories_created'] ?? $summary['categories_new'] ?? 0],
            ['Marcas creadas', $summary['brands_created'] ?? $summary['brands_new'] ?? 0],
            ['Proveedores creados', $summary['suppliers_created'] ?? $summary['suppliers_new'] ?? 0],
        ];
    }

    private function emptySummary(int $totalRows): array
    {
        return [
            'total_rows' => $totalRows,
            'valid_rows' => 0,
            'new_products' => 0,
            'inventory_increments' => 0,
            'rejected_rows' => $totalRows,
            'price_warnings' => 0,
            'allowed_duplicates' => 0,
            'categories_new' => 0,
            'brands_new' => 0,
            'suppliers_new' => 0,
        ];
    }

    private function normalizeHeader(string $value): string
    {
        $value = Str::ascii($this->normalizeText($value) ?? '');
        $value = mb_strtolower($value);
        $value = str_replace(['/', '.', '-'], ' ', $value);

        return preg_replace('/\s+/', '_', trim($value));
    }

    private function normalizeText(mixed $value): ?string
    {
        $normalized = preg_replace('/\s+/', ' ', trim((string) $value));

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeIdentifier(mixed $value): ?string
    {
        $value = $this->normalizeText($value);
        if ($value === null) {
            return null;
        }

        if (preg_match('/^-?\d+(?:\.0+)?$/', $value)) {
            $value = preg_replace('/\.0+$/', '', $value);
        } elseif (preg_match('/^\d+(?:\.\d+)?E\+\d+$/i', $value)) {
            $value = number_format((float) $value, 0, '', '');
        }

        return $this->normalizeText($value);
    }

    private function normalizeDecimal(mixed $value): ?float
    {
        $value = $this->normalizeText($value);
        if ($value === null) {
            return null;
        }

        $value = str_replace(['Q', '$', ' '], '', $value);
        if (str_contains($value, ',') && ! str_contains($value, '.')) {
            $value = str_replace(',', '.', $value);
        } else {
            $value = str_replace(',', '', $value);
        }

        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    private function normalizeKey(?string $value): string
    {
        $value = Str::ascii($this->normalizeText($value) ?? '');

        return mb_strtolower($value);
    }

    private function identityKey(?string $value): string
    {
        return mb_strtoupper($this->normalizeIdentifier($value) ?? '');
    }

    private function cellString(Cell $cell): ?string
    {
        $value = $cell->getFormattedValue();

        if ($value === null || $value === '') {
            $value = $cell->getValue();
        }

        return $this->normalizeText($value);
    }
}
