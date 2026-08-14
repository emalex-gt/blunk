<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Customer;
use App\Support\GuatemalaNitCustomerResolver;
use App\Support\TextEncoding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class CustomerController extends Controller
{
    private const DUPLICATE_NIT_MESSAGE = 'Ya existe un cliente con este NIT.';
    private const INVALID_NIT_MESSAGE = 'El NIT ingresado no es válido.';
    private const LOCKED_NAME_MESSAGE = 'El nombre fiscal no puede editarse manualmente.';
    private const LOCKED_NIT_MESSAGE = 'El NIT no puede editarse manualmente.';

    public function index(Request $request): Response
    {
        $businessId = currentBusinessId();
        $search = trim((string) $request->query('search', ''));
        $fiscalStatus = trim((string) $request->query('fiscal_status', ''));
        $perPage = in_array((int) $request->query('per_page', 25), [25, 50, 100], true)
            ? (int) $request->query('per_page', 25)
            : 25;

        $customers = Customer::query()
            ->where('business_id', $businessId)
            ->when($search !== '', fn ($query) => $this->applySearch($query, $search))
            ->when($fiscalStatus !== '', fn ($query) => $this->applyFiscalStatus($query, $fiscalStatus))
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Customer $customer) => $this->customerPayload($customer));

        return Inertia::render('Customers/Index', [
            'customers' => $customers,
            'filters' => [
                'search' => $search,
                'fiscal_status' => $fiscalStatus,
                'per_page' => $perPage,
            ],
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $businessId = currentBusinessId();
        $search = trim((string) $request->query('search', ''));

        if ($search === '') {
            return response()->json(['customers' => []]);
        }

        $customers = Customer::query()
            ->where('business_id', $businessId)
            ->where(function ($query) use ($search) {
                $query->where('name', 'ilike', "%{$search}%")
                    ->orWhere('commercial_name', 'ilike', "%{$search}%")
                    ->orWhere('contact_name', 'ilike', "%{$search}%")
                    ->orWhere('doc_number', 'ilike', "%{$search}%")
                    ->orWhere('phone', 'ilike', "%{$search}%")
                    ->orWhere('address', 'ilike', "%{$search}%")
                    ->orWhere('department', 'ilike', "%{$search}%")
                    ->orWhere('municipality', 'ilike', "%{$search}%");
            })
            ->with('creditAccount:id,customer_id,credit_limit,current_balance,is_blocked')
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'name', 'commercial_name', 'contact_name', 'doc_type', 'doc_number', 'tax_condition', 'address', 'postal_code', 'department', 'municipality', 'phone', 'country', 'is_final_consumer', 'name_locked', 'tax_lookup_verified_at']);

        return response()->json([
            'customers' => $customers->map(fn (Customer $customer) => $this->customerPayload($customer))->values(),
        ]);
    }

    public function edit(Customer $customer): Response
    {
        $customer = $this->customerForCurrentBusiness($customer);

        return Inertia::render('Customers/Edit', [
            'customer' => $this->customerPayload($customer),
        ]);
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $customer = $this->customerForCurrentBusiness($customer);
        $this->rejectFiscalIdentityChanges($request, $customer);

        $data = $request->validate($this->updateRules($customer), [
            'name.required' => 'El nombre es obligatorio.',
        ]);

        $payload = $this->generalCustomerPayload($data);

        if ($this->isCfCustomer($customer)) {
            $payload['name'] = trim((string) ($data['name'] ?? $customer->name));
            $payload['doc_type'] = $customer->doc_type ?: 'CF';
            $payload['doc_number'] = $this->isCfValue($customer->doc_number) || blank($customer->doc_number) ? 'CF' : $customer->doc_number;
            $payload['is_final_consumer'] = true;
            $payload['name_locked'] = false;
        }

        $customer->fill($payload)->save();

        return back()->with('success', 'Cliente actualizado correctamente.');
    }

    public function refreshTaxData(Customer $customer): RedirectResponse
    {
        $customer = $this->customerForCurrentBusiness($customer);

        if (! $this->hasRealNit($customer)) {
            throw ValidationException::withMessages([
                'nit' => self::INVALID_NIT_MESSAGE,
            ]);
        }

        $business = Business::query()->findOrFail(currentBusinessId());

        try {
            $lookup = GuatemalaNitCustomerResolver::lookupTaxData(
                $business,
                $this->normalizedNit($customer->doc_number),
                allowCache: false,
                ignoreBadCache: true,
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'nit' => GuatemalaNitCustomerResolver::LOOKUP_ERROR_MESSAGE,
            ]);
        }

        if ($this->normalizedNitExists(currentBusinessId(), $lookup['nit'], $customer->id)) {
            throw ValidationException::withMessages([
                'nit' => self::DUPLICATE_NIT_MESSAGE,
            ]);
        }

        $addressParts = GuatemalaNitCustomerResolver::extractAddressParts(is_array($lookup['raw'] ?? null) ? $lookup['raw'] : null);

        $customer->forceFill([
            'name' => $lookup['name'],
            'doc_type' => 'NIT',
            'doc_number' => $lookup['nit'],
            'address' => $addressParts['address'] ?: $customer->address,
            'department' => $addressParts['department'] ?: $customer->department,
            'municipality' => $addressParts['municipality'] ?: $customer->municipality,
            'country' => 'GT',
            'is_final_consumer' => false,
            'name_locked' => true,
            'tax_lookup_payload' => is_array($lookup['raw'] ?? null) ? $lookup['raw'] : null,
            'tax_lookup_verified_at' => now(),
        ])->save();

        return back()->with('success', 'Datos fiscales actualizados correctamente.');
    }

    public function assignNit(Request $request, Customer $customer): RedirectResponse
    {
        $customer = $this->customerForCurrentBusiness($customer);

        if (! $this->isCfCustomer($customer)) {
            throw ValidationException::withMessages([
                'nit' => 'Este cliente ya tiene NIT asignado.',
            ]);
        }

        $data = $request->validate([
            'nit' => ['required', 'string', 'max:50'],
        ]);

        $nit = $this->normalizedNit($data['nit']);

        if ($nit === '' || $this->isCfValue($nit) || ! preg_match('/^[A-Z0-9]+$/', $nit)) {
            throw ValidationException::withMessages([
                'nit' => self::INVALID_NIT_MESSAGE,
            ]);
        }

        if ($this->normalizedNitExists(currentBusinessId(), $nit, $customer->id)) {
            throw ValidationException::withMessages([
                'nit' => self::DUPLICATE_NIT_MESSAGE,
            ]);
        }

        try {
            $lookup = GuatemalaNitCustomerResolver::lookupTaxData(
                Business::query()->findOrFail(currentBusinessId()),
                $nit,
            );
        } catch (ValidationException $exception) {
            $message = $exception->errors()['nit'][0] ?? self::INVALID_NIT_MESSAGE;

            throw ValidationException::withMessages([
                'nit' => $message === GuatemalaNitCustomerResolver::LOOKUP_ERROR_MESSAGE ? $message : self::INVALID_NIT_MESSAGE,
            ]);
        }

        if ($this->normalizedNitExists(currentBusinessId(), $lookup['nit'], $customer->id)) {
            throw ValidationException::withMessages([
                'nit' => self::DUPLICATE_NIT_MESSAGE,
            ]);
        }

        $customer->forceFill([
            'name' => $lookup['name'],
            'doc_type' => 'NIT',
            'doc_number' => $lookup['nit'],
            'country' => 'GT',
            'is_final_consumer' => false,
            'name_locked' => true,
            'tax_lookup_payload' => is_array($lookup['raw'] ?? null) ? $lookup['raw'] : null,
            'tax_lookup_verified_at' => now(),
        ])->save();

        return back()->with('success', 'NIT asignado correctamente.');
    }

    public function lookupNit(Request $request): JsonResponse
    {
        return $this->lookupGuatemalaNit($request);
    }

    public function lookupGuatemalaNit(Request $request): JsonResponse
    {
        $nit = GuatemalaNitCustomerResolver::normalize((string) $request->query('nit'));
        $business = Business::query()->findOrFail(currentBusinessId());

        if ($business->country !== 'GT') {
            return response()->json([
                'message' => 'La consulta NIT está disponible solo para Guatemala.',
            ], 403);
        }

        if ($nit === '' || $nit === 'CF' || ! preg_match('/^[A-Za-z0-9]+$/', $nit)) {
            return response()->json([
                'message' => GuatemalaNitCustomerResolver::INVALID_NIT_MESSAGE,
                'errors' => [
                    'nit' => [GuatemalaNitCustomerResolver::INVALID_NIT_MESSAGE],
                ],
            ], 422);
        }

        try {
            $result = GuatemalaNitCustomerResolver::resolve($business, $nit, requireVerifiedExisting: true);
            /** @var Customer $customer */
            $customer = $result['customer'];

            Log::info('Guatemala NIT lookup source', [
                'business_id' => $business->id,
                'nit' => $result['nit'],
                'source' => $result['source'],
            ]);

            return response()->json([
                'nit' => $result['nit'],
                'name' => $customer->name,
                'raw' => $result['raw'] ?? $customer->tax_lookup_payload,
                'source' => $result['source'],
                'tax_lookup_verified_at' => $customer->tax_lookup_verified_at?->toIso8601String(),
                'customer' => $this->customerPayload($customer),
            ]);
        } catch (ValidationException $exception) {
            Log::warning('Guatemala NIT lookup failed', [
                'business_id' => $business->id,
                'nit' => $nit,
                'error' => $exception->getMessage(),
            ]);

            $message = $exception->errors()['nit'][0] ?? GuatemalaNitCustomerResolver::LOOKUP_ERROR_MESSAGE;

            return response()->json([
                'message' => $message,
                'errors' => [
                    'nit' => [$message],
                ],
            ], 422);
        } catch (Throwable $exception) {
            Log::error('Unexpected Guatemala NIT lookup error', [
                'business_id' => $business->id,
                'nit' => $nit,
                'error' => $exception->getMessage(),
            ]);

            $message = GuatemalaNitCustomerResolver::LOOKUP_ERROR_MESSAGE;

            return response()->json([
                'message' => $message,
                'errors' => [
                    'nit' => [$message],
                ],
            ], 500);
        }
    }

    private function customerPayload(Customer $customer): array
    {
        $customer->loadMissing('creditAccount:id,customer_id,credit_limit,current_balance,is_blocked');

        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'commercial_name' => $customer->commercial_name,
            'contact_name' => $customer->contact_name,
            'doc_type' => $customer->doc_type,
            'doc_number' => $customer->doc_number,
            'tax_condition' => $customer->tax_condition,
            'address' => $customer->address,
            'postal_code' => $customer->postal_code,
            'department' => $customer->department,
            'municipality' => $customer->municipality,
            'phone' => $customer->phone,
            'country' => $customer->country,
            'is_final_consumer' => (bool) $customer->is_final_consumer,
            'name_locked' => (bool) $customer->name_locked,
            'tax_lookup_verified_at' => $customer->tax_lookup_verified_at?->toIso8601String(),
            'encoding_issue_fields' => $this->encodingIssueFields($customer),
            'has_encoding_issue' => $this->encodingIssueFields($customer) !== [],
            'fiscal_status' => $this->fiscalStatus($customer),
            'has_real_nit' => $this->hasRealNit($customer),
            'is_cf' => $this->isCfCustomer($customer),
            'credit_account' => $customer->creditAccount ? [
                'credit_limit' => $customer->creditAccount->credit_limit,
                'current_balance' => $customer->creditAccount->current_balance,
                'is_blocked' => (bool) $customer->creditAccount->is_blocked,
            ] : null,
        ];
    }

    private function applySearch($query, string $search): void
    {
        $normalizedNit = $this->normalizedNit($search);

        $query->where(function ($query) use ($search, $normalizedNit) {
            $query->where('name', 'ilike', "%{$search}%")
                ->orWhere('commercial_name', 'ilike', "%{$search}%")
                ->orWhere('contact_name', 'ilike', "%{$search}%")
                ->orWhere('doc_number', 'ilike', "%{$search}%")
                ->orWhere('phone', 'ilike', "%{$search}%")
                ->orWhere('address', 'ilike', "%{$search}%")
                ->orWhere('department', 'ilike', "%{$search}%")
                ->orWhere('municipality', 'ilike', "%{$search}%");

            if ($normalizedNit !== '') {
                $query->orWhereRaw("UPPER(REPLACE(REPLACE(COALESCE(doc_number, ''), '-', ''), ' ', '')) LIKE ?", ["%{$normalizedNit}%"]);
            }
        });
    }

    private function applyFiscalStatus($query, string $status): void
    {
        match ($status) {
            'cf' => $query->where(function ($query) {
                $query->where('is_final_consumer', true)
                    ->orWhereRaw("UPPER(REPLACE(REPLACE(REPLACE(COALESCE(doc_number, ''), '/', ''), '-', ''), ' ', '')) = 'CF'");
            }),
            'validated' => $query->whereNotNull('tax_lookup_verified_at')->where('name_locked', true),
            'pending' => $query->whereNotNull('doc_number')->whereNull('tax_lookup_verified_at')->where(function ($query) {
                $query->where('is_final_consumer', false)->orWhereNull('is_final_consumer');
            })->whereRaw("UPPER(REPLACE(REPLACE(REPLACE(COALESCE(doc_number, ''), '/', ''), '-', ''), ' ', '')) <> 'CF'"),
            'locked' => $query->where('name_locked', true),
            default => null,
        };
    }

    private function customerForCurrentBusiness(Customer $customer): Customer
    {
        abort_unless((int) $customer->business_id === (int) currentBusinessId(), 404);

        return $customer;
    }

    private function updateRules(Customer $customer): array
    {
        return [
            'name' => [$this->isCfCustomer($customer) ? 'required' : 'nullable', 'string', 'max:255'],
            'commercial_name' => ['nullable', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'department' => ['nullable', 'string', 'max:100'],
            'municipality' => ['nullable', 'string', 'max:100'],
        ];
    }

    private function generalCustomerPayload(array $data): array
    {
        return [
            'commercial_name' => $this->nullableTrim($data['commercial_name'] ?? null),
            'contact_name' => $this->nullableTrim($data['contact_name'] ?? null),
            'phone' => $this->nullableTrim($data['phone'] ?? null),
            'address' => $this->nullableTrim($data['address'] ?? null),
            'postal_code' => $this->nullableTrim($data['postal_code'] ?? null),
            'department' => $this->nullableTrim($data['department'] ?? null),
            'municipality' => $this->nullableTrim($data['municipality'] ?? null),
        ];
    }

    private function rejectFiscalIdentityChanges(Request $request, Customer $customer): void
    {
        if (! $this->hasRealNit($customer)) {
            return;
        }

        $errors = [];

        if ($request->has('doc_number') && $this->normalizedNit($request->input('doc_number')) !== $this->normalizedNit($customer->doc_number)) {
            $errors['doc_number'] = self::LOCKED_NIT_MESSAGE;
        }

        if ($request->has('name') && trim((string) $request->input('name')) !== trim((string) $customer->name)) {
            $errors['name'] = self::LOCKED_NAME_MESSAGE;
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function normalizedNitExists(int $businessId, string $nit, ?int $ignoreCustomerId = null): bool
    {
        return Customer::query()
            ->where('business_id', $businessId)
            ->when($ignoreCustomerId, fn ($query) => $query->whereKeyNot($ignoreCustomerId))
            ->whereRaw("UPPER(REPLACE(REPLACE(COALESCE(doc_number, ''), '-', ''), ' ', '')) = ?", [$nit])
            ->exists();
    }

    private function fiscalStatus(Customer $customer): string
    {
        if ($this->isCfCustomer($customer)) {
            return 'CF';
        }

        if ($this->hasRealNit($customer) && $customer->tax_lookup_verified_at) {
            return 'NIT validado';
        }

        if ($this->hasRealNit($customer)) {
            return 'NIT sin validar';
        }

        return $customer->name_locked ? 'Datos fiscales bloqueados' : 'Sin documento';
    }

    private function encodingIssueFields(Customer $customer): array
    {
        return TextEncoding::fieldsWithMojibake([
            'name' => $customer->name,
            'commercial_name' => $customer->commercial_name,
            'contact_name' => $customer->contact_name,
            'address' => $customer->address,
        ]);
    }

    private function hasRealNit(Customer $customer): bool
    {
        $nit = $this->normalizedNit($customer->doc_number);

        return $nit !== '' && ! $this->isCfValue($customer->doc_number);
    }

    private function isCfCustomer(Customer $customer): bool
    {
        return (bool) $customer->is_final_consumer
            || strtoupper((string) $customer->doc_type) === 'CF'
            || $this->isCfValue($customer->doc_number)
            || blank($customer->doc_number);
    }

    private function isCfValue(?string $value): bool
    {
        $value = strtoupper(str_replace([' ', '-', '/'], '', trim((string) $value)));

        return $value === '' || $value === 'CF';
    }

    private function normalizedNit(mixed $value): string
    {
        return GuatemalaNitCustomerResolver::normalize((string) $value);
    }

    private function nullableTrim(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
