<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Customer;
use App\Support\GuatemalaNitCustomerResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class CustomerController extends Controller
{
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
                    ->orWhere('doc_number', 'ilike', "%{$search}%")
                    ->orWhere('phone', 'ilike', "%{$search}%")
                    ->orWhere('address', 'ilike', "%{$search}%")
                    ->orWhere('department', 'ilike', "%{$search}%")
                    ->orWhere('municipality', 'ilike', "%{$search}%");
            })
            ->with('creditAccount:id,customer_id,credit_limit,current_balance,is_blocked')
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'name', 'commercial_name', 'doc_type', 'doc_number', 'tax_condition', 'address', 'department', 'municipality', 'phone', 'country', 'is_final_consumer', 'name_locked', 'tax_lookup_verified_at']);

        return response()->json([
            'customers' => $customers->map(fn (Customer $customer) => $this->customerPayload($customer))->values(),
        ]);
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
            'doc_type' => $customer->doc_type,
            'doc_number' => $customer->doc_number,
            'tax_condition' => $customer->tax_condition,
            'address' => $customer->address,
            'department' => $customer->department,
            'municipality' => $customer->municipality,
            'phone' => $customer->phone,
            'country' => $customer->country,
            'is_final_consumer' => $customer->is_final_consumer,
            'name_locked' => $customer->name_locked,
            'tax_lookup_verified_at' => $customer->tax_lookup_verified_at?->toIso8601String(),
            'credit_account' => $customer->creditAccount ? [
                'credit_limit' => $customer->creditAccount->credit_limit,
                'current_balance' => $customer->creditAccount->current_balance,
                'is_blocked' => $customer->creditAccount->is_blocked,
            ] : null,
        ];
    }
}
