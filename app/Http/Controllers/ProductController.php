<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\StockMovement;
use App\Support\BranchInventory;
use App\Support\PriceLists;
use App\Support\ProductSupplierCostHistory;
use App\Support\StockAvailability;
use Cloudinary\Cloudinary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        $businessId = currentBusinessId();
        $search = $request->string('search')->toString();

        $products = Product::query()
            ->where('business_id', $businessId)
            ->with([
                'category:id,name',
                'prices' => fn ($query) => $query
                    ->where('business_id', $businessId)
                    ->where('is_active', true)
                    ->select(['id', 'business_id', 'product_id', 'price_type_id', 'price']),
            ])
            ->when($search, function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'ilike', "%{$search}%")
                        ->orWhere('code', 'ilike', "%{$search}%")
                        ->orWhere('barcode', 'ilike', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->get();
        $activeBranch = BranchInventory::activeBranch($businessId);
        BranchInventory::applyBranchStockAndPrices($products, $businessId, $activeBranch->id);
        PriceLists::applyBranchPricesToProducts($products, $businessId, $activeBranch->id);
        $supplierCostHistory = ProductSupplierCostHistory::forProducts(
            $businessId,
            $products->pluck('id')->all(),
        );

        $products->each(function (Product $product) use ($supplierCostHistory) {
            $product->setAttribute(
                'supplier_cost_history',
                $supplierCostHistory->get($product->id, collect())->values(),
            );
        });

        return Inertia::render('Products/Index', [
            'products' => $products,
            'priceTypes' => PriceLists::active($businessId)->values(),
            'pricingScope' => BranchInventory::pricingScope($businessId),
            'activeBranch' => BranchInventory::pricingScope($businessId) === 'branch' ? [
                'id' => $activeBranch->id,
                'name' => $activeBranch->name,
            ] : null,
            'categories' => Category::query()
                ->where('business_id', $businessId)
                ->orderBy('name')
                ->get(['id', 'name']),
            'filters' => ['search' => $search],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatedProduct($request);
        $categoryName = $data['category_name'] ?? null;
        unset($data['category_name'], $data['image']);
        $businessId = currentBusinessId();
        $data['business_id'] = $businessId;
        $useProductImages = tenantSetting('use_product_images', true);

        DB::transaction(function () use ($request, $data, $categoryName, $useProductImages, $businessId) {
            $data['category_id'] = $this->resolveCategoryId(
                $businessId,
                $categoryName,
            );

            if ($useProductImages && $request->hasFile('image')) {
                $data = [
                    ...$data,
                    ...$this->uploadProductImage($request),
                ];
            }

            $prices = $data['prices'] ?? [];
            unset($data['prices']);

            $product = Product::create($data);
            $branch = BranchInventory::activeBranch($businessId);
            $this->syncProductPrices($product, $prices, (float) $product->sale_price, $branch->id);
            BranchInventory::adjust($product, $branch->id, (float) $product->stock);

            if ($product->stock > 0) {
                StockMovement::create([
                    'business_id' => $businessId,
                    'branch_id' => $branch->id,
                    'product_id' => $product->id,
                    'type' => 'initial',
                    'quantity' => $product->stock,
                    'note' => stockMovementNote('initial'),
                    'created_by' => $request->user()->id,
                ]);
            }
        });

        return back()->with('success', 'Producto creado.');
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        abort_unless($product->business_id === currentBusinessId(), 403);
        $useProductImages = tenantSetting('use_product_images', true);

        DB::transaction(function () use ($request, $product, $useProductImages) {
            $data = $this->validatedProduct($request);
            $prices = $data['prices'] ?? [];
            $categoryName = $data['category_name'] ?? null;
            unset($data['category_name'], $data['image'], $data['prices']);
            $branch = BranchInventory::activeBranch(currentBusinessId());
            $oldStock = (float) (BranchInventory::stockMap(currentBusinessId(), [$product->id], $branch->id)[$product->id] ?? 0);
            $oldImagePublicId = $product->image_public_id;
            $data['category_id'] = $this->resolveCategoryId(
                currentBusinessId(),
                $categoryName,
            );

            if ($useProductImages && $request->hasFile('image')) {
                $data = [
                    ...$data,
                    ...$this->uploadProductImage($request),
                ];
            }

            $targetStock = (float) ($data['stock'] ?? $oldStock);
            $salePrice = (float) ($data['sale_price'] ?? $product->sale_price);
            unset($data['stock']);
            if (BranchInventory::pricingScope(currentBusinessId()) === 'branch') {
                unset($data['sale_price']);
            }
            $product->update($data);
            $this->syncProductPrices($product, $prices, $salePrice, $branch->id);
            [$previousStock, $newStock] = BranchInventory::adjust($product, $branch->id, $targetStock);

            if ($useProductImages && $request->hasFile('image') && $oldImagePublicId) {
                try {
                    $cloudinary = $this->cloudinaryClient();
                    $cloudinary->uploadApi()->destroy($oldImagePublicId);
                } catch (\Throwable $exception) {
                    Log::warning('Cloudinary product image deletion failed', [
                        'product_id' => $product->id,
                        'business_id' => currentBusinessId(),
                        'public_id' => $oldImagePublicId,
                        'exception_message' => $exception->getMessage(),
                    ]);
                }
            }

            $difference = $newStock - $previousStock;

            if ($difference !== 0) {
                StockMovement::create([
                    'business_id' => currentBusinessId(),
                    'branch_id' => $branch->id,
                    'product_id' => $product->id,
                    'type' => 'manual',
                    'quantity' => $difference,
                    'note' => stockMovementNote('adjustment'),
                    'created_by' => $request->user()->id,
                ]);
            }
        });

        return back()->with('success', 'Producto actualizado.');
    }

    public function stockHistory(Request $request, Product $product): Response
    {
        abort_unless($product->business_id === currentBusinessId(), 403);
        $businessId = currentBusinessId();
        $activeBranch = BranchInventory::activeBranch($businessId);
        $stock = (float) (BranchInventory::stockMap($businessId, [$product->id], $activeBranch->id)[$product->id] ?? 0);
        $reserved = StockAvailability::reservedStock($product, null, $activeBranch->id);

        $movements = StockMovement::query()
            ->where('business_id', $businessId)
            ->where('branch_id', $activeBranch->id)
            ->where('product_id', $product->id)
            ->with(['createdBy:id,name', 'user:id,name'])
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Products/StockHistory', [
            'product' => [
                ...$product->only([
                'id',
                'name',
                'code',
                'barcode',
                'location',
                'image_url',
                ]),
                'stock' => $stock,
                'reserved_stock' => $reserved,
                'available_stock' => $stock - $reserved,
                'branch' => [
                    'id' => $activeBranch->id,
                    'name' => $activeBranch->name,
                ],
            ],
            'movements' => $movements,
        ]);
    }

    private function validatedProduct(Request $request): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:255'],
            'barcode' => ['nullable', 'string', 'max:255'],
            'cost_price' => ['required', 'numeric', 'min:0'],
            'sale_price' => ['required', 'numeric', 'min:0'],
            'stock' => ['required', 'integer', 'min:0'],
            'min_stock' => ['required', 'integer', 'min:0'],
            'location' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'category_name' => ['nullable', 'string', 'max:255'],
            'prices' => ['nullable', 'array'],
            'prices.*' => ['nullable', 'numeric', 'min:0'],
        ];

        if (tenantSetting('use_product_images', true)) {
            $rules['image'] = ['nullable', 'image', 'max:5120'];
        }

        return $request->validate($rules, [
            'image.max' => 'La imagen no debe superar los 5MB después de comprimirse.',
        ]);
    }

    private function syncProductPrices(Product $product, array $prices, ?float $defaultSalePrice = null, ?int $branchId = null): void
    {
        $default = PriceLists::ensureDefaultPriceType((int) $product->business_id);
        $prices[$default->id] = $prices[$default->id] ?? ($defaultSalePrice ?? $product->sale_price);

        PriceLists::updatePricesForProduct($product, $prices, $branchId);
    }

    private function resolveCategoryId(int $businessId, ?string $categoryName): ?int
    {
        $categoryName = trim((string) $categoryName);

        if ($categoryName === '') {
            return null;
        }

        $category = Category::query()
            ->where('business_id', $businessId)
            ->whereRaw('LOWER(name) = ?', [strtolower($categoryName)])
            ->first();

        if ($category) {
            return $category->id;
        }

        return Category::create([
            'business_id' => $businessId,
            'name' => $categoryName,
        ])->id;
    }

    private function uploadProductImage(Request $request): array
    {
        $file = $request->file('image');
        $businessId = currentBusinessId();
        $folder = "businesses/{$businessId}/products";
        $tempFilePath = null;

        try {
            if (! $file || ! $file->isValid()) {
                throw new RuntimeException('Invalid uploaded image.');
            }

            $tempDir = storage_path('app/tmp/cloudinary');

            if (! is_dir($tempDir)) {
                mkdir($tempDir, 0775, true);
            }

            Log::info('Cloudinary temp folder check', [
                'exists' => is_dir($tempDir),
                'is_writable' => is_writable($tempDir),
            ]);

            $extension = $file->getClientOriginalExtension() ?: 'jpg';
            $tempFileName = 'product_'.$businessId.'_'.uniqid('', true).'.'.$extension;
            $tempFilePath = $tempDir.DIRECTORY_SEPARATOR.$tempFileName;

            if (! copy($file->getPathname(), $tempFilePath)) {
                throw new RuntimeException('Could not copy uploaded image to temp folder.');
            }

            if (! file_exists($tempFilePath) || ! is_readable($tempFilePath)) {
                throw new RuntimeException('Cloudinary temp image is not readable.');
            }

            Log::info('Uploading product image to Cloudinary', [
                'business_id' => currentBusinessId(),
                'user_id' => $request->user()->id,
                'has_cloudinary_cloud_url' => filled(config('cloudinary.cloud_url')),
                'folder' => $folder,
                'original_name' => $file?->getClientOriginalName(),
                'mime_type' => $file?->getMimeType(),
                'size_bytes' => $file?->getSize(),
            ]);

            Log::info('Cloudinary temp file copied', [
                'path_exists' => file_exists($tempFilePath),
                'is_readable' => is_readable($tempFilePath),
                'size_bytes' => filesize($tempFilePath),
            ]);

            $cloudinary = $this->cloudinaryClient();

            $result = $cloudinary->uploadApi()->upload(
                $tempFilePath,
                [
                    'folder' => $folder,
                    'resource_type' => 'image',
                ]
            );

            $imageUrl = $result['secure_url'] ?? null;
            $publicId = $result['public_id'] ?? null;

            if (! $imageUrl || ! $publicId) {
                throw new RuntimeException('Cloudinary did not return secure_url or public_id.');
            }

            Log::info('Cloudinary product image upload response', [
                'business_id' => currentBusinessId(),
                'has_secure_url' => filled($imageUrl),
                'has_public_id' => filled($publicId),
                'bytes' => $result['bytes'] ?? null,
                'format' => $result['format'] ?? null,
            ]);
        } catch (\Throwable $exception) {
            Log::error('Cloudinary product image upload failed', [
                'business_id' => currentBusinessId(),
                'user_id' => $request->user()->id,
                'has_cloudinary_cloud_url' => filled(config('cloudinary.cloud_url')),
                'folder' => $folder,
                'original_name' => $file?->getClientOriginalName(),
                'size_bytes' => $file?->getSize(),
                'exception_message' => $exception->getMessage(),
                'exception' => $exception,
            ]);

            throw ValidationException::withMessages([
                'image' => 'No se pudo subir la imagen',
            ]);
        } finally {
            if ($tempFilePath && file_exists($tempFilePath)) {
                @unlink($tempFilePath);
            }
        }

        return [
            'image_url' => $imageUrl,
            'image_public_id' => $publicId,
        ];
    }

    private function cloudinaryClient(): Cloudinary
    {
        $cloudUrl = config('cloudinary.cloud_url') ?: env('CLOUDINARY_URL');

        if (! $cloudUrl) {
            throw new RuntimeException('CLOUDINARY_URL is not configured.');
        }

        return new Cloudinary($cloudUrl);
    }
}
