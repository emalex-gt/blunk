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
            $result = GuatemalaNitCustomerResolver::resolve($business, $nit);
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
        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'doc_type' => $customer->doc_type,
            'doc_number' => $customer->doc_number,
            'tax_condition' => $customer->tax_condition,
            'address' => $customer->address,
            'phone' => $customer->phone,
            'country' => $customer->country,
            'is_final_consumer' => $customer->is_final_consumer,
            'name_locked' => $customer->name_locked,
            'tax_lookup_verified_at' => $customer->tax_lookup_verified_at?->toIso8601String(),
        ];
    }
}
