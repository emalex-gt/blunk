<?php

namespace App\Support\Products;

use App\Models\Branch;
use App\Models\BranchProductPrice;
use App\Models\Business;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Support\BranchInventory;
use App\Support\PriceLists;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class MainPriceAuditor
{
    public function run(array $options): array
    {
        $businessId = (int) ($options['business'] ?? 0);

        if ($businessId <= 0) {
            throw new InvalidArgumentException('Debes indicar --business=ID. No se permite ejecucion global.');
        }

        $business = Business::query()->findOrFail($businessId);
        $confirm = (bool) ($options['confirm'] ?? false);
        $includeBranch = (bool) ($options['include_branch'] ?? false);
        $branchId = $options['branch'] !== null && $options['branch'] !== ''
            ? (int) $options['branch']
            : null;

        if ($includeBranch && ! $branchId) {
            throw new InvalidArgumentException('Debes indicar --branch=ID cuando usas --include-branch.');
        }

        $branch = null;

        if ($branchId) {
            $branch = Branch::query()
                ->where('business_id', $businessId)
                ->findOrFail($branchId);
        }

        $createdAfter = $this->dateOption($options['created_after'] ?? null, 'created-after');
        $createdBefore = $this->dateOption($options['created_before'] ?? null, 'created-before');
        $default = PriceLists::ensureDefaultPriceType($businessId);
        $pricingScope = BranchInventory::pricingScope($businessId);

        if ($includeBranch && $pricingScope !== 'branch') {
            throw new InvalidArgumentException('--include-branch requiere que el tenant tenga pricing_scope=branch.');
        }

        $summary = [
            'business_id' => $businessId,
            'business_name' => $business->name,
            'mode' => $confirm ? 'confirm' : 'dry-run',
            'pricing_scope' => $pricingScope,
            'price_type_id' => $default->id,
            'price_type_name' => $default->name,
            'price_type_resolution' => 'PriceLists::ensureDefaultPriceType: active price_types.is_default, normalized to one default if needed.',
            'branch_id' => $branch?->id,
            'branch_name' => $branch?->name,
            'include_branch' => $includeBranch,
            'total_products_reviewed' => 0,
            'matches' => 0,
            'differences_detected' => 0,
            'missing_product_prices' => 0,
            'inactive_product_prices' => 0,
            'product_prices_created' => 0,
            'product_prices_updated' => 0,
            'branch_price_differences_detected' => 0,
            'missing_branch_product_prices' => 0,
            'inactive_branch_product_prices' => 0,
            'branch_product_prices_created' => 0,
            'branch_product_prices_updated' => 0,
            'omitted' => 0,
            'filters' => [
                'only_active' => (bool) ($options['only_active'] ?? false),
                'created_after' => $createdAfter?->toDateString(),
                'created_before' => $createdBefore?->toDateString(),
            ],
            'report_path' => null,
        ];

        $rows = [];
        $missingRows = [];
        $branchRows = [];
        $errorRows = [];

        $processor = function () use (
            $businessId,
            $default,
            $branch,
            $includeBranch,
            $confirm,
            $createdAfter,
            $createdBefore,
            $options,
            &$summary,
            &$rows,
            &$missingRows,
            &$branchRows,
            &$errorRows,
        ): void {
            $query = Product::query()
                ->where('business_id', $businessId)
                ->when((bool) ($options['only_active'] ?? false), fn ($query) => $query->where('is_active', true))
                ->when($createdAfter, fn ($query) => $query->whereDate('created_at', '>=', $createdAfter->toDateString()))
                ->when($createdBefore, fn ($query) => $query->whereDate('created_at', '<=', $createdBefore->toDateString()))
                ->orderBy('id');

            $query->chunkById(500, function ($products) use (
                $businessId,
                $default,
                $branch,
                $includeBranch,
                $confirm,
                &$summary,
                &$rows,
                &$missingRows,
                &$branchRows,
                &$errorRows,
            ): void {
                $productIds = $products->pluck('id')->all();
                $productPrices = ProductPrice::query()
                    ->where('business_id', $businessId)
                    ->where('price_type_id', $default->id)
                    ->whereIn('product_id', $productIds)
                    ->get()
                    ->keyBy('product_id');

                $branchPrices = collect();

                if ($branch) {
                    $branchPrices = BranchProductPrice::query()
                        ->where('business_id', $businessId)
                        ->where('branch_id', $branch->id)
                        ->where('price_type_id', $default->id)
                        ->whereIn('product_id', $productIds)
                        ->get()
                        ->keyBy('product_id');
                }

                foreach ($products as $product) {
                    try {
                        $summary['total_products_reviewed']++;
                        $salePriceRaw = $this->rawDecimal($product, 'sale_price');
                        $salePrice = $this->money($salePriceRaw);
                        $salePriceCents = $this->cents($salePriceRaw);
                        $productPrice = $productPrices->get($product->id);
                        $currentProductPriceRaw = $productPrice ? $this->rawDecimal($productPrice, 'price') : null;
                        $currentProductPrice = $currentProductPriceRaw === null ? null : $this->money($currentProductPriceRaw);
                        $currentProductPriceCents = $currentProductPriceRaw === null ? null : $this->cents($currentProductPriceRaw);
                        $difference = $currentProductPrice === null ? null : $this->money($salePrice - $currentProductPrice);
                        $productMismatch = $productPrice === null
                            || ! $productPrice->is_active
                            || $salePriceCents !== $currentProductPriceCents;

                        $action = 'none';

                        if ($productMismatch) {
                            $summary['differences_detected']++;

                            if ($productPrice === null) {
                                $summary['missing_product_prices']++;
                                $action = $confirm ? 'created_product_price' : 'would_create_product_price';
                            } else {
                                if (! $productPrice->is_active) {
                                    $summary['inactive_product_prices']++;
                                }

                                $action = $confirm ? 'updated_product_price' : 'would_update_product_price';
                            }

                            if ($confirm) {
                                ProductPrice::query()->updateOrCreate(
                                    [
                                        'business_id' => $businessId,
                                        'product_id' => $product->id,
                                        'price_type_id' => $default->id,
                                    ],
                                    [
                                        'price' => $salePrice,
                                        'is_active' => true,
                                    ],
                                );

                                if ($productPrice === null) {
                                    $summary['product_prices_created']++;
                                } else {
                                    $summary['product_prices_updated']++;
                                }
                            }
                        } else {
                            $summary['matches']++;
                        }

                        $row = [
                            'product_id' => $product->id,
                            'name' => $product->name,
                            'barcode' => $product->barcode,
                            'code' => $product->code,
                            'product_sale_price_raw' => $salePriceRaw,
                            'main_product_price_raw' => $currentProductPriceRaw ?? '',
                            'product_sale_price_formatted' => $this->formatMoney($salePrice),
                            'main_product_price_formatted' => $currentProductPrice === null ? '' : $this->formatMoney($currentProductPrice),
                            'product_sale_price_cents' => $salePriceCents,
                            'main_product_price_cents' => $currentProductPriceCents ?? '',
                            'product_sale_price' => $this->formatMoney($salePrice),
                            'main_product_price' => $currentProductPrice === null ? '' : $this->formatMoney($currentProductPrice),
                            'difference' => $difference === null ? '' : $this->formatMoney($difference),
                            'price_type_id' => $default->id,
                            'price_type_name' => $default->name,
                            'product_price_active' => $productPrice ? ($productPrice->is_active ? 'yes' : 'no') : '',
                            'branch_id' => $branch?->id,
                            'branch_product_price' => '',
                            'branch_difference' => '',
                            'branch_price_active' => '',
                            'action' => $action,
                        ];

                        if ($productMismatch) {
                            $rows[] = $row;

                            if ($productPrice === null) {
                                $missingRows[] = $row;
                            }
                        }

                        if ($branch) {
                            $branchPrice = $branchPrices->get($product->id);
                            $currentBranchPriceRaw = $branchPrice ? $this->rawDecimal($branchPrice, 'price') : null;
                            $currentBranchPrice = $currentBranchPriceRaw === null ? null : $this->money($currentBranchPriceRaw);
                            $currentBranchPriceCents = $currentBranchPriceRaw === null ? null : $this->cents($currentBranchPriceRaw);
                            $branchDifference = $currentBranchPrice === null ? null : $this->money($salePrice - $currentBranchPrice);
                            $branchMismatch = $branchPrice === null
                                || ! $branchPrice->is_active
                                || $salePriceCents !== $currentBranchPriceCents;
                            $branchAction = 'none';

                            if ($branchMismatch) {
                                $summary['branch_price_differences_detected']++;

                                if ($branchPrice === null) {
                                    $summary['missing_branch_product_prices']++;
                                    $branchAction = $includeBranch
                                        ? ($confirm ? 'created_branch_product_price' : 'would_create_branch_product_price')
                                        : 'not_modified_without_include_branch';
                                } else {
                                    if (! $branchPrice->is_active) {
                                        $summary['inactive_branch_product_prices']++;
                                    }

                                    $branchAction = $includeBranch
                                        ? ($confirm ? 'updated_branch_product_price' : 'would_update_branch_product_price')
                                        : 'not_modified_without_include_branch';
                                }

                                if ($confirm && $includeBranch) {
                                    BranchProductPrice::query()->updateOrCreate(
                                        [
                                            'business_id' => $businessId,
                                            'branch_id' => $branch->id,
                                            'product_id' => $product->id,
                                            'price_type_id' => $default->id,
                                        ],
                                        [
                                            'price' => $salePrice,
                                            'is_active' => true,
                                        ],
                                    );

                                    if ($branchPrice === null) {
                                        $summary['branch_product_prices_created']++;
                                    } else {
                                        $summary['branch_product_prices_updated']++;
                                    }
                                }
                            }

                            if ($branchMismatch) {
                                $branchRows[] = [
                                    ...$row,
                                    'branch_product_price_raw' => $currentBranchPriceRaw ?? '',
                                    'branch_product_price_formatted' => $currentBranchPrice === null ? '' : $this->formatMoney($currentBranchPrice),
                                    'branch_product_price_cents' => $currentBranchPriceCents ?? '',
                                    'branch_product_price' => $currentBranchPrice === null ? '' : $this->formatMoney($currentBranchPrice),
                                    'branch_difference' => $branchDifference === null ? '' : $this->formatMoney($branchDifference),
                                    'branch_price_active' => $branchPrice ? ($branchPrice->is_active ? 'yes' : 'no') : '',
                                    'action' => $branchAction,
                                ];
                            }
                        }
                    } catch (\Throwable $exception) {
                        $summary['omitted']++;
                        $errorRows[] = [
                            'product_id' => $product->id ?? '',
                            'name' => $product->name ?? '',
                            'error' => $exception->getMessage(),
                        ];
                    }
                }
            });
        };

        if ($confirm) {
            DB::transaction($processor);
        } else {
            $processor();
        }

        if ((bool) ($options['report'] ?? false)) {
            $summary['report_path'] = $this->writeReport($summary, $rows, $missingRows, $branchRows, $errorRows);
        }

        return [
            'summary' => $summary,
            'mismatches' => $rows,
            'missing_product_prices' => $missingRows,
            'branch_prices' => $branchRows,
            'errors' => $errorRows,
        ];
    }

    private function dateOption(mixed $value, string $name): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', (string) $value)->startOfDay();
        } catch (\Throwable) {
            throw new InvalidArgumentException("La opcion --{$name} debe usar formato YYYY-MM-DD.");
        }
    }

    private function writeReport(array $summary, array $rows, array $missingRows, array $branchRows, array $errorRows): string
    {
        $timestamp = now()->format('Ymd-His');
        $dir = "products-price-audits/business-{$summary['business_id']}-{$timestamp}";
        Storage::disk('local')->makeDirectory($dir);

        Storage::disk('local')->put($dir.'/summary.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->writeCsv($dir.'/mismatches.csv', $rows);
        $this->writeCsv($dir.'/missing_product_prices.csv', $missingRows);
        $this->writeCsv($dir.'/branch_prices.csv', $branchRows);
        $this->writeCsv($dir.'/errors.csv', $errorRows);

        return Storage::disk('local')->path($dir);
    }

    private function writeCsv(string $path, array $rows): void
    {
        $stream = fopen('php://temp', 'w+');
        $headers = array_keys($rows[0] ?? ['empty' => '']);
        fputcsv($stream, $headers);

        foreach ($rows as $row) {
            fputcsv($stream, array_map(fn ($header) => $row[$header] ?? '', $headers));
        }

        rewind($stream);
        Storage::disk('local')->put($path, stream_get_contents($stream));
        fclose($stream);
    }

    private function money(mixed $value): float
    {
        return round((float) $value, 2);
    }

    private function cents(mixed $value): int
    {
        return (int) round((float) $value * 100);
    }

    private function rawDecimal(object $model, string $attribute): string
    {
        return (string) ($model->getRawOriginal($attribute) ?? $model->{$attribute} ?? 0);
    }

    private function formatMoney(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
