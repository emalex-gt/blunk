<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\CashRegisterController;
use App\Http\Controllers\CompanySettingsController;
use App\Http\Controllers\InventoryTransferController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\TenantUserController;
use App\Http\Controllers\SuperAdmin\DashboardController as SuperAdminDashboardController;
use App\Http\Controllers\SuperAdmin\TenantController as SuperAdminTenantController;
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
    ->middleware(['auth', 'verified', 'tenant.active'])
    ->name('dashboard');

Route::middleware(['auth', 'super.admin'])
    ->prefix('super-admin')
    ->name('super-admin.')
    ->group(function () {
        Route::get('/', SuperAdminDashboardController::class)->name('dashboard');
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
        Route::get('/pos', [SaleController::class, 'create'])->middleware('module:pos')->name('sales.create');
        Route::post('/sales', [SaleController::class, 'store'])->middleware('module:pos')->name('sales.store');
        Route::get('/sales/cancelled', fn () => redirect()->route('reports.sales', ['status' => 'cancelled']))
            ->name('sales.cancelled');
        Route::get('/sales/{sale}', [SaleController::class, 'show'])->name('sales.show');
        Route::post('/sales/{sale}/cancel', [SaleController::class, 'cancel'])->name('sales.cancel');
        Route::get('/sales/{sale}/receipt', [SaleController::class, 'receipt'])->name('sales.receipt');
        Route::get('/sales/{sale}/fel-document', [SaleController::class, 'felDocument'])->middleware('module:fel_gt')->name('sales.fel-document');
        Route::get('/sales/{sale}/fel-download/{format}', [SaleController::class, 'felDownload'])
            ->middleware('module:fel_gt')
            ->whereIn('format', ['xml', 'pdf'])
            ->name('sales.fel-download');
        Route::get('/sales/{sale}/fel-print', [SaleController::class, 'felPrint'])->middleware('module:fel_gt')->name('sales.fel-print');
        Route::get('/sales/{sale}/invoice-document', [SaleController::class, 'invoiceDocument'])->middleware('module:fel_gt')->name('sales.invoice-document');
        Route::get('/customers/lookup/nit', [CustomerController::class, 'lookupNit'])->middleware('module:customers')->name('customers.lookup.nit');
        Route::get('/customers/gt/nit-lookup', [CustomerController::class, 'lookupGuatemalaNit'])->middleware('module:fel_gt')->name('customers.gt.nit-lookup');
        Route::get('/customers/{customer}/products/{product}/last-price', [SaleController::class, 'lastCustomerProductPrice'])
            ->middleware('module:pos')
            ->name('customers.products.last-price');

        Route::get('/settings/company', [CompanySettingsController::class, 'edit'])->name('settings.company.edit');
        Route::post('/settings/company', [CompanySettingsController::class, 'update'])->name('settings.company.update');
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
            Route::get('/products', [ProductController::class, 'index'])->name('products.index');
            Route::post('/products', [ProductController::class, 'store'])->name('products.store');
            Route::get('/products/{product}/stock-history', [ProductController::class, 'stockHistory'])->name('products.stock-history');
            Route::put('/products/{product}', [ProductController::class, 'update'])->name('products.update');

            Route::get('/stock', [StockController::class, 'index'])->name('stock.index');
            Route::post('/stock', [StockController::class, 'store'])->name('stock.store');
            Route::get('/stock/quick', [StockController::class, 'quick'])->name('stock.quick');
            Route::post('/stock/quick', [StockController::class, 'quickStore'])->name('stock.quick.store');
        });

        Route::middleware(['module:branches'])->group(function () {
            Route::post('/branches/active', [BranchController::class, 'activate'])->name('branches.active');
            Route::get('/branches', [BranchController::class, 'index'])->name('branches.index');
            Route::get('/branches/create', [BranchController::class, 'create'])->name('branches.create');
            Route::post('/branches', [BranchController::class, 'store'])->name('branches.store');
            Route::get('/branches/{branch}/edit', [BranchController::class, 'edit'])->name('branches.edit');
            Route::put('/branches/{branch}', [BranchController::class, 'update'])->name('branches.update');

            Route::get('/inventory/transfers', [InventoryTransferController::class, 'index'])->name('inventory.transfers.index');
            Route::get('/inventory/transfers/create', [InventoryTransferController::class, 'create'])->name('inventory.transfers.create');
            Route::post('/inventory/transfers', [InventoryTransferController::class, 'store'])->name('inventory.transfers.store');
            Route::get('/inventory/transfers/{transfer}', [InventoryTransferController::class, 'show'])->name('inventory.transfers.show');
        });

        Route::middleware('module:cash_register')->group(function () {
            Route::get('/cash-register', [CashRegisterController::class, 'index'])->name('cash-register.index');
            Route::post('/cash-register/open', [CashRegisterController::class, 'open'])->name('cash-register.open');
            Route::post('/cash-register/expenses', [CashRegisterController::class, 'expense'])->name('cash-register.expenses.store');
            Route::post('/cash-register/close', [CashRegisterController::class, 'close'])->name('cash-register.close');
            Route::get('/cash-register/closings', [CashRegisterController::class, 'closings'])->name('cash-register.closings.index');
            Route::get('/cash-register/closings/{session}', [CashRegisterController::class, 'closingShow'])->name('cash-register.closings.show');
            Route::get('/cash-register/closings/{session}/print', [CashRegisterController::class, 'closingPrint'])->name('cash-register.closings.print');
        });

        Route::middleware('module:purchases')->group(function () {
            Route::get('/purchases', [PurchaseController::class, 'index'])->name('purchases.index');
            Route::get('/purchases/create', [PurchaseController::class, 'create'])->name('purchases.create');
            Route::post('/purchases', [PurchaseController::class, 'store'])->name('purchases.store');
            Route::get('/purchases/{purchase}', [PurchaseController::class, 'show'])->name('purchases.show');
        });

        Route::middleware('module:reports')->group(function () {
            Route::get('/reports/sales', [ReportController::class, 'sales'])->name('reports.sales');
            Route::get('/reports/sales/export/excel', [ReportController::class, 'salesExportExcel'])->name('reports.sales.export.excel');
            Route::get('/reports/sales/export/pdf', [ReportController::class, 'salesExportPdf'])->name('reports.sales.export.pdf');
            Route::get('/reports/low-stock', [ReportController::class, 'lowStock'])->name('reports.low-stock');
            Route::get('/reports/low-stock/export/excel', [ReportController::class, 'lowStockExportExcel'])->name('reports.low-stock.export.excel');
            Route::get('/reports/top-products', [ReportController::class, 'topProducts'])->name('reports.top-products');
            Route::get('/reports/top-products/export/excel', [ReportController::class, 'topProductsExportExcel'])->name('reports.top-products.export.excel');
        });

        Route::middleware('tenant.users')->group(function () {
            Route::get('/users', [TenantUserController::class, 'index'])->name('users.index');
            Route::post('/users', [TenantUserController::class, 'store'])->name('users.store');
            Route::put('/users/{user}', [TenantUserController::class, 'update'])->name('users.update');
            Route::patch('/users/{user}/toggle-active', [TenantUserController::class, 'toggleActive'])->name('users.toggle-active');
            Route::put('/users/{user}/password', [TenantUserController::class, 'resetPassword'])->name('users.password');
        });
    });

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
