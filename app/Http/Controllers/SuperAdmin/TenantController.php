<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\TenantModule;
use App\Models\TenantFelSetting;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Fel\Providers\Digifact\DigifactClient;
use App\Support\CloudinaryUploader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TenantController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->string('search')->toString();

        return Inertia::render('SuperAdmin/Tenants/Index', [
            'tenants' => Business::query()
                ->with(['tenantSetting:id,business_id,use_product_images,max_users,receipt_format', 'latestSubscription'])
                ->withCount([
                    'users as active_users_count' => fn ($query) => $query->where('is_active', true),
                ])
                ->when($search, function ($query) use ($search) {
                    $query->where(function ($query) use ($search) {
                        $query->where('name', 'ilike', "%{$search}%")
                            ->orWhere('email', 'ilike', "%{$search}%");
                    });
                })
                ->orderBy('name')
                ->paginate(25)
                ->withQueryString(),
            'filters' => ['search' => $search],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('SuperAdmin/Tenants/Form', [
            'tenant' => null,
            'settings' => [
                'use_product_images' => true,
                'max_users' => 1,
                'receipt_format' => 'ticket',
                'use_branches' => false,
                'products_shared_across_branches' => true,
                'pricing_scope' => 'global',
                'allow_manual_price' => false,
                'manual_price_min_margin_percent' => 0,
                'remember_last_customer_product_price' => false,
                'enable_credit_sales' => false,
                'allow_negative_stock' => false,
                'allow_duplicate_product_codes' => false,
                'allow_duplicate_product_barcodes' => false,
                'allow_receipts' => true,
                'allow_invoices' => false,
            ],
            'felSettings' => $this->defaultFelSettings(),
            'availableModules' => $this->availableModulesPayload(),
            'enabledModules' => $this->defaultEnabledModules(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($request, $data) {
            $business = Business::create($data['tenant']);

            if ($request->hasFile('logo')) {
                $logo = app(CloudinaryUploader::class)->uploadImage(
                    $request->file('logo'),
                    "businesses/{$business->id}/tenant",
                    'tenant_logo',
                );

                $business->update([
                    'logo_url' => $logo['secure_url'],
                    'logo_public_id' => $logo['public_id'],
                ]);
            }

            $business->tenantSetting()->create($data['settings']);
            $this->syncModules($business, $data['modules']);
            $this->syncFelSettings($business, $data['fel'], $data['fel_phrases']);

            if (filled($data['owner']['name']) && filled($data['owner']['email']) && filled($data['owner']['password'])) {
                User::create([
                    'business_id' => $business->id,
                    'name' => $data['owner']['name'],
                    'email' => $data['owner']['email'],
                    'password' => $data['owner']['password'],
                    'role' => 'owner',
                    'is_super_admin' => false,
                ]);
            }
        });

        return redirect()->route('super-admin.tenants.index');
    }

    public function edit(Business $business): Response
    {
        $business->load(['tenantSetting', 'tenantFelSetting.phrases', 'tenantModules']);
        $felSettings = $business->country === 'GT'
            ? $this->felSettingsPayload($this->settings($business))
            : $this->defaultFelSettings();

        return Inertia::render('SuperAdmin/Tenants/Form', [
            'tenant' => $business,
            'settings' => [
                'use_product_images' => $business->tenantSetting?->use_product_images ?? true,
                'max_users' => $business->tenantSetting?->max_users ?? 1,
                'receipt_format' => $business->tenantSetting?->receipt_format ?? 'ticket',
                'use_branches' => $business->tenantSetting?->use_branches ?? false,
                'products_shared_across_branches' => $business->tenantSetting?->products_shared_across_branches ?? true,
                'pricing_scope' => $business->tenantSetting?->pricing_scope ?? 'global',
                'allow_manual_price' => $business->tenantSetting?->allow_manual_price ?? false,
                'manual_price_min_margin_percent' => $business->tenantSetting?->manual_price_min_margin_percent ?? 0,
                'remember_last_customer_product_price' => $business->tenantSetting?->remember_last_customer_product_price ?? false,
                'enable_credit_sales' => $business->tenantSetting?->enable_credit_sales ?? false,
                'allow_negative_stock' => $business->tenantSetting?->allow_negative_stock ?? false,
                'allow_duplicate_product_codes' => $business->tenantSetting?->allow_duplicate_product_codes ?? false,
                'allow_duplicate_product_barcodes' => $business->tenantSetting?->allow_duplicate_product_barcodes ?? false,
                'allow_receipts' => $business->tenantSetting?->allow_receipts ?? true,
                'allow_invoices' => $business->tenantSetting?->allow_invoices ?? false,
            ],
            'felSettings' => $felSettings,
            'availableModules' => $this->availableModulesPayload(),
            'enabledModules' => $business->tenantModules
                ->where('is_enabled', true)
                ->pluck('module')
                ->values()
                ->all(),
        ]);
    }

    public function update(Request $request, Business $business): RedirectResponse
    {
        $data = $this->validated($request, $business);

        DB::transaction(function () use ($request, $business, $data) {
            $business->update($data['tenant']);

            $cloudinary = app(CloudinaryUploader::class);

            if ($request->boolean('remove_logo')) {
                $cloudinary->destroy($business->logo_public_id);
                $business->update([
                    'logo_url' => null,
                    'logo_public_id' => null,
                ]);
            }

            if ($request->hasFile('logo')) {
                $oldPublicId = $business->logo_public_id;
                $logo = $cloudinary->uploadImage(
                    $request->file('logo'),
                    "businesses/{$business->id}/tenant",
                    'tenant_logo',
                );

                $cloudinary->destroy($oldPublicId);
                $business->update([
                    'logo_url' => $logo['secure_url'],
                    'logo_public_id' => $logo['public_id'],
                ]);
            }

            TenantSetting::updateOrCreate(
                ['business_id' => $business->id],
                $data['settings'],
            );

            $this->syncModules($business, $data['modules']);
            $this->syncFelSettings($business, $data['fel'], $data['fel_phrases']);
        });

        return redirect()->route('super-admin.tenants.index');
    }

    public function testFelConnection(Business $business): RedirectResponse
    {
        abort_unless($business->country === 'GT', 404);
        $settings = $this->settings($business);

        if (! $settings->isConfigured()) {
            $message = $settings->configurationErrorMessage();
            $settings->update(['last_error' => $message]);

            return back()->withErrors(['fel_connection' => $message]);
        }

        try {
            DigifactClient::forBusiness($business)->testConnection();
            $settings->update([
                'last_successful_connection_at' => now(),
                'last_error' => null,
            ]);

            return back()->with('success', 'Conexion exitosa con Digifact.');
        } catch (\Throwable $exception) {
            $settings->update(['last_error' => $exception->getMessage()]);

            return back()->withErrors([
                'fel_connection' => 'No se pudo conectar con Digifact: '.$exception->getMessage(),
            ]);
        }
    }

    private function validated(Request $request, ?Business $business = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('businesses', 'slug')->ignore($business)],
            'country' => ['nullable', Rule::in(['AR', 'GT'])],
            'phone' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'logo' => ['nullable', 'image', 'max:5120'],
            'remove_logo' => ['nullable', 'boolean'],
            'is_active' => ['boolean'],
            'use_product_images' => ['boolean'],
            'max_users' => ['required', 'integer', 'min:1'],
            'receipt_format' => ['required', Rule::in(['ticket', 'document'])],
            'use_branches' => ['nullable', 'boolean'],
            'products_shared_across_branches' => ['nullable', 'boolean'],
            'pricing_scope' => ['nullable', Rule::in(['global', 'branch'])],
            'allow_manual_price' => ['nullable', 'boolean'],
            'manual_price_min_margin_percent' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
            'remember_last_customer_product_price' => ['nullable', 'boolean'],
            'enable_credit_sales' => ['nullable', 'boolean'],
            'allow_negative_stock' => ['nullable', 'boolean'],
            'allow_duplicate_product_codes' => ['nullable', 'boolean'],
            'allow_duplicate_product_barcodes' => ['nullable', 'boolean'],
            'allow_receipts' => ['nullable', 'boolean'],
            'allow_invoices' => ['nullable', 'boolean'],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'owner_email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'owner_password' => ['nullable', 'string', 'min:8'],
            'fel_enabled' => ['nullable', 'boolean'],
            'fel_environment' => ['nullable', 'in:test,production'],
            'fel_issuer_tax_id' => ['nullable', 'string', 'max:50'],
            'fel_username' => ['nullable', 'string', 'max:255'],
            'fel_password' => ['nullable', 'string', 'max:1000'],
            'fel_test_base_url' => ['nullable', 'url', 'max:255'],
            'fel_production_base_url' => ['nullable', 'url', 'max:255'],
            'fel_affiliate_type' => ['nullable', 'string', 'max:255'],
            'fel_certifier_tax_id' => ['nullable', 'string', 'max:50'],
            'fel_phrases' => ['nullable', 'array'],
            'fel_phrases.*.data_identifier' => ['nullable', 'string', 'max:50'],
            'fel_phrases.*.phrase_type' => ['nullable', 'string', 'max:50'],
            'fel_phrases.*.scenario_code' => ['nullable', 'string', 'max:50'],
            'fel_phrases.*.resolution_number' => ['nullable', 'string', 'max:255'],
            'fel_phrases.*.resolution_date' => ['nullable', 'date'],
            'modules' => ['nullable', 'array'],
            'modules.*' => ['string', Rule::in(array_keys(config('blunk_modules')))],
        ], [
            'logo.max' => 'El logo no debe superar los 5MB.',
        ]);

        $modules = $validated['modules'] ?? $this->defaultEnabledModules();
        $branchesModuleEnabled = in_array('branches', $modules, true);

        $payload = [
            'tenant' => [
                'name' => $validated['name'],
                'slug' => $validated['slug'] ?? null,
                'country' => $validated['country'] ?? 'GT',
                'currency' => config('currency.'.($validated['country'] ?? 'GT').'.code', 'GTQ'),
                'phone' => $validated['phone'] ?? null,
                'email' => $validated['email'] ?? null,
                'is_active' => (bool) ($validated['is_active'] ?? false),
            ],
            'settings' => [
                'use_product_images' => (bool) ($validated['use_product_images'] ?? false),
                'max_users' => (int) $validated['max_users'],
                'receipt_format' => $validated['receipt_format'],
                'use_branches' => $branchesModuleEnabled && (bool) ($validated['use_branches'] ?? false),
                'products_shared_across_branches' => (bool) ($validated['products_shared_across_branches'] ?? true),
                'pricing_scope' => $branchesModuleEnabled && ($validated['pricing_scope'] ?? 'global') === 'branch' ? 'branch' : 'global',
                'allow_manual_price' => (bool) ($validated['allow_manual_price'] ?? false),
                'manual_price_min_margin_percent' => round((float) ($validated['manual_price_min_margin_percent'] ?? 0), 2),
                'remember_last_customer_product_price' => (bool) ($validated['remember_last_customer_product_price'] ?? false),
                'enable_credit_sales' => in_array('credits', $modules, true) && (bool) ($validated['enable_credit_sales'] ?? false),
                'allow_negative_stock' => (bool) ($validated['allow_negative_stock'] ?? false),
                'allow_duplicate_product_codes' => (bool) ($validated['allow_duplicate_product_codes'] ?? false),
                'allow_duplicate_product_barcodes' => (bool) ($validated['allow_duplicate_product_barcodes'] ?? false),
                'allow_receipts' => (bool) ($validated['allow_receipts'] ?? true),
                'allow_invoices' => (bool) ($validated['allow_invoices'] ?? false),
            ],
            'owner' => [
                'name' => $validated['owner_name'] ?? null,
                'email' => $validated['owner_email'] ?? null,
                'password' => $validated['owner_password'] ?? null,
            ],
            'fel' => [
                'enabled' => (bool) ($validated['fel_enabled'] ?? false),
                'provider' => 'digifact',
                'environment' => $validated['fel_environment'] ?? 'test',
                'issuer_tax_id' => $validated['fel_issuer_tax_id'] ?? null,
                'username' => $validated['fel_username'] ?? null,
                'password' => $validated['fel_password'] ?? null,
                'test_base_url' => filled($validated['fel_test_base_url'] ?? null)
                    ? $validated['fel_test_base_url']
                    : config('digifact.test_base_url'),
                'production_base_url' => filled($validated['fel_production_base_url'] ?? null)
                    ? $validated['fel_production_base_url']
                    : config('digifact.production_base_url'),
                'affiliate_type' => $validated['fel_affiliate_type'] ?? null,
                'certifier_tax_id' => $validated['fel_certifier_tax_id'] ?? null,
            ],
            'fel_phrases' => $validated['fel_phrases'] ?? [],
            'modules' => $modules,
        ];

        $this->validateDocumentAvailability($payload, $business);

        return $payload;
    }

    private function validateDocumentAvailability(array $data, ?Business $business): void
    {
        $allowReceipts = (bool) $data['settings']['allow_receipts'];
        $allowInvoices = (bool) $data['settings']['allow_invoices'];

        if (! $allowReceipts && ! $allowInvoices) {
            throw ValidationException::withMessages([
                'allow_receipts' => 'Debe habilitar al menos un tipo de documento de venta.',
            ]);
        }

        if (! $allowInvoices) {
            return;
        }

        $fel = $data['fel'];
        $existingFel = $business?->tenantFelSetting()->first();
        $activeBaseUrl = ($fel['environment'] ?? 'test') === 'production'
            ? ($fel['production_base_url'] ?? null)
            : ($fel['test_base_url'] ?? null);
        $hasPassword = filled($fel['password'] ?? null) || filled($existingFel?->password);
        $ready = $data['tenant']['country'] === 'GT'
            && in_array('fel_gt', $data['modules'], true)
            && (bool) ($fel['enabled'] ?? false)
            && filled($fel['issuer_tax_id'] ?? null)
            && filled($fel['username'] ?? null)
            && $hasPassword
            && filled($activeBaseUrl)
            && filled($fel['affiliate_type'] ?? null);

        if (! $ready) {
            throw ValidationException::withMessages([
                'allow_invoices' => 'Para habilitar facturas FEL primero completa la configuración FEL.',
            ]);
        }
    }

    private function syncModules(Business $business, array $enabledModules): void
    {
        $available = array_keys(config('blunk_modules'));
        $enabledModules = array_values(array_intersect($available, $enabledModules));
        $now = now();

        foreach ($available as $module) {
            $enabled = in_array($module, $enabledModules, true);

            TenantModule::query()->updateOrCreate(
                ['business_id' => $business->id, 'module' => $module],
                [
                    'is_enabled' => $enabled,
                    'enabled_at' => $enabled ? $now : null,
                    'disabled_at' => $enabled ? null : $now,
                    'created_by' => auth()->id(),
                ],
            );
        }
    }

    private function defaultEnabledModules(): array
    {
        return ['pos', 'inventory', 'purchases', 'cash_register', 'customers', 'reports'];
    }

    private function availableModulesPayload(): array
    {
        return collect(config('blunk_modules'))
            ->map(fn (array $module, string $key) => [
                'key' => $key,
                'name' => $module['name'],
                'description' => $module['description'] ?? '',
                'group' => $module['group'] ?? '',
                'plan_hint' => $module['plan_hint'] ?? '',
            ])
            ->values()
            ->all();
    }

    private function settings(Business $business): TenantFelSetting
    {
        return TenantFelSetting::query()->firstOrCreate(
            ['business_id' => $business->id],
            [
                'provider' => 'digifact',
                'environment' => 'test',
                'enabled' => false,
                'test_base_url' => config('digifact.test_base_url'),
                'production_base_url' => config('digifact.production_base_url'),
                'establishment_country' => 'GT',
            ],
        );
    }

    private function syncFelSettings(Business $business, array $data, array $phrases): void
    {
        if ($business->country !== 'GT') {
            return;
        }

        $settings = $this->settings($business);
        $payload = $data;
        unset($payload['password']);

        if (filled($data['password'] ?? null)) {
            $payload['password'] = $data['password'];
            $payload['token'] = null;
            $payload['token_expires_at'] = null;
        }

        $settings->update($payload);
        $settings->phrases()->delete();

        $rows = collect($phrases)
            ->filter(fn (array $phrase) => filled($phrase['phrase_type'] ?? null) || filled($phrase['scenario_code'] ?? null))
            ->values();

        if ($rows->isEmpty()) {
            $rows = collect([[
                'data_identifier' => '1',
                'phrase_type' => '1',
                'scenario_code' => '2',
                'resolution_number' => null,
                'resolution_date' => null,
            ]]);
        }

        $settings->phrases()->createMany($rows->map(fn (array $phrase) => [
            'business_id' => $business->id,
            'data_identifier' => $phrase['data_identifier'] ?? '1',
            'phrase_type' => $phrase['phrase_type'] ?? '1',
            'scenario_code' => $phrase['scenario_code'] ?? '2',
            'resolution_number' => $phrase['resolution_number'] ?? null,
            'resolution_date' => $phrase['resolution_date'] ?? null,
            'type_data' => $phrase['data_identifier'] ?? '1',
            'type_value' => $phrase['phrase_type'] ?? '1',
            'scenario_data' => $phrase['data_identifier'] ?? '1',
            'scenario_value' => $phrase['scenario_code'] ?? '2',
        ])->all());
    }

    private function defaultFelSettings(): array
    {
        return [
            'enabled' => false,
            'provider' => 'digifact',
            'environment' => 'test',
            'issuer_tax_id' => null,
            'username' => null,
            'test_base_url' => config('digifact.test_base_url'),
            'production_base_url' => config('digifact.production_base_url'),
            'affiliate_type' => null,
            'certifier_tax_id' => null,
            'last_successful_connection_at' => null,
            'last_error' => null,
            'phrases' => [[
                'data_identifier' => '1',
                'phrase_type' => '1',
                'scenario_code' => '2',
                'resolution_number' => null,
                'resolution_date' => null,
            ]],
        ];
    }

    private function felSettingsPayload(TenantFelSetting $settings): array
    {
        $settings->loadMissing('phrases');

        return [
            'enabled' => $settings->enabled,
            'provider' => $settings->provider,
            'environment' => $settings->environment,
            'issuer_tax_id' => $settings->issuer_tax_id,
            'username' => $settings->username,
            'test_base_url' => $settings->test_base_url ?: config('digifact.test_base_url'),
            'production_base_url' => $settings->production_base_url ?: config('digifact.production_base_url'),
            'affiliate_type' => $settings->affiliate_type,
            'certifier_tax_id' => $settings->certifier_tax_id,
            'last_successful_connection_at' => $settings->last_successful_connection_at?->format('Y-m-d H:i'),
            'last_error' => $settings->last_error,
            'phrases' => $settings->phrases->map(fn ($phrase) => [
                'data_identifier' => $phrase->data_identifier ?: $phrase->type_data ?: $phrase->scenario_data ?: '1',
                'phrase_type' => $phrase->phrase_type ?: $phrase->type_value ?: '1',
                'scenario_code' => $phrase->scenario_code ?: $phrase->scenario_value ?: '2',
                'resolution_number' => $phrase->resolution_number,
                'resolution_date' => $phrase->resolution_date?->format('Y-m-d'),
            ])->values()->all() ?: $this->defaultFelSettings()['phrases'],
        ];
    }
}
