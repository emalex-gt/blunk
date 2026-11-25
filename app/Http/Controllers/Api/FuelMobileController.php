<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\FuelAccess;
use App\Support\FuelCatalog;
use App\Support\CheckoutPaymentMethods;
use App\Support\Permissions;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Blunk Fuel mobile API v1, operational capture ONLY.
 * Does not create sales, sale_payments, cash movements, stock movements or FEL.
 */
final class FuelMobileController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        // Android emulator may use http://10.0.2.2:8000 ONLY in APP_ENV=local.
        abort_unless($request->isSecure() || app()->environment('local', 'testing'), 403, 'HTTPS obligatorio.');

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'min:3', 'max:80'],
        ]);

        $user = User::query()->where('email', Str::lower($data['email']))->first();
        if (! $user || ! Hash::check($data['password'], (string) $user->password)) {
            throw ValidationException::withMessages(['email' => 'Credenciales incorrectas.']);
        }

        $business = FuelAccess::businessFor($user);
        abort_unless(Permissions::userHas($user, Permissions::FUEL_VIEW), 403);

        $branches = FuelAccess::authorizedBranches($user, $business);
        abort_if($branches->isEmpty(), 403, 'El usuario no tiene sucursales autorizadas.');

        $abilities = ['fuel:read', 'fuel:tenant:'.$business->id];
        if (Permissions::userHas($user, Permissions::FUEL_RECORD_MANUAL)) {
            $abilities[] = 'fuel:manual';
        }
        // A password login cannot mint a trusted dispenser ingestion token.
        // 'fuel:automatic' must be provisioned separately to a trusted device.
        foreach ($branches as $branch) {
            $abilities[] = 'fuel:branch:'.$branch->id;
        }

        $expiresAt = now()->addHours(12);
        $token = $user->createToken('blunk-fuel:'.Str::substr($data['device_name'], 0, 64), $abilities, $expiresAt);
        $default = $branches->firstWhere('id', (int) $user->current_branch_id) ?? $branches->first();

        return response()->json([
            'token_type' => 'Bearer',
            'access_token' => $token->plainTextToken,
            'expires_at' => $expiresAt->toIso8601String(),
            'default_branch_id' => (int) $default->id,
            'abilities' => array_values(array_filter($abilities, fn (string $ability) => ! str_starts_with($ability, 'fuel:tenant:') && ! str_starts_with($ability, 'fuel:branch:'))),
        ], 200, ['Cache-Control' => 'no-store']);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken && str_starts_with($token->name, 'blunk-fuel:'), 403);
        $token->delete();
        return response()->json(['revoked' => true], 200, ['Cache-Control' => 'no-store']);
    }

    public function bootstrap(Request $request): JsonResponse
    {
        [$user, $business, $branch, $token] = FuelAccess::requireContext($request, Permissions::FUEL_VIEW, 'fuel:read');
        $scopedBranches = FuelAccess::authorizedBranches($user, $business)
            ->filter(fn ($candidate) => in_array('fuel:branch:'.$candidate->id, (array) $token->abilities, true))
            ->map(fn ($candidate) => [
                'id' => (int) $candidate->id,
                'name' => $candidate->name,
                'code' => $candidate->code,
            ])->values()->all();

        return response()->json([
            'api_version' => 'fuel/v1',
            'business' => ['id' => (int) $business->id, 'name' => $business->name],
            'branch' => ['id' => (int) $branch->id, 'name' => $branch->name, 'code' => $branch->code],
            'branches' => $scopedBranches,
            'operator' => ['id' => (int) $user->id, 'name' => $user->name],
            'currency' => 'GTQ',
            'fuel_products' => FuelCatalog::available((int) $business->id, (int) $branch->id),
            'payment_methods' => CheckoutPaymentMethods::forCountry('GT'),
            'capabilities' => [
                'manual_consumption' => Permissions::userHas($user, Permissions::FUEL_RECORD_MANUAL)
                    && in_array('fuel:manual', (array) $token->abilities, true),
                'automatic_consumption' => Permissions::userHas($user, Permissions::FUEL_RECORD_AUTO)
                    && in_array('fuel:automatic', (array) $token->abilities, true),
                'payments' => false,
                'invoicing' => false,
                'inventory_deduction' => false,
                'neonet' => false,
            ],
        ], 200, ['Cache-Control' => 'no-store']);
    }

    public function index(Request $request): JsonResponse
    {
        [, $business, $branch] = FuelAccess::requireContext($request, Permissions::FUEL_VIEW, 'fuel:read');
        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'before_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $limit = (int) ($data['limit'] ?? 30);
        $query = DB::table('fuel_dispenses as fd')
            ->join('fuel_product_configs as fp', 'fp.id', '=', 'fd.fuel_product_id')
            ->join('products as p', 'p.id', '=', 'fp.product_id')
            ->where('fd.business_id', $business->id)->where('fd.branch_id', $branch->id)
            ->where('fp.business_id', $business->id)->where('fp.branch_id', $branch->id)
            ->where('p.business_id', $business->id);
        if (isset($data['before_id'])) $query->where('fd.id', '<', $data['before_id']);
        $rows = $query->orderByDesc('fd.id')->limit($limit + 1)
            ->get(['fd.id', 'fd.fuel_product_id', 'fp.product_id', 'fp.label as configured_label', 'p.name as product_name',
                'fd.pump_number', 'fd.liters', 'fd.unit_price', 'fd.total', 'fd.entry_mode',
                'fd.source', 'fd.source_key', 'fd.status', 'fd.payment_status', 'fd.invoice_status',
                'fd.created_at', 'fd.occurred_at']);
        $hasMore = $rows->count() > $limit;
        $items = $rows->take($limit)->map(fn ($row) => $this->serializeDispense($row))->values()->all();

        return response()->json([
            'data' => $items,
            'pagination' => ['has_more' => $hasMore, 'next_before_id' => $hasMore ? end($items)['id'] : null],
        ], 200, ['Cache-Control' => 'no-store']);
    }

    public function storeManual(Request $request): JsonResponse
    {
        return $this->storeDispense($request, 'manual', Permissions::FUEL_RECORD_MANUAL, 'fuel:manual');
    }

    public function storeAutomatic(Request $request): JsonResponse
    {
        // Manual user tokens do not contain 'fuel:automatic'.
        return $this->storeDispense($request, 'automatic', Permissions::FUEL_RECORD_AUTO, 'fuel:automatic');
    }

    private function storeDispense(Request $request, string $source, string $permission, string $ability): JsonResponse
    {
        [$user, $business, $branch] = FuelAccess::requireContext($request, $permission, $ability);
        $rules = [
            'fuel_product_id' => ['required', 'integer', 'min:1'],
            'pump_number' => ['required', 'integer', 'min:1', 'max:999'],
            'entry_mode' => ['required', 'in:liters,amount'],
            'value' => ['required', 'string', 'regex:/^\d{1,6}(?:\.\d{1,3})?$/D'],
            'expected_price_per_liter' => ['required', 'string', 'regex:/^\d{1,6}(?:\.\d{1,2})?$/D'],
        ];
        if ($source === 'manual') {
            $rules['request_key'] = ['required', 'uuid'];
        } else {
            $rules['device_id'] = ['required', 'string', 'min:3', 'max:80', 'regex:/^[a-zA-Z0-9._:-]+$/D'];
            $rules['external_event_id'] = ['required', 'string', 'min:1', 'max:120'];
            $rules['occurred_at'] = ['nullable', 'date'];
        }
        $data = $request->validate($rules);
        if ($source === 'automatic') {
            $deviceScope = 'fuel:device:'.$data['device_id'];
            $token = $user->currentAccessToken();
            abort_unless($token instanceof PersonalAccessToken
                && in_array($deviceScope, (array) $token->abilities, true), 403,
                'El token no está autorizado para este dispositivo.');
        }
        $mode = $data['entry_mode'];
        $scale = $mode === 'liters' ? 3 : 2;
        $inputUnits = $this->parseUnits($data['value'], $scale);
        $expectedCents = $this->parseUnits($data['expected_price_per_liter'], 2);
        if ($inputUnits <= 0 || $expectedCents <= 0) {
            throw ValidationException::withMessages(['value' => 'Valor o precio no válido.']);
        }

        $sourceKey = $source === 'manual'
            ? Str::lower($data['request_key'])
            : hash('sha256', $data['device_id']."\0".$data['external_event_id']);
        $canonicalValue = $this->decimal($inputUnits, $scale);
        $fingerprint = hash('sha256', json_encode([
            $source, (int) $data['fuel_product_id'], (int) $data['pump_number'],
            $mode, $canonicalValue, $this->decimal($expectedCents, 2),
            $source === 'automatic' ? $data['device_id'] : null,
            $source === 'automatic' ? $data['external_event_id'] : null,
            $source === 'automatic' ? ($data['occurred_at'] ?? null) : null,
        ], JSON_THROW_ON_ERROR));

        $businessId = (int) $business->id;
        $branchId = (int) $branch->id;
        try {
            $result = DB::transaction(function () use ($businessId, $branchId, $user, $source, $sourceKey, $fingerprint, $data, $expectedCents, $inputUnits, $mode) {
                // Replay before fetching current product: configuration/price may have changed.
                $existing = $this->findBySource($businessId, $branchId, $source, $sourceKey);
                if ($existing) return $this->replay($existing, $fingerprint);

                [, , $fuel] = FuelCatalog::requireConfigured($businessId, $branchId, (int) $data['fuel_product_id'], true);
                $rateCents = $this->parseUnits($fuel['price_per_liter'], 2);
                if ($rateCents !== $expectedCents) {
                    throw ValidationException::withMessages([
                        'expected_price_per_liter' => 'El precio por litro cambió. Actualiza el catálogo antes de registrar el consumo.',
                    ]);
                }

                // Recheck after the product configuration lock.
                $existing = $this->findBySource($businessId, $branchId, $source, $sourceKey);
                if ($existing) return $this->replay($existing, $fingerprint);

                if ($mode === 'liters') {
                    $litersMilli = $inputUnits;
                    $amountCents = intdiv($litersMilli * $rateCents + 500, 1000);
                } else {
                    $amountCents = $inputUnits;
                    $litersMilli = intdiv($amountCents * 1000 + intdiv($rateCents, 2), $rateCents);
                }
                if ($litersMilli <= 0 || $amountCents <= 0 || $amountCents > 999999999999) {
                    throw ValidationException::withMessages(['value' => 'Cantidad o importe fuera de rango.']);
                }

                $id = DB::table('fuel_dispenses')->insertGetId([
                    'business_id' => $businessId,
                    'branch_id' => $branchId,
                    'fuel_product_id' => (int) $data['fuel_product_id'],
                    'pump_number' => (int) $data['pump_number'],
                    'liters' => $this->decimal($litersMilli, 3),
                    'unit_price' => $this->decimal($rateCents, 2),
                    'total' => $this->decimal($amountCents, 2),
                    'entry_mode' => $mode,
                    'source' => $source,
                    'source_key' => $sourceKey,
                    'request_fingerprint' => $fingerprint,
                    'external_device_id' => $source === 'automatic' ? $data['device_id'] : null,
                    'external_event_id' => $source === 'automatic' ? $data['external_event_id'] : null,
                    'occurred_at' => $source === 'automatic' && ! empty($data['occurred_at'])
                        ? \Illuminate\Support\Carbon::parse($data['occurred_at'])->utc()->toDateTimeString()
                        : null,
                    'status' => 'recorded',
                    'payment_status' => 'unpaid',
                    'invoice_status' => 'not_requested',
                    'created_by' => $user->id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                return ['id' => $id, 'replay' => false];
            }, 2);
        } catch (QueryException $exception) {
            // Unique key is final concurrency barrier, including mismatched-product races.
            $sqlState = (string) $exception->getCode();
            if (! in_array($sqlState, ['23505', '23000'], true)) throw $exception;
            $existing = $this->findBySource($businessId, $branchId, $source, $sourceKey);
            if (! $existing) throw $exception;
            $result = $this->replay($existing, $fingerprint);
        }

        $row = $this->findForResponse($businessId, $branchId, (int) $result['id']);
        return response()->json([
            'data' => $this->serializeDispense($row),
            'idempotent_replay' => $result['replay'],
        ], $result['replay'] ? 200 : 201, ['Cache-Control' => 'no-store']);
    }

    private function findBySource(int $businessId, int $branchId, string $source, string $key): ?object
    {
        return DB::table('fuel_dispenses')->where('business_id', $businessId)->where('branch_id', $branchId)
            ->where('source', $source)->where('source_key', $key)->first();
    }

    private function replay(object $existing, string $fingerprint): array
    {
        abort_unless(hash_equals((string) $existing->request_fingerprint, $fingerprint), 409,
            'La clave de idempotencia ya se usó con otros datos.');
        return ['id' => $existing->id, 'replay' => true];
    }

    private function findForResponse(int $businessId, int $branchId, int $id): object
    {
        return DB::table('fuel_dispenses as fd')
            ->join('fuel_product_configs as fp', 'fp.id', '=', 'fd.fuel_product_id')
            ->join('products as p', 'p.id', '=', 'fp.product_id')
            ->where('fd.business_id', $businessId)->where('fd.branch_id', $branchId)
            ->where('fp.business_id', $businessId)->where('fp.branch_id', $branchId)
            ->where('p.business_id', $businessId)->where('fd.id', $id)
            ->first(['fd.id', 'fd.fuel_product_id', 'fp.product_id', 'fp.label as configured_label', 'p.name as product_name',
                'fd.pump_number', 'fd.liters', 'fd.unit_price', 'fd.total', 'fd.entry_mode',
                'fd.source', 'fd.source_key', 'fd.status', 'fd.payment_status', 'fd.invoice_status',
                'fd.created_at', 'fd.occurred_at']);
    }

    private function serializeDispense(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'fuel_product_id' => (int) $row->fuel_product_id,
            'product_id' => (int) $row->product_id,
            'fuel' => $row->configured_label ?: $row->product_name,
            'pump_number' => (int) $row->pump_number,
            'liters' => (string) $row->liters,
            'price_per_liter' => (string) $row->unit_price,
            'total' => (string) $row->total,
            'currency' => 'GTQ',
            'entry_mode' => $row->entry_mode,
            'source' => $row->source,
            'status' => $row->status,
            'payment_status' => $row->payment_status,
            'invoice_status' => $row->invoice_status,
            'created_at' => \Illuminate\Support\Carbon::parse((string) $row->created_at)->utc()->toIso8601String(),
            'occurred_at' => $row->occurred_at
                ? \Illuminate\Support\Carbon::parse((string) $row->occurred_at, 'UTC')->toIso8601String()
                : null,
        ];
    }

    private function parseUnits(string $value, int $scale): int
    {
        if (! preg_match('/^\d{1,6}(?:\.\d{1,3})?$/D', $value)) {
            throw ValidationException::withMessages(['value' => 'Formato decimal inválido.']);
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        if (strlen($fraction) > $scale) {
            throw ValidationException::withMessages(['value' => 'Precisión decimal no permitida.']);
        }
        return (int) $whole * (10 ** $scale) + (int) str_pad($fraction, $scale, '0');
    }

    private function decimal(int $value, int $scale): string
    {
        $base = 10 ** $scale;
        return intdiv($value, $base).'.'.str_pad((string) ($value % $base), $scale, '0', STR_PAD_LEFT);
    }
}
