<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\TenantSetting;
use Cloudinary\Cloudinary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class CompanySettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        abort_unless(in_array($request->user()->role, ['owner', 'admin'], true), 403);

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
        abort_unless(in_array($request->user()->role, ['owner', 'admin'], true), 403);

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
            $logo = $this->uploadLogo($request);

            if ($settings->company_logo_public_id) {
                $this->cloudinaryClient()->uploadApi()->destroy($settings->company_logo_public_id);
            }

            $payload['company_logo_url'] = $logo['secure_url'];
            $payload['company_logo_public_id'] = $logo['public_id'];
        }

        $settings->update($payload);

        return back()->with('success', 'Configuración guardada.');
    }

    private function cloudinaryClient(): Cloudinary
    {
        $cloudUrl = config('cloudinary.cloud_url') ?: env('CLOUDINARY_URL');

        if (! $cloudUrl) {
            throw new RuntimeException('CLOUDINARY_URL is not configured.');
        }

        return new Cloudinary($cloudUrl);
    }

    private function uploadLogo(Request $request): array
    {
        $file = $request->file('logo');
        $businessId = currentBusinessId();

        if (! $file || ! $file->isValid()) {
            throw new RuntimeException('Invalid uploaded logo.');
        }

        $tempDir = storage_path('app/tmp/cloudinary');

        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $extension = $file->getClientOriginalExtension() ?: 'jpg';
        $tempFilePath = $tempDir.DIRECTORY_SEPARATOR.'logo_'.$businessId.'_'.uniqid('', true).'.'.$extension;

        if (! copy($file->getPathname(), $tempFilePath)) {
            throw new RuntimeException('Could not copy uploaded logo to temp folder.');
        }

        try {
            Log::info('Uploading company logo to Cloudinary', [
                'business_id' => $businessId,
                'folder' => "businesses/{$businessId}/settings",
                'size_bytes' => filesize($tempFilePath),
            ]);

            $result = $this->cloudinaryClient()->uploadApi()->upload($tempFilePath, [
                'folder' => "businesses/{$businessId}/settings",
                'resource_type' => 'image',
            ]);

            $secureUrl = $result['secure_url'] ?? null;
            $publicId = $result['public_id'] ?? null;

            if (! $secureUrl || ! $publicId) {
                throw new RuntimeException('Cloudinary did not return secure_url or public_id.');
            }

            return ['secure_url' => $secureUrl, 'public_id' => $publicId];
        } finally {
            if (file_exists($tempFilePath)) {
                @unlink($tempFilePath);
            }
        }
    }
}
