<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerTaxLookup;
use App\Services\Fel\FelException;
use App\Services\Fel\Providers\Digifact\DigifactClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class CustomerController extends Controller
{
    public function lookupNit(Request $request): JsonResponse
    {
        return $this->lookupGuatemalaNit($request);
    }

    public function lookupGuatemalaNit(Request $request): JsonResponse
    {
        $nit = strtoupper(preg_replace('/[\s-]+/', '', (string) $request->query('nit')));
        $business = Business::query()->findOrFail(currentBusinessId());

        if ($business->country !== 'GT') {
            return response()->json([
                'message' => 'La consulta NIT está disponible solo para Guatemala.',
            ], 403);
        }

        if ($nit === '' || ! preg_match('/^[A-Za-z0-9]+$/', $nit)) {
            return response()->json([
                'message' => 'El NIT solo puede contener números y letras.',
                'errors' => [
                    'nit' => ['El NIT solo puede contener números y letras.'],
                ],
            ], 422);
        }

        try {
            $cache = CustomerTaxLookup::query()
                ->where('business_id', $business->id)
                ->where('country', 'GT')
                ->where('doc_type', 'NIT')
                ->where('doc_number', $nit)
                ->first();

            if ($cache && $cache->last_lookup_at?->greaterThanOrEqualTo(now()->subDays(30))) {
                $customer = $this->saveVerifiedGuatemalaNitCustomer(
                    $business,
                    $cache->doc_number,
                    $cache->name,
                    $cache->raw_response,
                );

                Log::info('Guatemala NIT lookup source', [
                    'business_id' => $business->id,
                    'nit' => $nit,
                    'source' => 'cache',
                    'last_lookup_at' => $cache->last_lookup_at?->toIso8601String(),
                ]);

                return response()->json([
                    'nit' => $cache->doc_number,
                    'name' => $cache->name,
                    'raw' => $cache->raw_response,
                    'source' => 'cache',
                    'tax_lookup_verified_at' => $customer->tax_lookup_verified_at?->toIso8601String(),
                    'customer' => $this->customerPayload($customer),
                ]);
            }

            $result = DigifactClient::forBusiness($business)->lookupNit($nit);

            CustomerTaxLookup::query()->updateOrCreate(
                [
                    'business_id' => $business->id,
                    'country' => 'GT',
                    'doc_type' => 'NIT',
                    'doc_number' => $result['nit'] ?? $nit,
                ],
                [
                    'name' => $result['name'],
                    'provider' => 'digifact',
                    'raw_response' => $result['raw'] ?? null,
                    'last_lookup_at' => now(),
                ],
            );

            $customer = $this->saveVerifiedGuatemalaNitCustomer(
                $business,
                $result['nit'] ?? $nit,
                $result['name'],
                $result['raw'] ?? null,
            );

            Log::info('Guatemala NIT lookup source', [
                'business_id' => $business->id,
                'nit' => $result['nit'] ?? $nit,
                'source' => 'digifact',
            ]);

            return response()->json([
                ...$result,
                'source' => 'digifact',
                'tax_lookup_verified_at' => $customer->tax_lookup_verified_at?->toIso8601String(),
                'customer' => $this->customerPayload($customer),
            ]);
        } catch (FelException $exception) {
            Log::warning('Guatemala NIT lookup failed', [
                'business_id' => $business->id,
                'nit' => $nit,
                'error' => $exception->getMessage(),
            ]);

            $message = $exception->getMessage() ?: 'No se pudo consultar el NIT.';

            if (! str_starts_with($message, 'No se pudo consultar el NIT')
                && ! str_starts_with($message, 'No se pudo obtener token Digifact')
            ) {
                $message = "No se pudo consultar el NIT: {$message}";
            }

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

            $message = 'No se pudo consultar el NIT. Intenta nuevamente.';

            return response()->json([
                'message' => $message,
                'errors' => [
                    'nit' => [$message],
                ],
            ], 500);
        }
    }

    private function saveVerifiedGuatemalaNitCustomer(Business $business, string $nit, string $name, ?array $raw): Customer
    {
        $customer = Customer::query()
            ->where('business_id', $business->id)
            ->where('doc_number', $nit)
            ->where(function ($query) {
                $query->where('doc_type', 'NIT')->orWhereNull('doc_type');
            })
            ->first();

        if (! $customer) {
            $customer = new Customer([
                'business_id' => $business->id,
                'doc_number' => $nit,
            ]);
        }

        $customer->forceFill([
            'name' => $name,
            'doc_type' => 'NIT',
            'doc_number' => $nit,
            'country' => 'GT',
            'is_final_consumer' => false,
            'name_locked' => true,
            'tax_lookup_payload' => $raw,
            'tax_lookup_verified_at' => now(),
        ])->save();

        return $customer;
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
