<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\TenantFelSetting;
use App\Services\Fel\Providers\Digifact\DigifactClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FelConnectionController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        abort_unless(in_array($request->user()->role, ['owner', 'admin'], true), 403);

        $business = Business::query()->findOrFail(currentBusinessId());
        abort_unless($business->country === 'GT', 403);

        $settings = TenantFelSetting::query()->firstOrCreate(
            ['business_id' => $business->id],
            [
                'provider' => 'digifact',
                'environment' => 'test',
                'enabled' => false,
                'test_base_url' => config('digifact.test_base_url'),
                'production_base_url' => config('digifact.production_base_url'),
            ],
        );

        try {
            DigifactClient::forBusiness($business)->testConnection();

            $settings->update([
                'last_successful_connection_at' => now(),
                'last_error' => null,
            ]);

            return back()->with('success', 'Conexion exitosa con Digifact.');
        } catch (\Throwable $exception) {
            $settings->update([
                'last_error' => $exception->getMessage(),
            ]);

            return back()->withErrors([
                'fel_connection' => 'No se pudo conectar con Digifact.',
            ]);
        }
    }
}
