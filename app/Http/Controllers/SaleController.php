<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\PriceType;
use App\Models\Sale;
use App\Models\Business;
use App\Models\CashRegisterSession;
use App\Models\Category;
use App\Models\Customer;
use App\Models\ElectronicDocument;
use App\Models\StockMovement;
use App\Models\TenantSetting;
use App\Models\TenantFelSetting;
use App\Services\Fel\FelException;
use App\Services\Fel\Providers\Digifact\DigifactInvoiceService;
use App\Support\CashRegister;
use App\Support\BranchInventory;
use App\Support\Permissions;
use App\Support\PriceLists;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SaleController extends Controller
{
    public function create(Request $request): Response
    {
        $businessId = currentBusinessId();
        $business = Business::query()->select('id', 'country')->findOrFail($businessId);
        $felSettings = $business->country === 'GT'
            ? TenantFelSetting::query()->where('business_id', $businessId)->first()
            : null;
        $branchesEnabled = BranchInventory::branchesEnabled($businessId);
        $activeBranch = BranchInventory::activeBranch($businessId);
        $tenantSettings = TenantSetting::query()->where('business_id', $businessId)->first();
        $priceTypes = PriceLists::active($businessId);
        $defaultPriceType = $priceTypes->firstWhere('is_default', true) ?: $priceTypes->first();
        $productsQuery = Product::query()
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->with(['prices' => fn ($query) => $query
                ->where('business_id', $businessId)
                ->where('is_active', true)
                ->select(['id', 'product_id', 'price_type_id', 'price'])]);
        BranchInventory::restrictProductsToBranch($productsQuery, $businessId, $activeBranch->id);
        $products = $productsQuery
            ->orderBy('name')
            ->get(['id', 'category_id', 'name', 'code', 'barcode', 'cost_price', 'sale_price', 'stock', 'min_stock', 'location', 'image_url']);
        BranchInventory::applyBranchStockAndPrices($products, $businessId, $activeBranch->id);
        $this->applyBranchPriceListPayload($products, $businessId, $activeBranch->id);

        return Inertia::render('Sales/POS', [
            'fel' => [
                'enabled' => module_enabled('fel_gt', $businessId) && $business->country === 'GT' && (bool) ($felSettings?->enabled),
                'configured' => module_enabled('fel_gt', $businessId) && $business->country === 'GT' && (bool) ($felSettings?->isConfigured()),
                'missing_fields' => $felSettings?->missingConfigurationFields() ?? [],
                'provider' => $felSettings?->provider ?? 'digifact',
                'environment' => $felSettings?->environment ?? 'test',
            ],
            'hasOpenCashRegister' => CashRegisterSession::query()
                ->where('business_id', $businessId)
                ->where('status', 'open')
                ->exists(),
            'branches_enabled' => $branchesEnabled,
            'active_branch' => $branchesEnabled ? $activeBranch : null,
            'price_types' => $priceTypes->map(fn (PriceType $priceType) => [
                'id' => $priceType->id,
                'name' => $priceType->name,
                'is_default' => $priceType->is_default,
            ])->values(),
            'default_price_type_id' => $defaultPriceType?->id,
            'price_settings' => [
                'allow_manual_price' => (bool) ($tenantSettings?->allow_manual_price ?? false),
                'remember_last_customer_product_price' => (bool) ($tenantSettings?->remember_last_customer_product_price ?? false),
            ],
            'products' => $products,
            'categories' => Category::query()
                ->where('business_id', $businessId)
                ->orderBy('name')
                ->get(['id', 'name']),
            'customers' => Customer::query()
                ->where('business_id', $businessId)
                ->latest()
                ->limit(50)
                ->get(['id', 'name', 'doc_type', 'doc_number', 'tax_condition', 'address', 'phone', 'country', 'is_final_consumer', 'name_locked', 'tax_lookup_verified_at']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $business = Business::query()->findOrFail(currentBusinessId());
        $country = $business->country ?: 'GT';
        $paymentMethods = ['cash', 'card', 'transfer', 'check'];

        if ($country === 'AR') {
            $paymentMethods[] = 'mercadopago';
        }

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
            'document_type' => ['required', 'in:invoice,receipt'],
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.method' => ['required', 'in:'.implode(',', $paymentMethods)],
            'payments.*.amount' => ['required', 'numeric', 'gt:0'],
            'payments.*.reference' => ['nullable', 'string', 'max:255'],
            'payments.*.details' => ['nullable', 'array'],
            'payments.*.details.authorization' => ['nullable', 'string', 'max:100'],
            'payments.*.details.bank' => ['nullable', 'string', 'max:100'],
            'payments.*.details.transfer_reference' => ['nullable', 'string', 'max:100'],
            'payments.*.details.check_number' => ['nullable', 'string', 'max:100'],
            'payments.*.details.mercadopago_reference' => ['nullable', 'string', 'max:100'],
            'customer' => ['nullable', 'array'],
            'customer.id' => ['nullable', 'integer', 'exists:customers,id'],
            'customer.name' => ['nullable', 'string', 'max:255'],
            'customer.doc_type' => ['nullable', 'string', 'max:50'],
            'customer.doc_number' => ['nullable', 'string', 'max:50'],
            'customer.tax_condition' => ['nullable', 'string', 'max:100'],
            'customer.address' => ['nullable', 'string', 'max:255'],
            'customer.phone' => ['nullable', 'string', 'max:50'],
            'customer.country' => ['nullable', 'in:GT,AR'],
            'customer.consumidor_final' => ['nullable', 'boolean'],
            'customer.name_locked' => ['nullable', 'boolean'],
            'customer.tax_lookup_verified_at' => ['nullable', 'date'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.price_type_id' => ['nullable', 'integer', 'exists:price_types,id'],
            'items.*.unit_price' => ['nullable', 'numeric', 'gt:0'],
            'items.*.price_source' => ['nullable', 'in:price_list,last_customer_price,manual'],
            'items.*.manual_price' => ['nullable', 'boolean'],
            'discount' => ['nullable', 'array'],
            'discount.type' => ['required_with:discount', 'in:fixed,percent'],
            'discount.value' => ['required_with:discount', 'numeric', 'gt:0'],
            'discount.reason' => ['required_with:discount', 'string', 'max:1000'],
        ], [
            'items.*.quantity.required' => 'La cantidad debe ser un número entero.',
            'items.*.quantity.integer' => 'La cantidad debe ser un número entero.',
            'items.*.quantity.min' => 'La cantidad debe ser un número entero.',
            'discount.type.required_with' => 'Selecciona el tipo de descuento.',
            'discount.type.in' => 'Selecciona un tipo de descuento válido.',
            'discount.value.required_with' => 'Ingresa el valor del descuento.',
            'discount.value.numeric' => 'Ingresa un valor de descuento válido.',
            'discount.value.gt' => 'Ingresa un valor de descuento válido.',
            'discount.reason.required_with' => 'El motivo del descuento es obligatorio.',
        ]);

        if (($data['document_type'] ?? null) === 'invoice') {
            $this->validateInvoiceConfiguration($business, $data['customer'] ?? null);
        }

        $saleId = DB::transaction(function () use ($request, $data, $business) {
            $businessId = currentBusinessId();
            $branch = BranchInventory::activeBranch($businessId);
            $openSession = CashRegister::requireOpenSession(
                $businessId,
                'Debes abrir caja antes de registrar ventas.',
                true,
            );
            $cashAmount = CashRegister::cashAmountFromPayments($data['payments']);
            $customer = $this->resolveCustomer($request, $data['customer'] ?? null);
            $customerSnapshot = $this->saleCustomerSnapshot($customer, $data['customer'] ?? null);
            $sale = Sale::create([
                'business_id' => $businessId,
                'branch_id' => $branch->id,
                'customer_id' => $customer?->id,
                ...$customerSnapshot,
                'payment_method' => $data['payments'][0]['method'],
                'document_type' => $data['document_type'],
                'status' => 'completed',
                'note' => $data['note'] ?? null,
                'created_by' => $request->user()->id,
                'total' => 0,
            ]);

            $subtotalBeforeDiscount = 0;
            $saleLines = [];

            foreach ($data['items'] as $item) {
                $quantity = (int) $item['quantity'];
                $product = Product::query()
                    ->where('business_id', $businessId)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->find($item['product_id']);

                if (! $product) {
                    throw ValidationException::withMessages([
                        'items' => 'Uno o mas productos no estan disponibles para este negocio.',
                    ]);
                }

                BranchInventory::ensureProductInBranch($product, $branch->id);
                $branchStock = (float) (BranchInventory::stockMap($businessId, [$product->id], $branch->id)[$product->id] ?? 0);

                if ($branchStock < $quantity) {
                    throw ValidationException::withMessages([
                        'items' => "Stock insuficiente para {$product->name}. Disponible: {$branchStock}.",
                    ]);
                }

                $resolvedPrice = $this->resolveLinePrice(
                    $request,
                    $product,
                    $item,
                    $customer,
                    $branch->id,
                );
                $unitPrice = $resolvedPrice['unit_price'];
                $lineTotal = round($unitPrice * $quantity, 2);
                $subtotalBeforeDiscount += $lineTotal;
                $saleLines[] = [
                    'product' => $product,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'original_price' => $resolvedPrice['original_price'],
                    'price_type_id' => $resolvedPrice['price_type_id'],
                    'price_source' => $resolvedPrice['price_source'],
                    'manual_price' => $resolvedPrice['manual_price'],
                    'line_total' => $lineTotal,
                ];
            }

            $discount = $this->resolveSaleDiscount(
                $request,
                $data['discount'] ?? null,
                round($subtotalBeforeDiscount, 2),
            );
            $lineDiscounts = $this->distributeDiscount($saleLines, $discount['amount'], round($subtotalBeforeDiscount, 2));
            $total = round($subtotalBeforeDiscount - $discount['amount'], 2);

            $paymentsTotal = collect($data['payments'])
                ->sum(fn (array $payment) => (float) $payment['amount']);

            if (round($paymentsTotal, 2) !== round($total, 2)) {
                throw ValidationException::withMessages([
                    'payments' => 'La suma de los pagos debe ser igual al total de la venta.',
                ]);
            }

            if (
                $business->country === 'GT'
                && ($data['document_type'] ?? null) === 'invoice'
                && $this->isFinalConsumerCustomer($data['customer'] ?? null)
                && round($total, 2) >= 2500
            ) {
                throw ValidationException::withMessages([
                    'customer.doc_number' => 'No se puede emitir factura a Consumidor Final por Q 2,500.00 o más. Debes ingresar un NIT válido.',
                    'document_type' => 'No se puede emitir factura a Consumidor Final por Q 2,500.00 o más. Debes ingresar un NIT válido.',
                ]);
            }

            $sale->update([
                'total' => $total,
                'subtotal_before_discount' => round($subtotalBeforeDiscount, 2),
                'discount_type' => $discount['type'],
                'discount_value' => $discount['value'],
                'discount_amount' => $discount['amount'],
                'discount_reason' => $discount['reason'],
            ]);

            foreach ($saleLines as $index => $line) {
                /** @var Product $product */
                $product = $line['product'];
                $lineDiscount = $lineDiscounts[$index] ?? 0.0;
                $lineTotalAfterDiscount = round($line['line_total'] - $lineDiscount, 2);

                $sale->items()->create([
                    'business_id' => $businessId,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'quantity' => $line['quantity'],
                    'price_type_id' => $line['price_type_id'],
                    'unit_price' => $line['unit_price'],
                    'original_price' => $line['original_price'],
                    'price_source' => $line['price_source'],
                    'manual_price' => $line['manual_price'],
                    'unit_cost' => $product->cost_price,
                    'total' => $lineTotalAfterDiscount,
                    'discount_amount' => $lineDiscount,
                    'total_before_discount' => $line['line_total'],
                    'total_after_discount' => $lineTotalAfterDiscount,
                ]);

                [$previousStock, $newStock] = BranchInventory::decrease($product, $branch->id, $line['quantity']);

                StockMovement::create([
                    'business_id' => $businessId,
                    'branch_id' => $branch->id,
                    'product_id' => $product->id,
                    'type' => 'sale',
                    'quantity' => -1 * $line['quantity'],
                    'previous_stock' => $previousStock,
                    'new_stock' => $newStock,
                    'note' => stockMovementNote('sale', $sale->id),
                    'created_by' => $request->user()->id,
                ]);
            }

            foreach ($data['payments'] as $payment) {
                $details = $this->paymentDetails($payment['method'], $payment['details'] ?? []);

                $sale->payments()->create([
                    'business_id' => $businessId,
                    'method' => $payment['method'],
                    'amount' => round((float) $payment['amount'], 2),
                    'reference' => $this->paymentReference($payment['method'], $details, $payment['reference'] ?? null),
                    'details' => $details ?: null,
                ]);
            }

            if ($cashAmount > 0) {
                CashRegister::recordMovement(
                    $openSession,
                    'sale_cash',
                    $cashAmount,
                    'sale',
                    $sale->id,
                    stockMovementNote('sale', $sale->id),
                    $request->user()->id,
                );
            }

            if (($data['document_type'] ?? null) === 'invoice') {
                $felSettings = TenantFelSetting::query()->where('business_id', $businessId)->first();
                $document = ElectronicDocument::query()->create([
                    'business_id' => $businessId,
                    'sale_id' => $sale->id,
                    'provider' => 'digifact',
                    'environment' => $felSettings?->environment ?? 'test',
                    'document_type' => 'invoice',
                    'status' => 'pending',
                    'created_by' => $request->user()->id,
                ]);

                $sale->update([
                    'electronic_document_id' => $document->id,
                    'certification_status' => 'pending',
                ]);
            }

            return $sale->id;
        });

        $certifiedDocument = null;

        if (($data['document_type'] ?? null) === 'invoice') {
            $sale = Sale::query()
                ->with(['business', 'customer', 'items.product', 'payments', 'electronicDocument'])
                ->findOrFail($saleId);

            try {
                $certifiedDocument = app(DigifactInvoiceService::class)->certifySale($sale);
            } catch (FelException $exception) {
                throw ValidationException::withMessages([
                    'document_type' => $exception->getMessage() ?: 'No se pudo certificar la factura.',
                ]);
            }
        }

        $redirect = redirect()->route('sales.create')->with('success', 'Venta finalizada.');

        if (($data['document_type'] ?? null) === 'receipt') {
            $redirect->with('receipt_sale_id', $saleId);
        }

        if (($data['document_type'] ?? null) === 'invoice' && $certifiedDocument) {
            $message = 'Factura FEL certificada correctamente.';

            if (filled($certifiedDocument->series) || filled($certifiedDocument->number)) {
                $message .= ' Serie '.($certifiedDocument->series ?: '-').' Número '.($certifiedDocument->number ?: '-').'.';
            }

            $redirect
                ->with('success', $message)
                ->with('fel_success_message', $message)
                ->with('fel_print_sale_id', $saleId)
                ->with('fel_print_url', URL::temporarySignedRoute(
                    'sales.fel-document',
                    now()->addMinutes(10),
                    ['sale' => $saleId],
                ));
        }

        return $redirect;
    }

    public function show(Request $request, Sale $sale): Response
    {
        $this->ensureSaleBelongsToCurrentBusiness($sale);

        $businessId = currentBusinessId();
        $timezone = tenantTimezone($businessId);

        $sale->load([
            'customer:id,name,doc_type,doc_number,address,phone',
            'branch:id,name',
            'createdBy:id,name',
            'cancelledBy:id,name',
            'items.product:id,code,barcode',
            'items.priceType:id,name',
            'payments:id,sale_id,method,amount,reference,details',
            'electronicDocument',
        ]);
        $canViewFelDocuments = $this->userCanViewFelDocuments($request);
        $felResponseMetadata = is_array($sale->fel_raw_response)
            ? $sale->fel_raw_response
            : ($sale->electronicDocument?->response_payload ?? []);

        return Inertia::render('Sales/Show', [
            'sale' => [
                'id' => $sale->id,
                'status' => $sale->status ?? 'completed',
                'document_type' => $sale->document_type ?? 'receipt',
                'created_at_local' => $sale->created_at?->copy()->timezone($timezone)->format('Y-m-d H:i'),
                'cancelled_at_local' => $sale->cancelled_at?->copy()->timezone($timezone)->format('Y-m-d H:i'),
                'cancellation_reason' => $sale->cancellation_reason,
                'total' => (float) $sale->total,
                'subtotal_before_discount' => (float) ($sale->subtotal_before_discount ?? $sale->total),
                'discount_type' => $sale->discount_type,
                'discount_value' => (float) ($sale->discount_value ?? 0),
                'discount_amount' => (float) ($sale->discount_amount ?? 0),
                'discount_reason' => $sale->discount_reason,
                'payment_method' => $sale->payment_method,
                'branch' => $sale->branch ? ['id' => $sale->branch->id, 'name' => $sale->branch->name] : null,
                'note' => $sale->note,
                'created_by' => $sale->createdBy?->name,
                'cancelled_by' => $sale->cancelledBy?->name,
                'certification_status' => $sale->certification_status,
                'fel_uuid' => $sale->fel_uuid,
                'fel_series' => $sale->fel_series,
                'fel_number' => $sale->fel_number,
                'has_fel_xml' => (bool) ($felResponseMetadata['has_xml'] ?? false) || filled($sale->fel_xml_path),
                'has_fel_html' => (bool) ($felResponseMetadata['has_html'] ?? false) || filled($sale->fel_html_path),
                'has_fel_pdf' => (bool) ($felResponseMetadata['has_pdf'] ?? false) || filled($sale->fel_pdf_path) || filled($sale->fel_pdf_url),
                'fel_certified_at' => $sale->fel_certified_at?->copy()->timezone($timezone)->format('Y-m-d H:i'),
                'customer' => $sale->customer ? [
                    'name' => $sale->customer_name ?: $sale->customer->name,
                    'doc_type' => $sale->customer_doc_type ?: $sale->customer->doc_type,
                    'doc_number' => $sale->customer_doc_number ?: $sale->customer->doc_number,
                    'address' => $sale->customer_address ?: $sale->customer->address,
                    'phone' => $sale->customer_phone ?: $sale->customer->phone,
                ] : ($sale->customer_name ? [
                    'name' => $sale->customer_name,
                    'doc_type' => $sale->customer_doc_type,
                    'doc_number' => $sale->customer_doc_number,
                    'address' => $sale->customer_address,
                    'phone' => $sale->customer_phone,
                ] : null),
                'items' => $sale->items->map(fn ($item) => [
                    'id' => $item->id,
                    'product_name' => $item->product_name,
                    'code' => $item->product?->barcode ?: $item->product?->code,
                    'quantity' => (float) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'price_type_name' => $item->priceType?->name,
                    'price_source' => $item->price_source,
                    'manual_price' => (bool) $item->manual_price,
                    'discount_amount' => (float) ($item->discount_amount ?? 0),
                    'total_before_discount' => (float) ($item->total_before_discount ?? $item->total),
                    'total_after_discount' => (float) ($item->total_after_discount ?? $item->total),
                    'total' => (float) $item->total,
                ]),
                'payments' => $sale->payments->map(fn ($payment) => [
                    'id' => $payment->id,
                    'method' => $payment->method,
                    'amount' => (float) $payment->amount,
                    'reference' => $payment->reference,
                    'details' => $payment->details ?? [],
                ]),
                'electronic_document' => $sale->electronicDocument ? [
                    'id' => $sale->electronicDocument->id,
                    'status' => $sale->electronicDocument->status,
                    'uuid' => $sale->electronicDocument->uuid,
                    'series' => $sale->electronicDocument->series,
                    'number' => $sale->electronicDocument->number,
                    'certification_date' => $sale->electronicDocument->certification_date?->copy()->timezone($timezone)->format('Y-m-d H:i'),
                    'error_message' => $sale->electronicDocument->error_message,
                    'cancelled_at' => $sale->electronicDocument->cancelled_at?->copy()->timezone($timezone)->format('Y-m-d H:i'),
                    'has_printable_document' => filled($sale->fel_uuid)
                        || (bool) ($felResponseMetadata['has_pdf'] ?? false)
                        || (bool) ($felResponseMetadata['has_html'] ?? false)
                        || filled($sale->electronicDocument->pdf_base64)
                        || filled($sale->electronicDocument->html),
                    'technical_response' => $canViewFelDocuments
                        ? $sale->electronicDocument->response_payload
                        : null,
                ] : null,
            ],
            'canViewFelDocuments' => $canViewFelDocuments,
            'canCancel' => $this->userCanCancelSale($request, $sale)
                && ($sale->status ?? 'completed') !== 'cancelled',
        ]);
    }

    public function cancel(Request $request, Sale $sale): RedirectResponse
    {
        abort_unless($this->userCanCancelSale($request, $sale), 403);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $sale->load(['electronicDocument', 'payments']);

        if (CashRegister::cashAmountFromPayments($sale->payments) > 0) {
            CashRegister::requireOpenSession(
                currentBusinessId(),
                'Debes abrir caja antes de anular una venta con pago en efectivo.',
                true,
            );
        }

        if (
            $sale->document_type === 'invoice'
            && $sale->electronicDocument
            && $sale->electronicDocument->status === 'certified'
        ) {
            try {
                app(DigifactInvoiceService::class)->cancelElectronicDocument(
                    $sale->electronicDocument,
                    $data['reason'],
                );
            } catch (FelException $exception) {
                throw ValidationException::withMessages([
                    'reason' => $exception->getMessage() ?: 'No se pudo anular la factura electronica.',
                ]);
            }
        }

        DB::transaction(function () use ($request, $sale, $data) {
            $businessId = currentBusinessId();

            $sale = Sale::query()
                ->where('business_id', $businessId)
                ->lockForUpdate()
                ->with(['items', 'payments'])
                ->findOrFail($sale->id);

            if (($sale->status ?? 'completed') === 'cancelled') {
                throw ValidationException::withMessages([
                    'reason' => 'Esta venta ya fue anulada.',
                ]);
            }

            foreach ($sale->items as $item) {
                $product = Product::query()
                    ->where('business_id', $businessId)
                    ->lockForUpdate()
                    ->find($item->product_id);

                if (! $product) {
                    continue;
                }

                $branchId = (int) ($sale->branch_id ?: BranchInventory::defaultBranch($businessId)->id);
                [$previousStock, $newStock] = BranchInventory::increase($product, $branchId, (float) $item->quantity);

                StockMovement::create([
                    'business_id' => $businessId,
                    'branch_id' => $branchId,
                    'product_id' => $product->id,
                    'type' => 'sale_cancel',
                    'quantity' => (float) $item->quantity,
                    'previous_stock' => $previousStock,
                    'new_stock' => $newStock,
                    'note' => stockMovementNote('sale_cancel', $sale->id),
                    'created_by' => $request->user()->id,
                ]);
            }

            $cashAmount = CashRegister::cashAmountFromPayments($sale->payments);

            if ($cashAmount > 0) {
                $cashSession = CashRegister::requireOpenSession(
                    $businessId,
                    'Debes abrir caja antes de anular una venta con pago en efectivo.',
                    true,
                );

                CashRegister::recordMovement(
                    $cashSession,
                    'sale_cash_cancel',
                    -1 * $cashAmount,
                    'sale',
                    $sale->id,
                    stockMovementNote('sale_cancel', $sale->id),
                    $request->user()->id,
                );
            }

            $sale->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $request->user()->id,
                'cancellation_reason' => $data['reason'],
            ]);
        });

        return redirect()->route('sales.show', $sale)->with('success', 'Venta anulada correctamente.');
    }

    public function receipt(Sale $sale)
    {
        $this->ensureSaleBelongsToCurrentBusiness($sale);

        $businessId = currentBusinessId();
        $business = \App\Models\Business::query()->select('id', 'name', 'country')->find($businessId);
        $timezone = tenantTimezone($business);
        $settings = TenantSetting::query()->where('business_id', $businessId)->first();

        $sale->load([
            'customer:id,name,doc_type,doc_number,address,phone',
            'createdBy:id,name',
            'cancelledBy:id,name',
            'items.product:id,code,barcode',
            'payments:id,sale_id,method,amount,reference,details',
        ]);

        $receiptFormat = $settings?->receipt_format === 'document' ? 'document' : 'ticket';

        return view("sales.receipt-{$receiptFormat}", [
            'paperSize' => ($business?->country ?? 'GT') === 'AR' ? 'A4' : 'Letter',
            'company' => [
                'logo_url' => $settings?->company_logo_url,
                'name' => $settings?->company_name ?: $business?->name,
                'tax_id' => $settings?->company_tax_id,
                'address' => $settings?->company_address,
                'phone' => $settings?->company_phone,
            ],
            'business' => $business,
            'sale' => $sale,
            'items' => $sale->items,
            'payments' => $sale->payments,
            'createdAtLocal' => $sale->created_at?->copy()->timezone($timezone)->format('Y-m-d H:i'),
            'cancelledAtLocal' => $sale->cancelled_at?->copy()->timezone($timezone)->format('Y-m-d H:i'),
        ]);
    }

    public function invoiceDocument(Sale $sale)
    {
        $this->authorizeFelDocumentAccess(request());
        $this->ensureSaleBelongsToCurrentBusiness($sale);

        $sale->load('electronicDocument');
        $document = $sale->electronicDocument;

        abort_unless($sale->document_type === 'invoice' && $document?->status === 'certified', 404);

        if (filled($document->pdf_base64)) {
            $pdf = base64_decode($document->pdf_base64, true);

            if ($pdf !== false) {
                return response($pdf, 200, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'inline; filename="factura-'.$sale->id.'.pdf"',
                ]);
            }
        }

        if (filled($document->html)) {
            return response()->view('sales.invoice-html', [
                'html' => $document->html,
                'sale' => $sale,
            ]);
        }

        return response('No hay documento imprimible disponible.', 404);
    }

    public function lastCustomerProductPrice(Request $request, Customer $customer, Product $product)
    {
        $businessId = currentBusinessId();
        abort_unless((int) $customer->business_id === (int) $businessId, 403);
        abort_unless((int) $product->business_id === (int) $businessId, 403);

        $settings = TenantSetting::query()->where('business_id', $businessId)->first();

        if (! (bool) ($settings?->remember_last_customer_product_price ?? false) || $this->isFinalConsumerModel($customer)) {
            return response()->json(['price' => null]);
        }

        $line = PriceLists::lastCustomerProductPrice($businessId, $customer->id, $product->id);

        return response()->json([
            'price' => $line ? (float) $line->unit_price : null,
            'price_type_id' => $line?->price_type_id,
            'sale_date' => $line?->sale?->created_at?->toDateString(),
        ]);
    }

    public function felPrint(Request $request, Sale $sale)
    {
        return $this->felDocument($request, $sale);
    }

    public function felDocument(Request $request, Sale $sale)
    {
        $this->ensureSaleBelongsToCurrentBusiness($sale);
        $this->authorizeFelDocumentAccess($request, allowSignedUrl: true);

        abort_unless($sale->document_type === 'invoice' && filled($sale->fel_uuid), 404);

        $sale->load('electronicDocument');

        try {
            $document = app(DigifactInvoiceService::class)->getDocumentContent($sale, 'HTML');
            $html = $this->injectFelAutoPrintScript((string) $document['content']);

            return response($html, 200, [
                'Content-Type' => $document['content_type'],
                'Content-Disposition' => 'inline; filename="fel-'.$this->safeFilename($sale->fel_uuid).'.html"',
            ]);
        } catch (FelException) {
            return response('No se pudo obtener el documento imprimible desde Digifact.', 404);
        }
    }

    public function felDownload(Request $request, Sale $sale, string $format)
    {
        $this->ensureSaleBelongsToCurrentBusiness($sale);
        $this->authorizeFelDocumentAccess($request);

        abort_unless($sale->document_type === 'invoice' && filled($sale->fel_uuid), 404);

        $sale->load('electronicDocument');
        $format = strtoupper($format);

        abort_unless(in_array($format, ['XML', 'PDF'], true), 404);

        try {
            $document = app(DigifactInvoiceService::class)->getDocumentContent($sale, $format);

            return response($document['content'], 200, [
                'Content-Type' => $document['content_type'],
                'Content-Disposition' => 'attachment; filename="fel-'.$this->safeFilename($sale->fel_uuid).'.'.$document['extension'].'"',
            ]);
        } catch (FelException) {
            return response('No se pudo obtener el documento imprimible desde Digifact.', 404);
        }
    }

    private function resolveSaleDiscount(Request $request, ?array $discountData, float $subtotal): array
    {
        if (! $discountData) {
            return [
                'type' => null,
                'value' => 0.0,
                'amount' => 0.0,
                'reason' => null,
            ];
        }

        if (! module_enabled('discounts')) {
            abort(403, 'Este modulo no esta habilitado para esta empresa.');
        }

        if (! Permissions::userHas($request->user(), Permissions::SALES_DISCOUNT_APPLY)) {
            abort(403, 'No tienes permiso para aplicar descuentos.');
        }

        $type = $discountData['type'] ?? null;
        $value = round((float) ($discountData['value'] ?? 0), 2);
        $reason = trim((string) ($discountData['reason'] ?? ''));
        $amount = $type === 'percent'
            ? round($subtotal * ($value / 100), 2)
            : $value;

        if ($amount <= 0 || $amount >= $subtotal) {
            throw ValidationException::withMessages([
                'discount.value' => 'El descuento no puede ser mayor o igual al total.',
            ]);
        }

        return [
            'type' => $type,
            'value' => $value,
            'amount' => round($amount, 2),
            'reason' => $reason,
        ];
    }

    private function distributeDiscount(array $saleLines, float $discountAmount, float $subtotal): array
    {
        if ($discountAmount <= 0 || $subtotal <= 0 || $saleLines === []) {
            return array_fill(0, count($saleLines), 0.0);
        }

        $discounts = [];
        $allocated = 0.0;
        $lastIndex = count($saleLines) - 1;

        foreach ($saleLines as $index => $line) {
            $lineSubtotal = round((float) $line['line_total'], 2);

            if ($index === $lastIndex) {
                $lineDiscount = round($discountAmount - $allocated, 2);
            } else {
                $lineDiscount = round(($lineSubtotal / $subtotal) * $discountAmount, 2);
                $allocated += $lineDiscount;
            }

            if ($lineDiscount < 0 || ($lineDiscount > 0 && $lineDiscount >= $lineSubtotal)) {
                throw ValidationException::withMessages([
                    'discount.value' => 'El descuento no puede ser mayor o igual al total.',
                ]);
            }

            $discounts[$index] = $lineDiscount;
        }

        return $discounts;
    }

    private function resolveLinePrice(Request $request, Product $product, array $item, ?Customer $customer, int $branchId): array
    {
        $businessId = (int) $product->business_id;
        $settings = TenantSetting::query()->where('business_id', $businessId)->first();
        $priceSource = $item['price_source'] ?? PriceLists::SOURCE_PRICE_LIST;
        $manualPrice = (bool) ($item['manual_price'] ?? false) || $priceSource === PriceLists::SOURCE_MANUAL;
        $priceTypeId = $item['price_type_id'] ?? null;
        $defaultPrice = PriceLists::priceForProduct($product, $priceTypeId ? (int) $priceTypeId : null, $branchId);
        $unitPrice = (float) $defaultPrice['price'];
        $originalPrice = $unitPrice;

        if ($manualPrice) {
            if (! (bool) ($settings?->allow_manual_price ?? false)) {
                abort(403, 'No tienes permiso para aplicar precio manual.');
            }

            $unitPrice = round((float) ($item['unit_price'] ?? 0), 2);

            if ($unitPrice <= 0) {
                throw ValidationException::withMessages([
                    'items' => 'El precio manual debe ser mayor a 0.',
                ]);
            }

            return [
                'unit_price' => $unitPrice,
                'original_price' => $originalPrice,
                'price_type_id' => $defaultPrice['price_type_id'],
                'price_source' => PriceLists::SOURCE_MANUAL,
                'manual_price' => true,
            ];
        }

        if ($priceSource === PriceLists::SOURCE_LAST_CUSTOMER) {
            if (! (bool) ($settings?->remember_last_customer_product_price ?? false)) {
                throw ValidationException::withMessages([
                    'items' => 'La opcion de recordar ultimo precio por cliente no esta habilitada.',
                ]);
            }

            if (! $customer || $this->isFinalConsumerModel($customer)) {
                throw ValidationException::withMessages([
                    'items' => 'El ultimo precio por cliente no aplica para Consumidor Final.',
                ]);
            }

            $lastLine = PriceLists::lastCustomerProductPrice($businessId, $customer->id, $product->id);

            if ($lastLine) {
                return [
                    'unit_price' => round((float) $lastLine->unit_price, 2),
                    'original_price' => $originalPrice,
                    'price_type_id' => $lastLine->price_type_id ?: $defaultPrice['price_type_id'],
                    'price_source' => PriceLists::SOURCE_LAST_CUSTOMER,
                    'manual_price' => false,
                ];
            }
        }

        return [
            'unit_price' => $unitPrice,
            'original_price' => $originalPrice,
            'price_type_id' => $defaultPrice['price_type_id'],
            'price_source' => PriceLists::SOURCE_PRICE_LIST,
            'manual_price' => false,
        ];
    }

    private function applyBranchPriceListPayload($products, int $businessId, int $branchId): void
    {
        if (BranchInventory::pricingScope($businessId) !== 'branch' || $products->isEmpty()) {
            return;
        }

        $branchPrices = DB::table('branch_product_prices')
            ->where('business_id', $businessId)
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->whereIn('product_id', $products->pluck('id')->all())
            ->get(['product_id', 'price_type_id', 'price'])
            ->groupBy('product_id');

        $products->each(function (Product $product) use ($branchPrices) {
            $prices = collect($product->prices ?? [])
                ->map(fn ($price) => [
                    'id' => $price->id ?? null,
                    'product_id' => $price->product_id,
                    'price_type_id' => $price->price_type_id,
                    'price' => $price->price,
                ])
                ->keyBy('price_type_id');

            foreach ($branchPrices->get($product->id, collect()) as $branchPrice) {
                if ($branchPrice->price_type_id) {
                    $prices->put($branchPrice->price_type_id, [
                        'id' => null,
                        'product_id' => $product->id,
                        'price_type_id' => $branchPrice->price_type_id,
                        'price' => $branchPrice->price,
                    ]);
                }
            }

            $product->setRelation('prices', $prices->values());
        });
    }

    private function saleCustomerSnapshot(?Customer $customer, ?array $customerData): array
    {
        $customerData ??= [];

        return [
            'customer_name' => trim((string) ($customerData['name'] ?? '')) ?: $customer?->name,
            'customer_doc_type' => $customerData['doc_type'] ?? $customer?->doc_type,
            'customer_doc_number' => $this->normalizeDocument($customerData['doc_number'] ?? $customer?->doc_number),
            'customer_address' => trim((string) ($customerData['address'] ?? '')) ?: $customer?->address,
            'customer_postal_code' => $customer?->postal_code,
            'customer_municipality' => $customer?->municipality,
            'customer_department' => $customer?->department,
            'customer_country' => $customer?->country ?: 'GT',
            'customer_phone' => trim((string) ($customerData['phone'] ?? '')) ?: $customer?->phone,
        ];
    }

    private function resolveCustomer(Request $request, ?array $customerData): ?Customer
    {
        $businessId = currentBusinessId();
        $country = \App\Models\Business::query()->whereKey($businessId)->value('country') ?: 'GT';
        $customerData ??= [];
        $customerCountry = $customerData['country'] ?? $country;
        $docType = $customerData['doc_type'] ?? null;
        $docNumber = $this->normalizeDocument($customerData['doc_number'] ?? null);
        $name = trim((string) ($customerData['name'] ?? ''));

        if ($customerCountry === 'GT' && ($customerData['consumidor_final'] ?? false)) {
            $name = $name !== '' ? $name : 'Consumidor Final';
            $customer = Customer::query()
                ->where('business_id', $businessId)
                ->where('doc_type', 'CF')
                ->where('doc_number', 'CF')
                ->whereRaw('LOWER(name) = ?', [strtolower($name)])
                ->first();

            $payload = [
                'name' => $name,
                'doc_type' => 'CF',
                'doc_number' => 'CF',
                'tax_condition' => null,
                'address' => $customerData['address'] ?? null,
                'phone' => $customerData['phone'] ?? null,
                'country' => 'GT',
                'is_final_consumer' => true,
                'name_locked' => false,
            ];

            if ($customer) {
                $customer->update($payload);

                return $customer;
            }

            return Customer::create([
                'business_id' => $businessId,
                ...$payload,
            ]);
        }

        if ($customerCountry === 'AR' && $docType === 'Consumidor Final') {
            return Customer::query()->firstOrCreate(
                [
                    'business_id' => $businessId,
                    'doc_type' => 'Consumidor Final',
                    'doc_number' => null,
                ],
                [
                    'name' => $name ?: 'Consumidor Final',
                    'tax_condition' => $customerData['tax_condition'] ?? 'Consumidor Final',
                    'country' => 'AR',
                ],
            );
        }

        if ($customerCountry === 'GT') {
            if ($docNumber === '' && $name === '') {
                return null;
            }

            if ($docType === 'CUI') {
                if (! preg_match('/^\d{13}$/', $docNumber)) {
                    throw ValidationException::withMessages([
                        'customer.doc_number' => 'El CUI/DPI debe tener 13 digitos.',
                    ]);
                }

                if ($name === '') {
                    throw ValidationException::withMessages([
                        'customer.name' => 'El nombre del cliente es obligatorio para CUI/DPI.',
                    ]);
                }
            } elseif ($docNumber !== '') {
                $docType = 'NIT';
            }

            if ($docNumber !== '' && ! preg_match('/^[A-Za-z0-9]+$/', $docNumber)) {
                throw ValidationException::withMessages([
                    'customer.doc_number' => 'El NIT solo puede contener números y letras.',
                ]);
            }

            if ($docType === 'NIT') {
                $verifiedCustomer = Customer::query()
                    ->where('business_id', $businessId)
                    ->where('doc_type', 'NIT')
                    ->where('doc_number', $docNumber)
                    ->whereNotNull('tax_lookup_verified_at')
                    ->first();

                if ($verifiedCustomer) {
                    $name = $verifiedCustomer->name;
                }
            }
        }

        if ($customerCountry === 'AR') {
            if ($docType !== 'Consumidor Final' && $docNumber === '') {
                throw ValidationException::withMessages([
                    'customer.doc_number' => 'El número de documento es obligatorio.',
                ]);
            }

            if ($docType === 'CUIT' && ! $this->isValidCuit($docNumber)) {
                throw ValidationException::withMessages([
                    'customer.doc_number' => 'El CUIT no es válido.',
                ]);
            }
        }

        if ($name === '') {
            $name = $docNumber !== '' ? "Cliente {$docNumber}" : 'Cliente';
        }

        $query = Customer::query()->where('business_id', $businessId);

        if (! empty($customerData['id'])) {
            $customer = (clone $query)->find($customerData['id']);
        } elseif ($docNumber !== '') {
            $customer = (clone $query)->where('doc_number', $docNumber)->first();
        } else {
            $customer = (clone $query)->whereRaw('LOWER(name) = ?', [strtolower($name)])->first();
        }

        $payload = [
            'name' => $name,
            'doc_type' => $docType,
            'doc_number' => $docNumber !== '' ? $docNumber : null,
            'tax_condition' => $customerData['tax_condition'] ?? null,
            'address' => $customerData['address'] ?? null,
            'phone' => $customerData['phone'] ?? null,
            'country' => $customerCountry,
            'is_final_consumer' => $customerCountry === 'GT' ? false : ($docType === 'Consumidor Final'),
            'name_locked' => $customerCountry === 'GT' && $docType === 'NIT'
                ? (bool) (($verifiedCustomer ?? null)?->name_locked ?? ($customerData['name_locked'] ?? false))
                : false,
        ];

        if (isset($customer)) {
            $customer->update($payload);

            return $customer;
        }

        return Customer::create([
            'business_id' => $businessId,
            ...$payload,
        ]);
    }

    private function ensureSaleBelongsToCurrentBusiness(Sale $sale): void
    {
        abort_unless((int) $sale->business_id === (int) currentBusinessId(), 403);
    }

    private function validateInvoiceConfiguration(Business $business, ?array $customerData): void
    {
        if (! module_enabled('fel_gt', $business->id)) {
            throw ValidationException::withMessages([
                'document_type' => 'La facturacion electronica FEL no esta habilitada.',
            ]);
        }

        if ($business->country !== 'GT') {
            throw ValidationException::withMessages([
                'document_type' => 'La facturacion electronica FEL esta disponible solo para Guatemala.',
            ]);
        }

        $settings = TenantFelSetting::query()->where('business_id', $business->id)->first();

        if (! $settings || ! $settings->isConfigured()) {
            throw ValidationException::withMessages([
                'document_type' => $settings?->configurationErrorMessage() ?: 'FEL no configurada: falta configurar Digifact para este negocio.',
            ]);
        }

        $customerData ??= [];
        $isFinalConsumer = (bool) ($customerData['consumidor_final'] ?? false);
        $docType = $customerData['doc_type'] ?? null;
        $docNumber = $this->normalizeDocument($customerData['doc_number'] ?? null);
        $name = trim((string) ($customerData['name'] ?? ''));

        if ($isFinalConsumer || $docType === 'CF') {
            if ($docNumber !== '' && strtoupper($docNumber) !== 'CF') {
                throw ValidationException::withMessages([
                    'customer.doc_number' => 'El documento de Consumidor Final debe ser CF.',
                ]);
            }

            return;
        }

        if ($docType === 'CUI') {
            throw ValidationException::withMessages([
                'customer.doc_number' => 'CUI/DPI aún no está habilitado.',
            ]);
        }

        if ($docNumber === '' || ! preg_match('/^[A-Za-z0-9]+$/', $docNumber)) {
            throw ValidationException::withMessages([
                'customer.doc_number' => 'Consulta un NIT valido antes de facturar.',
            ]);
        }

        $verifiedCustomer = Customer::query()
            ->where('business_id', $business->id)
            ->where('doc_type', 'NIT')
            ->where('doc_number', $docNumber)
            ->whereNotNull('tax_lookup_verified_at')
            ->first();

        if (! $verifiedCustomer) {
            throw ValidationException::withMessages([
                'customer.doc_number' => 'El NIT del cliente no ha sido validado.',
            ]);
        }

        if ($name === '' || $name !== $verifiedCustomer->name || ! $verifiedCustomer->name_locked) {
            throw ValidationException::withMessages([
                'customer.name' => 'El nombre del cliente debe obtenerse automaticamente desde la consulta NIT.',
            ]);
        }
    }

    private function isFinalConsumerCustomer(?array $customerData): bool
    {
        $customerData ??= [];
        $docType = strtoupper((string) ($customerData['doc_type'] ?? ''));
        $docNumber = $this->normalizeDocument($customerData['doc_number'] ?? null);

        return (bool) ($customerData['consumidor_final'] ?? false)
            || $docType === 'CF'
            || $docNumber === 'CF';
    }

    private function isFinalConsumerModel(Customer $customer): bool
    {
        return (bool) $customer->is_final_consumer
            || strtoupper((string) $customer->doc_type) === 'CF'
            || strtoupper((string) $customer->doc_number) === 'CF'
            || (int) $customer->id === 1;
    }

    private function userCanCancelSale(Request $request, Sale $sale): bool
    {
        $user = $request->user();

        if (! $user) {
            return false;
        }

        if (! $user->is_super_admin && ! in_array($user->role, ['owner', 'admin'], true)) {
            return false;
        }

        return (int) $sale->business_id === (int) currentBusinessId();
    }

    private function userCanViewFelDocuments(Request $request): bool
    {
        return Permissions::userHas($request->user(), Permissions::FEL_DOCUMENTS_VIEW);
    }

    private function authorizeFelDocumentAccess(Request $request, bool $allowSignedUrl = false): void
    {
        if ($allowSignedUrl && $request->hasValidSignature()) {
            return;
        }

        abort_unless($this->userCanViewFelDocuments($request), 403, 'No tienes permisos para ver documentos FEL.');
    }

    private function paymentDetails(string $method, array $details): array
    {
        $allowed = match ($method) {
            'card' => ['authorization'],
            'transfer' => ['bank', 'transfer_reference'],
            'check' => ['bank', 'check_number'],
            'mercadopago' => ['mercadopago_reference'],
            default => [],
        };

        return collect($details)
            ->only($allowed)
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn ($value) => $value !== '')
            ->all();
    }

    private function paymentReference(string $method, array $details, ?string $fallback = null): ?string
    {
        $reference = match ($method) {
            'card' => $details['authorization'] ?? null,
            'transfer' => $details['transfer_reference'] ?? null,
            'check' => $details['check_number'] ?? null,
            'mercadopago' => $details['mercadopago_reference'] ?? null,
            default => $fallback,
        };

        $reference = trim((string) ($reference ?? $fallback ?? ''));

        return $reference !== '' ? $reference : null;
    }

    private function normalizeDocument(?string $value): string
    {
        return strtoupper(preg_replace('/[\s-]+/', '', trim((string) $value)));
    }

    private function isValidCuit(string $value): bool
    {
        if (! preg_match('/^\d{11}$/', $value)) {
            return false;
        }

        $multipliers = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        $sum = 0;

        foreach ($multipliers as $index => $multiplier) {
            $sum += (int) $value[$index] * $multiplier;
        }

        $check = 11 - ($sum % 11);
        $check = $check === 11 ? 0 : ($check === 10 ? 9 : $check);

        return $check === (int) $value[10];
    }

    private function safeFilename(?string $value): string
    {
        $filename = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string) $value);

        return $filename !== '' ? $filename : 'factura';
    }

    private function injectFelAutoPrintScript(string $html): string
    {
        $script = <<<'HTML'
<script>
window.addEventListener('load', function () {
  setTimeout(function () {
    window.print();
  }, 300);
});
</script>
HTML;

        if (stripos($html, '</body>') !== false) {
            return preg_replace('/<\/body>/i', $script.'</body>', $html, 1) ?? ($html.$script);
        }

        return $html.$script;
    }
}
