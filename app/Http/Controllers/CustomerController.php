<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerTaxLookup;
use App\Services\Fel\FelIdentityLookupException;
use App\Support\CustomerIdentity;
use App\Support\GuatemalaCustomerIdentityPolicy;
use App\Support\GuatemalaCuiCustomerResolver;
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
    private const GENERIC_FINAL_CONSUMER_MESSAGE = 'El cliente Consumidor Final ya existe y se reutiliza automáticamente.';
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
            ->whereNull('merged_into_customer_id')
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
            ->whereNull('merged_into_customer_id')
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

            if (CustomerIdentity::isGenericFinalConsumer($payload['doc_number'], $payload['name'], $payload)
                && $this->genericFinalConsumerExists(currentBusinessId(), $customer->id)) {
                throw ValidationException::withMessages([
                    'name' => self::GENERIC_FINAL_CONSUMER_MESSAGE,
                ]);
            }
        }

        $customer->fill($payload)->save();

        return back()->with('success', 'Cliente actualizado correctamente.');
    }

    public function refreshTaxData(Customer $customer): RedirectResponse
    {
        $customer = $this->customerForCurrentBusiness($customer);
        $docType = $this->taxIdentityType($customer);

        if ($docType === null) {
            throw ValidationException::withMessages([
                'nit' => self::INVALID_NIT_MESSAGE,
            ]);
        }

        $business = Business::query()->findOrFail(currentBusinessId());

        try {
            $lookup = $this->lookupTaxIdentity($business, $docType, $customer->doc_number, refresh: true);
        } catch (ValidationException $exception) {
            if ($docType === CustomerIdentity::NIT) {
                throw $exception;
            }

            throw $this->identityLookupValidationException($docType, $exception);
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                $this->identityErrorField($docType) => $this->identityLookupErrorMessage($docType),
            ]);
        }

        $docNumber = $this->lookupDocumentNumber($docType, $lookup);

        if ($this->fiscalIdentityExists(currentBusinessId(), $docType, $docNumber, $customer->id)) {
            throw ValidationException::withMessages([
                $this->identityErrorField($docType) => $this->duplicateIdentityMessage($docType),
            ]);
        }

        $payload = [
            'name' => $lookup['name'],
            'doc_type' => $docType,
            'doc_number' => $docNumber,
            'country' => 'GT',
            'is_final_consumer' => false,
            'name_locked' => true,
            'tax_lookup_payload' => is_array($lookup['raw'] ?? null) ? $lookup['raw'] : null,
            'tax_lookup_verified_at' => now(),
        ];

        if ($docType === CustomerIdentity::NIT) {
            $addressParts = GuatemalaNitCustomerResolver::extractAddressParts(is_array($lookup['raw'] ?? null) ? $lookup['raw'] : null);
            $payload['address'] = $addressParts['address'] ?: $customer->address;
            $payload['department'] = $addressParts['department'] ?: $customer->department;
            $payload['municipality'] = $addressParts['municipality'] ?: $customer->municipality;
        }

        $customer->forceFill($payload)->save();

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
            'doc_type' => ['nullable', 'string', 'max:10'],
            'doc_number' => ['nullable', 'string', 'max:50'],
            'nit' => ['nullable', 'string', 'max:50'],
        ]);

        $rawDocNumber = $data['doc_number'] ?? $data['nit'] ?? '';
        $submittedDocType = $data['doc_type'] ?? null;

        if (blank($submittedDocType) && $request->filled('nit')) {
            $submittedDocType = CustomerIdentity::NIT;
        }

        $requestedDocType = CustomerIdentity::normalizeGuatemalaDocType($submittedDocType);
        $requestedDocNumber = $requestedDocType
            ? CustomerIdentity::normalizeDocument($requestedDocType, $rawDocNumber) ?? ''
            : '';

        if (in_array($requestedDocType, [CustomerIdentity::NIT, CustomerIdentity::CUI], true)
            && $this->fiscalIdentityExists(currentBusinessId(), $requestedDocType, $requestedDocNumber, $customer->id)) {
            throw ValidationException::withMessages([
                $this->identityErrorField($requestedDocType) => $this->duplicateIdentityMessage($requestedDocType),
            ]);
        }

        try {
            $identity = app(GuatemalaCustomerIdentityPolicy::class)->resolve(
                Business::query()->findOrFail(currentBusinessId()),
                $submittedDocType,
                $rawDocNumber,
                $customer,
            );
        } catch (ValidationException $exception) {
            if ($requestedDocType === CustomerIdentity::NIT) {
                $message = $exception->errors()['doc_number'][0] ?? self::INVALID_NIT_MESSAGE;

                throw ValidationException::withMessages([
                    'nit' => $message === GuatemalaNitCustomerResolver::LOOKUP_ERROR_MESSAGE
                        ? $message
                        : self::INVALID_NIT_MESSAGE,
                ]);
            }

            throw $exception;
        }
        $docType = $identity['doc_type'];
        $docNumber = $identity['doc_number'];

        if (! in_array($docType, [CustomerIdentity::NIT, CustomerIdentity::CUI], true)) {
            throw ValidationException::withMessages([
                'doc_type' => 'El tipo de documento debe ser NIT o CUI.',
            ]);
        }

        if ($this->fiscalIdentityExists(currentBusinessId(), $docType, $docNumber, $customer->id)) {
            throw ValidationException::withMessages([
                $this->identityErrorField($docType) => $this->duplicateIdentityMessage($docType),
            ]);
        }

        $customer->forceFill([
            'name' => $identity['name'],
            'doc_type' => $docType,
            'doc_number' => $docNumber,
            'country' => 'GT',
            'is_final_consumer' => false,
            'name_locked' => true,
            'tax_lookup_payload' => $identity['tax_lookup_payload'],
            'tax_lookup_verified_at' => $identity['tax_lookup_verified_at'],
        ])->save();

        return back()->with('success', $docType.' asignado correctamente.');
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

    public function lookupGuatemalaIdentity(Request $request): JsonResponse
    {
        $docType = CustomerIdentity::normalizeGuatemalaDocType($request->query('doc_type'));

        if (! in_array($docType, [CustomerIdentity::NIT, CustomerIdentity::CUI], true)) {
            return response()->json([
                'message' => 'El tipo de documento debe ser NIT o CUI.',
                'errors' => ['doc_type' => ['El tipo de documento debe ser NIT o CUI.']],
            ], 422);
        }

        $business = Business::query()->findOrFail(currentBusinessId());

        if ($business->country !== 'GT') {
            return response()->json([
                'message' => 'La consulta de identidad fiscal está disponible solo para Guatemala.',
            ], 403);
        }

        $docNumber = CustomerIdentity::normalizeDocument($docType, $request->query('doc_number')) ?? '';

        if ($docType === CustomerIdentity::CUI && ! CustomerIdentity::isValidCui($docNumber)) {
            return $this->identityLookupValidationError(GuatemalaCuiCustomerResolver::INVALID_CUI_MESSAGE);
        }

        try {
            if ($docType === CustomerIdentity::NIT) {
                $result = GuatemalaNitCustomerResolver::resolve($business, $docNumber, requireVerifiedExisting: true);
                /** @var Customer $customer */
                $customer = $result['customer'];
                $docNumber = $result['nit'];
                $name = $customer->name;
                $verifiedAt = $customer->tax_lookup_verified_at?->toIso8601String();
            } else {
                $result = GuatemalaCuiCustomerResolver::lookupTaxData($business, $docNumber);
                $docNumber = $result['cui'];
                $name = $result['name'];
                $customer = $this->customerByFiscalIdentity($business->id, $docType, $docNumber);
                $verifiedAt = CustomerTaxLookup::query()
                    ->where('business_id', $business->id)
                    ->where('country', 'GT')
                    ->where('doc_type', CustomerIdentity::CUI)
                    ->where('doc_number', $docNumber)
                    ->first(['last_lookup_at'])
                    ?->last_lookup_at
                    ?->toIso8601String();
            }

            return response()->json([
                'doc_type' => $docType,
                'doc_number' => $docNumber,
                'name' => $name,
                'source' => $result['source'],
                'tax_lookup_verified_at' => $verifiedAt,
                'customer' => $customer
                    ? $this->customerPayload($customer)
                    : $this->resolvedIdentityCustomerPayload($docType, $docNumber, $name, $verifiedAt),
            ]);
        } catch (ValidationException $exception) {
            $message = $exception->errors()['cui'][0]
                ?? $exception->errors()['nit'][0]
                ?? $exception->errors()['customer.doc_number'][0]
                ?? 'No se pudo validar la identidad fiscal.';

            return $this->identityLookupValidationError($message);
        } catch (FelIdentityLookupException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'reason_code' => $exception->reason(),
                'errors' => ['doc_number' => [$exception->getMessage()]],
            ], 422);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo consultar la identidad fiscal. Inténtalo nuevamente.',
                'errors' => ['doc_number' => ['No se pudo consultar la identidad fiscal. Inténtalo nuevamente.']],
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
            'has_real_nit' => $this->hasNitIdentity($customer),
            'has_tax_identity' => $this->hasTaxIdentity($customer),
            'is_cf' => $this->isCfCustomer($customer),
            'credit_account' => $customer->creditAccount ? [
                'credit_limit' => $customer->creditAccount->credit_limit,
                'current_balance' => $customer->creditAccount->current_balance,
                'is_blocked' => (bool) $customer->creditAccount->is_blocked,
            ] : null,
        ];
    }

    private function identityLookupValidationError(string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'errors' => ['doc_number' => [$message]],
        ], 422);
    }

    private function customerByFiscalIdentity(int $businessId, string $docType, string $docNumber): ?Customer
    {
        return Customer::query()
            ->where('business_id', $businessId)
            ->whereNull('merged_into_customer_id')
            ->where('doc_type', $docType)
            ->where('normalized_tax_id', $docNumber)
            ->first();
    }

    private function resolvedIdentityCustomerPayload(string $docType, string $docNumber, string $name, ?string $verifiedAt): array
    {
        return [
            'id' => null,
            'name' => $name,
            'commercial_name' => null,
            'contact_name' => null,
            'doc_type' => $docType,
            'doc_number' => $docNumber,
            'tax_condition' => null,
            'address' => null,
            'postal_code' => null,
            'department' => null,
            'municipality' => null,
            'phone' => null,
            'country' => 'GT',
            'is_final_consumer' => false,
            'name_locked' => false,
            'tax_lookup_verified_at' => $verifiedAt,
            'encoding_issue_fields' => [],
            'has_encoding_issue' => false,
            'fiscal_status' => 'CUI validado',
            'has_real_nit' => false,
            'is_cf' => false,
            'credit_account' => null,
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
        $docType = $this->taxIdentityType($customer);

        if ($docType === null) {
            return;
        }

        $errors = [];

        if ($request->has('doc_type')
            && CustomerIdentity::normalizeGuatemalaDocType($request->input('doc_type')) !== $docType) {
            $errors['doc_type'] = 'El tipo de documento fiscal no puede editarse manualmente.';
        }

        if ($request->has('doc_number')
            && CustomerIdentity::normalizeDocument($docType, $request->input('doc_number')) !== CustomerIdentity::normalizeDocument($docType, $customer->doc_number)) {
            $errors['doc_number'] = $docType === CustomerIdentity::NIT
                ? self::LOCKED_NIT_MESSAGE
                : 'El CUI no puede editarse manualmente.';
        }

        if ($request->has('name') && trim((string) $request->input('name')) !== trim((string) $customer->name)) {
            $errors['name'] = self::LOCKED_NAME_MESSAGE;
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function fiscalIdentityExists(int $businessId, string $docType, string $docNumber, ?int $ignoreCustomerId = null): bool
    {
        $query = Customer::query()
            ->where('business_id', $businessId)
            ->when($ignoreCustomerId, fn ($query) => $query->whereKeyNot($ignoreCustomerId))
            ->where('normalized_tax_id', $docNumber);

        if ($docType === CustomerIdentity::NIT) {
            $query->where(function ($query): void {
                $query->where('doc_type', CustomerIdentity::NIT)->orWhereNull('doc_type');
            });
        } else {
            $query->where('doc_type', $docType);
        }

        return $query->exists();
    }

    private function lookupTaxIdentity(Business $business, string $docType, string $docNumber, bool $refresh = false): array
    {
        return match ($docType) {
            CustomerIdentity::NIT => GuatemalaNitCustomerResolver::lookupTaxData(
                $business,
                $this->normalizedNit($docNumber),
                allowCache: ! $refresh,
                ignoreBadCache: $refresh,
            ),
            CustomerIdentity::CUI => GuatemalaCuiCustomerResolver::lookupTaxData(
                $business,
                $docNumber,
                allowCache: ! $refresh,
            ),
        };
    }

    private function lookupDocumentNumber(string $docType, array $lookup): string
    {
        return $docType === CustomerIdentity::CUI
            ? CustomerIdentity::normalizeCui($lookup['cui'] ?? null)
            : $this->normalizedNit($lookup['nit'] ?? null);
    }

    private function identityErrorField(string $docType): string
    {
        return $docType === CustomerIdentity::NIT ? 'nit' : 'doc_number';
    }

    private function duplicateIdentityMessage(string $docType): string
    {
        return $docType === CustomerIdentity::NIT
            ? self::DUPLICATE_NIT_MESSAGE
            : 'Ya existe un cliente con este CUI.';
    }

    private function identityLookupErrorMessage(string $docType): string
    {
        return $docType === CustomerIdentity::NIT
            ? GuatemalaNitCustomerResolver::LOOKUP_ERROR_MESSAGE
            : 'No se pudo consultar el CUI. Inténtalo nuevamente.';
    }

    private function identityLookupValidationException(string $docType, ValidationException $exception): ValidationException
    {
        if ($docType === CustomerIdentity::NIT) {
            $message = $exception->errors()['nit'][0] ?? self::INVALID_NIT_MESSAGE;

            return ValidationException::withMessages([
                'nit' => $message === GuatemalaNitCustomerResolver::LOOKUP_ERROR_MESSAGE ? $message : self::INVALID_NIT_MESSAGE,
            ]);
        }

        $message = $exception->errors()['cui'][0]
            ?? $exception->errors()['customer.doc_number'][0]
            ?? GuatemalaCuiCustomerResolver::INVALID_CUI_MESSAGE;

        return ValidationException::withMessages(['doc_number' => $message]);
    }

    private function genericFinalConsumerExists(int $businessId, ?int $ignoreCustomerId = null): bool
    {
        return Customer::query()
            ->where('business_id', $businessId)
            ->where('normalized_tax_id', 'CF')
            ->when($ignoreCustomerId, fn ($query) => $query->whereKeyNot($ignoreCustomerId))
            ->get()
            ->contains(fn (Customer $candidate) => CustomerIdentity::isGenericFinalConsumer(
                $candidate->normalized_tax_id,
                $candidate->name,
                $candidate->only(['commercial_name', 'contact_name', 'address', 'phone', 'postal_code', 'department', 'municipality']),
            ));
    }

    private function fiscalStatus(Customer $customer): string
    {
        if ($this->isCfCustomer($customer)) {
            return 'CF';
        }

        if (CustomerIdentity::normalizeGuatemalaDocType($customer->doc_type) === CustomerIdentity::CUI) {
            return $customer->tax_lookup_verified_at ? 'CUI validado' : 'CUI sin validar';
        }

        if ($this->hasNitIdentity($customer) && $customer->tax_lookup_verified_at) {
            return 'NIT validado';
        }

        if ($this->hasNitIdentity($customer)) {
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

    private function hasTaxIdentity(Customer $customer): bool
    {
        return $this->taxIdentityType($customer) !== null;
    }

    private function hasNitIdentity(Customer $customer): bool
    {
        $docType = CustomerIdentity::normalizeGuatemalaDocType($customer->doc_type);

        return ($docType === CustomerIdentity::NIT || ($docType === null && blank($customer->doc_type)))
            && CustomerIdentity::isRealTaxId($customer->doc_number);
    }

    private function taxIdentityType(Customer $customer): ?string
    {
        $docType = CustomerIdentity::normalizeGuatemalaDocType($customer->doc_type);

        if ($docType === CustomerIdentity::CUI && CustomerIdentity::isValidCui($customer->doc_number)) {
            return CustomerIdentity::CUI;
        }

        return $this->hasNitIdentity($customer) ? CustomerIdentity::NIT : null;
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
        return CustomerIdentity::normalizeTaxId($value) === 'CF' || blank($value);
    }

    private function normalizedNit(mixed $value): string
    {
        return CustomerIdentity::normalizeTaxId($value) ?? '';
    }

    private function nullableTrim(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
