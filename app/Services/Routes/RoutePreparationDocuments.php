<?php

namespace App\Services\Routes;

use App\Models\RoutePreparationBatch;
use Illuminate\Support\Collection;

class RoutePreparationDocuments
{
    public function batch(RoutePreparationBatch $batch): RoutePreparationBatch
    {
        return $batch->load([
            'business:id,name,phone,email',
            'branch:id,name,address,phone',
            'zone:id,name',
            'workDay:id,work_date,seller_id',
            'workDay.seller:id,name',
            'preparedBy:id,name',
            'preSales.preSale.customer:id,name,commercial_name,contact_name,phone,address',
            'preSales.preSale.seller:id,name',
            'preSales.preSale.items.product:id,name,code,brand_id',
            'preSales.preSale.items.product.brand:id,name',
        ]);
    }

    public function customers(RoutePreparationBatch $batch): Collection
    {
        return $batch->preSales
            ->map(function ($entry) {
                $preSale = $entry->preSale;

                return [
                    'pre_sale_id' => $preSale?->id,
                    'customer' => $preSale?->customer,
                    'total' => (float) ($entry->total_amount ?? $preSale?->total ?? 0),
                ];
            })
            ->sortBy(fn (array $row) => mb_strtolower((string) ($row['customer']?->commercial_name ?: $row['customer']?->name ?: '')))
            ->values();
    }

    public function products(RoutePreparationBatch $batch): Collection
    {
        return $batch->preSales
            ->flatMap(fn ($entry) => $entry->preSale?->items ?? collect())
            ->filter(fn ($item) => (float) ($item->picked_quantity ?? 0) > 0)
            ->groupBy('product_id')
            ->map(function (Collection $items) {
                $first = $items->first();
                $product = $first->product;

                return [
                    'product' => $product,
                    'brand' => $product?->brand?->name,
                    'quantity' => round((float) $items->sum(fn ($item) => (float) $item->picked_quantity), 4),
                ];
            })
            ->sortBy([
                ['brand', 'asc'],
                [fn (array $row) => mb_strtolower((string) ($row['product']?->name ?? '')), 'asc'],
            ])
            ->values();
    }
}
