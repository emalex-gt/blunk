<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\BranchInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ProductImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = [
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->withoutVite();
        Storage::fake('local');
    }

    public function test_only_super_admin_can_access_product_importer(): void
    {
        [$business] = $this->tenant();
        $superAdmin = $this->superAdmin();
        $tenantUser = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'owner',
            'is_super_admin' => false,
        ]);

        $this->get(route('super-admin.product-imports.create', $business))
            ->assertRedirect(route('login'));

        $this->actingAs($tenantUser)
            ->get(route('super-admin.product-imports.create', $business))
            ->assertForbidden();

        $this->actingAs($superAdmin)
            ->get(route('super-admin.product-imports.create', $business))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('SuperAdmin/ProductImports/Create')
                ->where('business.id', $business->id));
    }

    public function test_preview_rejects_missing_required_columns_and_wrong_branch(): void
    {
        [$business] = $this->tenant();
        [$otherBusiness, , $otherBranch] = $this->tenant('Otro');
        $superAdmin = $this->superAdmin();

        $this->actingAs($superAdmin)
            ->post(route('super-admin.product-imports.preview', $business), [
                'branch_id' => $otherBranch->id,
                'file' => $this->xlsx([['Nombre']]),
            ])
            ->assertNotFound();

        $this->actingAs($superAdmin)
            ->post(route('super-admin.product-imports.preview', $business), [
                'branch_id' => BranchInventory::defaultBranch($business->id)->id,
                'file' => $this->xlsx([
                    ['Nombre', 'Cantidad'],
                    ['Producto incompleto', 1],
                ]),
            ])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('SuperAdmin/ProductImports/Create')
                ->where('preview.can_confirm', false)
                ->where('preview.missing_columns.0', 'Categoría'));

        $this->assertNotSame($business->id, $otherBusiness->id);
    }

    public function test_preview_normalizes_barcode_as_string_and_detects_invalid_rows(): void
    {
        [$business, , $branch] = $this->tenant();
        $superAdmin = $this->superAdmin();

        $this->actingAs($superAdmin)
            ->post(route('super-admin.product-imports.preview', $business), [
                'branch_id' => $branch->id,
                'file' => $this->xlsx([
                    self::HEADERS,
                    [1, 'Producto válido', 3, 'Marca A', 'Proveedor A', '7401006400482', 'COD-1', 'Categoría A', '10,50', '15.75', 'Descripción'],
                    [2, '', -1, '', '', '', '', '', 'x', 'y', ''],
                ]),
            ])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('SuperAdmin/ProductImports/Create')
                ->where('preview.rows.0.barcode', '7401006400482')
                ->where('preview.rows.0.cost_price', 10.5)
                ->where('preview.rows.1.status', 'error')
                ->where('preview.summary.valid_rows', 1)
                ->where('preview.summary.rejected_rows', 1));
    }

    public function test_disallowed_duplicate_identifiers_inside_file_reject_rows(): void
    {
        [$business, , $branch] = $this->tenant();
        $superAdmin = $this->superAdmin();

        $this->actingAs($superAdmin)
            ->post(route('super-admin.product-imports.preview', $business), [
                'branch_id' => $branch->id,
                'file' => $this->xlsx([
                    self::HEADERS,
                    [1, 'Producto A', 1, '', '', 'DUP-1', 'COD-A', 'Cat', 10, 20, ''],
                    [2, 'Producto B', 1, '', '', 'DUP-1', 'COD-B', 'Cat', 10, 20, ''],
                ]),
            ])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('preview.can_confirm', false)
                ->where('preview.rows.0.status', 'error')
                ->where('preview.rows.1.status', 'error')
                ->where('preview.rows.0.messages.0', 'Código de barras duplicado dentro del archivo.'));
    }

    public function test_existing_product_with_disallowed_barcode_sums_inventory_without_changing_product_or_creating_purchase(): void
    {
        [$business, , $branch] = $this->tenant();
        $superAdmin = $this->superAdmin();
        $category = Category::query()->create(['business_id' => $business->id, 'name' => 'Original']);
        $brand = Brand::query()->create(['business_id' => $business->id, 'name' => 'Original', 'is_active' => true]);
        $existing = Product::query()->create([
            'business_id' => $business->id,
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'name' => 'Existente',
            'barcode' => 'EX-1',
            'code' => 'COD-EX',
            'cost_price' => 5,
            'sale_price' => 8,
            'stock' => 0,
            'min_stock' => 0,
            'is_active' => true,
        ]);
        BranchInventory::increase($existing, $branch->id, 5);

        $token = $this->storeTempImport([
            self::HEADERS,
            [1, 'Nombre nuevo ignorado', 7, 'Marca Nueva', 'Proveedor Nuevo', 'EX-1', '', 'Categoría Nueva', 50, 80, 'Nueva descripción'],
        ]);

        $this->actingAs($superAdmin)
            ->post(route('super-admin.product-imports.confirm', $business), [
                'branch_id' => $branch->id,
                'token' => $token,
                'filename' => 'existente.xlsx',
            ])
            ->assertRedirect(route('super-admin.product-imports.create', $business));

        $existing->refresh();
        $this->assertSame('Existente', $existing->name);
        $this->assertSame($category->id, $existing->category_id);
        $this->assertSame($brand->id, $existing->brand_id);
        $this->assertSame('5.00', $existing->cost_price);
        $this->assertSame('8.00', $existing->sale_price);
        $this->assertSame(12.0, (float) BranchInventory::stockMap($business->id, [$existing->id], $branch->id)[$existing->id]);
        $this->assertSame(1, Product::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, Purchase::query()->count());
        $this->assertSame(0, PurchaseItem::query()->count());
        $this->assertDatabaseHas('stock_movements', [
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'product_id' => $existing->id,
            'type' => 'product_import_increment',
            'quantity' => '7',
        ]);
    }

    public function test_allowed_duplicate_barcode_creates_new_products_and_report_sheet(): void
    {
        [$business, , $branch] = $this->tenant('Tenant dup', allowDuplicateBarcodes: true);
        $superAdmin = $this->superAdmin();
        $existing = Product::query()->create([
            'business_id' => $business->id,
            'name' => 'Existente',
            'barcode' => 'DUP-ALLOWED',
            'code' => 'EXIST',
            'cost_price' => 5,
            'sale_price' => 8,
            'stock' => 0,
            'min_stock' => 0,
            'is_active' => true,
        ]);
        BranchInventory::increase($existing, $branch->id, 4);

        $token = $this->storeTempImport([
            self::HEADERS,
            [1, 'Nuevo A', 2, 'Marca X', 'Proveedor X', 'DUP-ALLOWED', 'NEW-A', 'Cat X', 10, 20, ''],
            [2, 'Nuevo B', 3, 'Marca X', 'Proveedor X', 'DUP-ALLOWED', 'NEW-B', 'Cat X', 11, 21, ''],
        ]);

        $this->actingAs($superAdmin)
            ->post(route('super-admin.product-imports.confirm', $business), [
                'branch_id' => $branch->id,
                'token' => $token,
                'filename' => 'duplicados.xlsx',
            ])
            ->assertRedirect(route('super-admin.product-imports.create', $business))
            ->assertSessionHas('product_import_report_url');

        $this->assertSame(3, Product::query()->where('business_id', $business->id)->count());
        $this->assertSame(4.0, (float) BranchInventory::stockMap($business->id, [$existing->id], $branch->id)[$existing->id]);
        $this->assertSame(2, StockMovement::query()->where('type', 'product_import_initial')->count());

        $reportPath = collect(Storage::disk('local')->allFiles('product-imports/reports'))->first();
        $this->assertNotNull($reportPath);
        $sheetNames = IOFactory::load(Storage::disk('local')->path($reportPath))->getSheetNames();
        $this->assertContains('Duplicados permitidos', $sheetNames);
        $this->assertContains('Importados nuevos', $sheetNames);
    }

    public function test_barcode_and_code_matching_different_products_rejects_row(): void
    {
        [$business, , $branch] = $this->tenant();
        $superAdmin = $this->superAdmin();
        Product::query()->create(['business_id' => $business->id, 'name' => 'Por barra', 'barcode' => 'BAR-1', 'cost_price' => 1, 'sale_price' => 2, 'stock' => 0, 'min_stock' => 0, 'is_active' => true]);
        Product::query()->create(['business_id' => $business->id, 'name' => 'Por código', 'code' => 'CODE-1', 'cost_price' => 1, 'sale_price' => 2, 'stock' => 0, 'min_stock' => 0, 'is_active' => true]);

        $this->actingAs($superAdmin)
            ->post(route('super-admin.product-imports.preview', $business), [
                'branch_id' => $branch->id,
                'file' => $this->xlsx([
                    self::HEADERS,
                    [1, 'Conflicto', 1, '', '', 'BAR-1', 'CODE-1', 'Cat', 10, 20, ''],
                ]),
            ])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('preview.can_confirm', false)
                ->where('preview.rows.0.status', 'error')
                ->where('preview.rows.0.messages.0', 'Conflicto de identidad: el código de barras pertenece a un producto y el código interno a otro.'));
    }

    public function test_new_products_create_stock_catalogs_description_price_and_skip_rejected_catalogs(): void
    {
        [$business, , $branch] = $this->tenant();
        $superAdmin = $this->superAdmin();

        $token = $this->storeTempImport([
            self::HEADERS,
            [1, 'Producto nuevo', 6, ' Coca  Cola ', ' Proveedor  Uno ', 'BAR-N', 'COD-N', ' Bebidas ', 12.5, 18.75, 'Descripción nueva'],
            [2, '', 1, 'Marca Rechazada', 'Proveedor Rechazado', 'BAR-R', 'COD-R', 'Categoría Rechazada', 1, 2, ''],
        ]);

        $this->actingAs($superAdmin)
            ->post(route('super-admin.product-imports.confirm', $business), [
                'branch_id' => $branch->id,
                'token' => $token,
                'filename' => 'nuevo.xlsx',
            ])
            ->assertRedirect(route('super-admin.product-imports.create', $business));

        $product = Product::query()->where('business_id', $business->id)->where('code', 'COD-N')->firstOrFail();
        $this->assertSame('Producto nuevo', $product->name);
        $this->assertSame('Descripción nueva', $product->description);
        $this->assertSame('12.50', $product->cost_price);
        $this->assertSame('18.75', $product->sale_price);
        $this->assertSame(6.0, (float) BranchInventory::stockMap($business->id, [$product->id], $branch->id)[$product->id]);
        $this->assertDatabaseHas('categories', ['business_id' => $business->id, 'name' => 'Bebidas']);
        $this->assertDatabaseHas('brands', ['business_id' => $business->id, 'name' => 'Coca Cola']);
        $this->assertDatabaseHas('suppliers', ['business_id' => $business->id, 'name' => 'Proveedor Uno']);
        $this->assertDatabaseMissing('brands', ['business_id' => $business->id, 'name' => 'Marca Rechazada']);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $product->id, 'type' => 'product_import_initial']);
    }

    public function test_template_download_is_available_to_super_admin(): void
    {
        $superAdmin = $this->superAdmin();

        $this->actingAs($superAdmin)
            ->get(route('super-admin.product-imports.template'))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    private function tenant(string $name = 'Tenant import', bool $allowDuplicateCodes = false, bool $allowDuplicateBarcodes = false): array
    {
        $business = Business::query()->create([
            'name' => $name,
            'country' => 'GT',
            'currency' => 'GTQ',
            'is_active' => true,
        ]);

        TenantSetting::query()->create([
            'business_id' => $business->id,
            'use_branches' => true,
            'allow_receipts' => true,
            'allow_invoices' => false,
            'allow_duplicate_product_codes' => $allowDuplicateCodes,
            'allow_duplicate_product_barcodes' => $allowDuplicateBarcodes,
        ]);

        $branch = BranchInventory::defaultBranch($business->id);

        return [$business, $business->tenantSetting, $branch];
    }

    private function superAdmin(): User
    {
        return User::factory()->create([
            'role' => 'super_admin',
            'is_super_admin' => true,
        ]);
    }

    private function xlsx(array $rows): UploadedFile
    {
        $path = $this->writeXlsx($rows);

        return new UploadedFile(
            $path,
            'productos.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );
    }

    private function storeTempImport(array $rows): string
    {
        $token = str()->random(48);
        Storage::disk('local')->put("product-imports/tmp/{$token}.xlsx", file_get_contents($this->writeXlsx($rows)));

        return $token;
    }

    private function writeXlsx(array $rows): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $columnIndex => $value) {
                $sheet->setCellValue([$columnIndex + 1, $rowIndex + 1], $value);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'product-import-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }
}
