<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\TenantSetting;
use App\Support\CloudinaryUploader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CompanySettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        abort_unless((bool) $request->user()?->is_super_admin, 403);

        $settings = TenantSetting::query()->firstOrCreate(
            ['business_id' => currentBusinessId()],
            ['use_product_images' => true, 'max_users' => 1],
        );
        $business = Business::query()->select('id', 'name', 'country')->findOrFail(currentBusinessId());

        return Inertia::render('Settings/Company', [
            'business' => $business,
            'settings' => $settings->only([
                'company_logo_url',
                'company_name',
                'company_tax_id',
                'company_address',
                'company_phone',
                'receipt_format',
                'allow_manual_price',
                'remember_last_customer_product_price',
            ]),
            'felSettings' => null,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless((bool) $request->user()?->is_super_admin, 403);

        $data = $request->validate([
            'company_name' => ['nullable', 'string', 'max:255'],
            'company_tax_id' => ['nullable', 'string', 'max:255'],
            'company_address' => ['nullable', 'string', 'max:255'],
            'company_phone' => ['nullable', 'string', 'max:255'],
            'receipt_format' => ['required', 'in:ticket,document'],
            'allow_manual_price' => ['nullable', 'boolean'],
            'remember_last_customer_product_price' => ['nullable', 'boolean'],
            'logo' => ['nullable', 'image', 'max:5120'],
        ], [
            'logo.max' => 'El logo no debe superar los 5MB.',
        ]);

        $settings = TenantSetting::query()->firstOrCreate(
            ['business_id' => currentBusinessId()],
            ['use_product_images' => true, 'max_users' => 1],
        );

        $payload = [
            'company_name' => $data['company_name'] ?? null,
            'company_tax_id' => $data['company_tax_id'] ?? null,
            'company_address' => $data['company_address'] ?? null,
            'company_phone' => $data['company_phone'] ?? null,
            'receipt_format' => $data['receipt_format'],
            'allow_manual_price' => (bool) ($data['allow_manual_price'] ?? false),
            'remember_last_customer_product_price' => (bool) ($data['remember_last_customer_product_price'] ?? false),
        ];

        if ($request->hasFile('logo')) {
            $cloudinary = app(CloudinaryUploader::class);
            $logo = $cloudinary->uploadImage(
                $request->file('logo'),
                'businesses/'.currentBusinessId().'/settings',
                'company_logo',
            );

            $cloudinary->destroy($settings->company_logo_public_id);

            $payload['company_logo_url'] = $logo['secure_url'];
            $payload['company_logo_public_id'] = $logo['public_id'];
        }

        $settings->update($payload);

        return back()->with('success', 'Configuración guardada.');
    }
}
