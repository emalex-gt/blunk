<?php

namespace App\Support;

use Cloudinary\Cloudinary;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CloudinaryUploader
{
    public function uploadImage(UploadedFile $file, string $folder, string $prefix = 'image'): array
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages([
                'logo' => 'No se pudo leer el archivo subido.',
            ]);
        }

        $tempDir = storage_path('app/tmp/cloudinary');
        $tempFilePath = null;

        try {
            if (! is_dir($tempDir)) {
                mkdir($tempDir, 0775, true);
            }

            $extension = $file->getClientOriginalExtension() ?: 'jpg';
            $tempFilePath = $tempDir.DIRECTORY_SEPARATOR.$prefix.'_'.uniqid('', true).'.'.$extension;

            if (! copy($file->getPathname(), $tempFilePath)) {
                throw new RuntimeException('Could not copy uploaded image to temp folder.');
            }

            $result = $this->client()->uploadApi()->upload($tempFilePath, [
                'folder' => $folder,
                'resource_type' => 'image',
            ]);

            $secureUrl = $result['secure_url'] ?? null;
            $publicId = $result['public_id'] ?? null;

            if (! $secureUrl || ! $publicId) {
                throw new RuntimeException('Cloudinary did not return secure_url or public_id.');
            }

            return ['secure_url' => $secureUrl, 'public_id' => $publicId];
        } catch (\Throwable $exception) {
            Log::error('Cloudinary image upload failed', [
                'folder' => $folder,
                'original_name' => $file->getClientOriginalName(),
                'size_bytes' => $file->getSize(),
                'exception_message' => $exception->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'logo' => 'No se pudo subir el logo.',
            ]);
        } finally {
            if ($tempFilePath && file_exists($tempFilePath)) {
                @unlink($tempFilePath);
            }
        }
    }

    public function destroy(?string $publicId): void
    {
        if (! filled($publicId)) {
            return;
        }

        try {
            $this->client()->uploadApi()->destroy($publicId);
        } catch (\Throwable $exception) {
            Log::warning('Cloudinary image deletion failed', [
                'public_id' => $publicId,
                'exception_message' => $exception->getMessage(),
            ]);
        }
    }

    private function client(): Cloudinary
    {
        $cloudUrl = config('cloudinary.cloud_url') ?: env('CLOUDINARY_URL');

        if (! $cloudUrl) {
            throw new RuntimeException('CLOUDINARY_URL is not configured.');
        }

        return new Cloudinary($cloudUrl);
    }
}
