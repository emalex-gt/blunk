<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PriceListController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\CashRegisterController;
use App\Http\Controllers\InventoryTransferController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\TenantUserController;
use App\Http\Controllers\SuperAdmin\DashboardController as SuperAdminDashboardController;
use App\Http\Controllers\SuperAdmin\FelIncidentController as SuperAdminFelIncidentController;
use App\Http\Controllers\SuperAdmin\SecurityController as SuperAdminSecurityController;
use App\Http\Controllers\SuperAdmin\TenantController as SuperAdminTenantController;
use App\Http\Controllers\SuperAdmin\TenantBranchController as SuperAdminTenantBranchController;
use App\Http\Controllers\SuperAdmin\TenantSubscriptionController as SuperAdminTenantSubscriptionController;
use App\Http\Controllers\SuperAdmin\TenantUserController as SuperAdminTenantUserController;
use App\Services\Fel\Providers\Digifact\DigifactClient;
use App\Services\Fel\Providers\Digifact\DigifactInvoiceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Models\Business;
use App\Models\Sale;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }

    return redirect()->route('login');
});

Route::get('/dashboard', [ReportController::class, 'dashboard'])
    ->middleware(['auth', 'verified', 'tenant.active', 'permission:pos.view'])
    ->name('dashboard');

Route::middleware(['auth', 'super.admin'])
    ->prefix('super-admin')
    ->name('super-admin.')
    ->group(function () {
        Route::get('/', SuperAdminDashboardController::class)->name('dashboard');
        Route::get('/fel-incidents', [SuperAdminFelIncidentController::class, 'index'])->name('fel-incidents.index');
        Route::post('/fel-incidents/{incident}/review', [SuperAdminFelIncidentController::class, 'review'])->name('fel-incidents.review');
        Route::post('/fel-incidents/{incident}/resolve', [SuperAdminFelIncidentController::class, 'resolve'])->name('fel-incidents.resolve');
        Route::get('/security/roles', [SuperAdminSecurityController::class, 'roles'])->name('security.roles');
        Route::post('/security/roles', [SuperAdminSecurityController::class, 'storeRole'])->name('security.roles.store');
        Route::put('/security/roles/{role}', [SuperAdminSecurityController::class, 'updateRole'])->name('security.roles.update');
        Route::delete('/security/roles/{role}', [SuperAdminSecurityController::class, 'destroyRole'])->name('security.roles.destroy');
        Route::get('/security/permissions', [SuperAdminSecurityController::class, 'permissions'])->name('security.permissions');
        Route::post('/security/permissions', [SuperAdminSecurityController::class, 'storePermission'])->name('security.permissions.store');
        Route::put('/security/permissions/{permission}', [SuperAdminSecurityController::class, 'updatePermission'])->name('security.permissions.update');
        Route::delete('/security/permissions/{permission}', [SuperAdminSecurityController::class, 'destroyPermission'])->name('security.permissions.destroy');
        Route::get('/security/assignments', [SuperAdminSecurityController::class, 'assignments'])->name('security.assignments');
        Route::put('/security/assignments/{user}', [SuperAdminSecurityController::class, 'updateAssignment'])->name('security.assignments.update');
        Route::get('/tenants', [SuperAdminTenantController::class, 'index'])->name('tenants.index');
        Route::get('/tenants/create', [SuperAdminTenantController::class, 'create'])->name('tenants.create');
        Route::post('/tenants', [SuperAdminTenantController::class, 'store'])->name('tenants.store');
        Route::get('/tenants/{business}/edit', [SuperAdminTenantController::class, 'edit'])->name('tenants.edit');
        Route::put('/tenants/{business}', [SuperAdminTenantController::class, 'update'])->name('tenants.update');
        Route::post('/tenants/{business}/fel/test-connection', [SuperAdminTenantController::class, 'testFelConnection'])->name('tenants.fel.test-connection');
        Route::get('/tenants/{business}/users', [SuperAdminTenantUserController::class, 'index'])->name('tenants.users');
        Route::post('/tenants/{business}/users', [SuperAdminTenantUserController::class, 'store'])->name('tenants.users.store');
        Route::put('/tenants/{business}/users/{user}', [SuperAdminTenantUserController::class, 'update'])->name('tenants.users.update');
        Route::delete('/tenants/{business}/users/{user}', [SuperAdminTenantUserController::class, 'destroy'])->name('tenants.users.destroy');
        Route::get('/tenants/{business}/branches', [SuperAdminTenantBranchController::class, 'index'])->name('tenants.branches');
        Route::post('/tenants/{business}/branches', [SuperAdminTenantBranchController::class, 'store'])->name('tenants.branches.store');
        Route::put('/tenants/{business}/branches/{branch}', [SuperAdminTenantBranchController::class, 'update'])->name('tenants.branches.update');
        Route::delete('/tenants/{business}/branches/{branch}', [SuperAdminTenantBranchController::class, 'destroy'])->name('tenants.branches.destroy');
        Route::get('/tenants/{business}/subscription', [SuperAdminTenantSubscriptionController::class, 'edit'])->name('tenants.subscription');
        Route::put('/tenants/{business}/subscription', [SuperAdminTenantSubscriptionController::class, 'update'])->name('tenants.subscription.update');
        Route::post('/tenants/{business}/subscription/{status}', [SuperAdminTenantSubscriptionController::class, 'setStatus'])->name('tenants.subscription.status');
    });

Route::middleware('auth')->group(function () {
    Route::post('/tenant/switch', function (Request $request): RedirectResponse {
        abort_unless($request->user()?->id === 1, 403);

        $data = $request->validate([
            'business_id' => ['required', 'exists:businesses,id'],
        ]);

        $business = Business::query()
            ->where('is_active', true)
            ->findOrFail($data['business_id']);

        session(['active_business_id' => $business->id]);

        return back()->with('success', 'Negocio cambiado correctamente');
    })->name('tenant.switch');

    Route::middleware('tenant.active')->group(function () {
        Route::get('/pos', [SaleController::class, 'create'])->middleware(['module:pos', 'permission:pos.view'])->name('sales.create');
        Route::post('/pos/fel/prewarm-token', [SaleController::class, 'prewarmFelToken'])
            ->middleware(['module:pos', 'module:fel_gt', 'permission:pos.view'])
            ->name('sales.fel.prewarm-token');
        Route::post('/sales', [SaleController::class, 'store'])->middleware(['module:pos', 'permission:pos.sell'])->name('sales.store');
        Route::get('/sales/cancelled', fn () => redirect()->route('reports.sales', ['status' => 'cancelled']))
            ->middleware(['module:reports', 'permission:reports.sales.view'])
            ->name('sales.cancelled');
        Route::get('/sales/{sale}', [SaleController::class, 'show'])->middleware(['module:pos', 'permission:sales.view'])->name('sales.show');
        Route::post('/sales/{sale}/cancel', [SaleController::class, 'cancel'])->middleware(['module:pos', 'permission:sales.cancel'])->name('sales.cancel');
        Route::get('/sales/{sale}/receipt', [SaleController::class, 'receipt'])->middleware(['module:pos', 'permission:sales.print'])->name('sales.receipt');
        Route::post('/sales/{sale}/fel/retry', [SaleController::class, 'retryFelCertification'])->middleware(['module:fel_gt', 'permission:fel.certify'])->name('sales.fel.retry');
        Route::get('/sales/{sale}/fel-document', [SaleController::class, 'felDocument'])->middleware(['module:fel_gt', 'permission:fel.documents.view,signed'])->name('sales.fel-document');
        Route::get('/sales/{sale}/fel-download/{format}', [SaleController::class, 'felDownload'])
            ->middleware(['module:fel_gt', 'permission:fel.documents.view'])
            ->whereIn('format', ['xml', 'pdf'])
            ->name('sales.fel-download');
        Route::get('/sales/{sale}/fel-print', [SaleController::class, 'felPrint'])->middleware(['module:fel_gt', 'permission:fel.documents.view,signed'])->name('sales.fel-print');
        Route::get('/sales/{sale}/invoice-document', [SaleController::class, 'invoiceDocument'])->middleware(['module:fel_gt', 'permission:fel.documents.view,signed'])->name('sales.invoice-document');
        Route::get('/customers/lookup/nit', [CustomerController::class, 'lookupNit'])->middleware(['module:customers', 'permission:customers.view'])->name('customers.lookup.nit');
        Route::get('/customers/gt/nit-lookup', [CustomerController::class, 'lookupGuatemalaNit'])->middleware(['module:fel_gt', 'permission:customers.view'])->name('customers.gt.nit-lookup');
        Route::get('/customers/{customer}/products/{product}/last-price', [SaleController::class, 'lastCustomerProductPrice'])
            ->middleware(['module:pos', 'permission:sales.view'])
            ->name('customers.products.last-price');

        Route::match(['get', 'patch'], '/settings/fel', fn () => abort(403));
        Route::post('/settings/fel/test-connection', fn () => abort(403));
        Route::get('/debug/digifact/nit/{nit}', function (Request $request, string $nit) {
            abort_unless($request->user()?->id === 1 || $request->user()?->is_super_admin, 403);

            $business = Business::query()->findOrFail(currentBusinessId());
            $client = DigifactClient::forBusiness($business);

            return response()->json($client->debugLookupNit($nit));
        })->name('debug.digifact.nit');
        Route::get('/debug/digifact/last-response/{sale}', function (Request $request, Sale $sale) {
            abort_unless($request->user()?->id === 1 || $request->user()?->is_super_admin, 403);
            abort_unless((int) $sale->business_id === (int) currentBusinessId(), 403);

            $sale->load('electronicDocument');
            $document = $sale->electronicDocument;
            $response = $document?->response_payload ?? [];

            return response()->json([
                'sale_id' => $sale->id,
                'electronic_document' => $document ? [
                    'id' => $document->id,
                    'status' => $document->status,
                    'uuid' => $document->uuid,
                    'series' => $document->series,
                    'number' => $document->number,
                    'certification_date' => $document->certification_date?->toIso8601String(),
                    'error_message' => $document->error_message,
                ] : null,
                'request_payload' => $document?->request_payload,
                'request_payload_pretty' => $document?->request_payload
                    ? json_encode($document->request_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null,
                'response_payload' => $document?->response_payload,
                'response_payload_pretty' => $document?->response_payload
                    ? json_encode($document->response_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null,
                'extracted_identifiers' => is_array($response)
                    ? app(DigifactInvoiceService::class)->extractCertificationIdentifiers($response)
                    : null,
            ]);
        })->name('debug.digifact.last-response');

        Route::middleware('module:inventory')->group(function () {
            Route::get('/products', [ProductController::class, 'index'])->middleware('permission:products.view')->name('products.index');
            Route::post('/products', [ProductController::class, 'store'])->middleware('permission:products.create')->name('products.store');
            Route::get('/products/{product}/stock-history', [ProductController::class, 'stockHistory'])->middleware('permission:products.view')->name('products.stock-history');
            Route::put('/products/{product}', [ProductController::class, 'update'])->middleware('permission:products.update')->name('products.update');

            Route::get('/stock', [StockController::class, 'index'])->middleware('permission:inventory.view')->name('stock.index');
            Route::post('/stock', [StockController::class, 'store'])->middleware('permission:inventory.adjust')->name('stock.store');
            Route::get('/stock/quick', [StockController::class, 'quick'])->middleware('permission:inventory.view')->name('stock.quick');
            Route::post('/stock/quick', [StockController::class, 'quickStore'])->middleware('permission:inventory.adjust')->name('stock.quick.store');
        });

        Route::middleware('module:inventory')->group(function () {
            Route::get('/price-lists', [PriceListController::class, 'index'])->middleware('permission:price_lists.view')->name('price-lists.index');
            Route::get('/price-lists/create', [PriceListController::class, 'create'])->middleware('permission:price_lists.manage')->name('price-lists.create');
            Route::post('/price-lists', [PriceListController::class, 'store'])->middleware('permission:price_lists.manage')->name('price-lists.store');
            Route::get('/price-lists/{priceType}/edit', [PriceListController::class, 'edit'])->middleware('permission:price_lists.manage')->name('price-lists.edit');
            Route::patch('/price-lists/{priceType}', [PriceListController::class, 'update'])->middleware('permission:price_lists.manage')->name('price-lists.update');
            Route::delete('/price-lists/{priceType}', [PriceListController::class, 'destroy'])->middleware('permission:price_lists.manage')->name('price-lists.destroy');
            Route::post('/price-lists/{priceType}/set-default', [PriceListController::class, 'setDefault'])->middleware('permission:price_lists.manage')->name('price-lists.set-default');
            Route::get('/price-lists/{priceType}/prices', [PriceListController::class, 'prices'])->middleware('permission:price_lists.manage')->name('price-lists.prices');
            Route::patch('/price-lists/{priceType}/prices', [PriceListController::class, 'updatePrices'])->middleware('permission:price_lists.manage')->name('price-lists.prices.update');
        });

        Route::middleware(['module:branches'])->group(function () {
            Route::post('/branches/active', [BranchController::class, 'activate'])->name('branches.active');

            Route::get('/inventory/transfers', [InventoryTransferController::class, 'index'])->middleware('permission:inventory.transfers.view')->name('inventory.transfers.index');
            Route::get('/inventory/transfers/create', [InventoryTransferController::class, 'create'])->middleware('permission:inventory.transfers.create')->name('inventory.transfers.create');
            Route::post('/inventory/transfers', [InventoryTransferController::class, 'store'])->middleware('permission:inventory.transfers.create')->name('inventory.transfers.store');
            Route::get('/inventory/transfers/{transfer}', [InventoryTransferController::class, 'show'])->middleware('permission:inventory.transfers.view')->name('inventory.transfers.show');
        });

        Route::middleware('module:cash_register')->group(function () {
            Route::get('/cash-register', [CashRegisterController::class, 'index'])->middleware('permission:cash_register.view')->name('cash-register.index');
            Route::post('/cash-register/open', [CashRegisterController::class, 'open'])->middleware('permission:cash_register.open')->name('cash-register.open');
            Route::post('/cash-register/expenses', [CashRegisterController::class, 'expense'])->middleware('permission:cash_register.expenses')->name('cash-register.expenses.store');
            Route::post('/cash-register/close', [CashRegisterController::class, 'close'])->middleware('permission:cash_register.close')->name('cash-register.close');
            Route::get('/cash-register/closings', [CashRegisterController::class, 'closings'])->middleware('permission:cash_register.view')->name('cash-register.closings.index');
            Route::get('/cash-register/closings/{session}', [CashRegisterController::class, 'closingShow'])->middleware('permission:cash_register.view')->name('cash-register.closings.show');
            Route::get('/cash-register/closings/{session}/print', [CashRegisterController::class, 'closingPrint'])->middleware('permission:cash_register.view')->name('cash-register.closings.print');
        });

        Route::middleware('module:purchases')->group(function () {
            Route::get('/purchases', [PurchaseController::class, 'index'])->middleware('permission:purchases.view')->name('purchases.index');
            Route::get('/purchases/create', [PurchaseController::class, 'create'])->middleware('permission:purchases.create')->name('purchases.create');
            Route::post('/purchases', [PurchaseController::class, 'store'])->middleware('permission:purchases.create')->name('purchases.store');
            Route::get('/purchases/{purchase}', [PurchaseController::class, 'show'])->middleware('permission:purchases.view')->name('purchases.show');
        });

        Route::middleware('module:reports')->group(function () {
            Route::get('/reports/sales', [ReportController::class, 'sales'])->middleware('permission:reports.sales.view')->name('reports.sales');
            Route::get('/reports/sales/export/excel', [ReportController::class, 'salesExportExcel'])->middleware('permission:reports.sales.view')->name('reports.sales.export.excel');
            Route::get('/reports/sales/export/pdf', [ReportController::class, 'salesExportPdf'])->middleware('permission:reports.sales.view')->name('reports.sales.export.pdf');
            Route::get('/reports/low-stock', [ReportController::class, 'lowStock'])->middleware('permission:reports.stock.view')->name('reports.low-stock');
            Route::get('/reports/low-stock/export/excel', [ReportController::class, 'lowStockExportExcel'])->middleware('permission:reports.stock.view')->name('reports.low-stock.export.excel');
            Route::get('/reports/top-products', [ReportController::class, 'topProducts'])->middleware('permission:reports.top_products.view')->name('reports.top-products');
            Route::get('/reports/top-products/export/excel', [ReportController::class, 'topProductsExportExcel'])->middleware('permission:reports.top_products.view')->name('reports.top-products.export.excel');
        });

        Route::middleware('tenant.users')->group(function () {
            Route::get('/users', [TenantUserController::class, 'index'])->middleware('permission:users.view')->name('users.index');
            Route::post('/users', [TenantUserController::class, 'store'])->middleware('permission:users.create,users.assign_roles')->name('users.store');
            Route::put('/users/{user}', [TenantUserController::class, 'update'])->middleware('permission:users.update,users.assign_roles')->name('users.update');
            Route::patch('/users/{user}/toggle-active', [TenantUserController::class, 'toggleActive'])->middleware('permission:any:users.update|users.delete')->name('users.toggle-active');
            Route::put('/users/{user}/password', [TenantUserController::class, 'resetPassword'])->middleware('permission:users.update')->name('users.password');
        });
    });

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
