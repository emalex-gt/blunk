<?php

namespace App\Services\Routes;

use App\Models\RoutePreparationBatch;
use Illuminate\Validation\ValidationException;

class RouteGlobalPreparationDocuments
{
    /** @return array{batch_ids:array<int,int>,sellers:array<int,array<string,mixed>>} */
    public function forBatches(int $businessId, int $branchId, array $batchIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $batchIds)));
        if ($ids === []) {
            throw ValidationException::withMessages(['batch_ids' => 'Seleccione al menos un lote de preparación.']);
        }
        $batches = RoutePreparationBatch::query()->where('business_id', $businessId)->where('branch_id', $branchId)->whereIn('id', $ids)
            ->where('status', RoutePreparationBatch::STATUS_COMPLETED)
            ->with(['workDay.seller:id,name', 'preSales.preSale.customer:id,name,commercial_name', 'preSales.preSale.items.product.brand'])
            ->orderBy('id')->get();
        if ($batches->count() !== count($ids)) {
            throw ValidationException::withMessages(['batch_ids' => 'Uno de los lotes no pertenece a la sucursal actual o no está completado.']);
        }
        $groups = [];
        foreach ($batches as $batch) {
            $seller = $batch->workDay?->seller;
            if (! $seller) {
                throw ValidationException::withMessages(['batch_ids' => 'Un lote no conserva vendedor de jornada.']);
            }
            $groups[$seller->id] ??= ['seller' => ['id' => $seller->id, 'name' => $seller->name], 'orders' => [], 'products' => []];
            foreach ($batch->preSales as $entry) {
                $preSale = $entry->preSale;
                if (! $preSale) continue;
                $groups[$seller->id]['orders'][] = [
                    'batch_id' => $batch->id,
                    'work_day_id' => $batch->route_work_day_id,
                    'pre_sale_id' => $preSale->id,
                    'customer' => $preSale->customer,
                    'total' => (float) $entry->total_amount,
                    'items' => $preSale->items->where('picked_quantity', '>', 0)->values(),
                ];
                foreach ($preSale->items->where('picked_quantity', '>', 0) as $item) {
                    $product = $item->product;
                    $groups[$seller->id]['products'][$item->product_id] ??= ['product' => $product, 'brand' => $product?->brand?->name, 'quantity' => 0.0];
                    $groups[$seller->id]['products'][$item->product_id]['quantity'] += (float) $item->picked_quantity;
                }
            }
        }
        ksort($groups);
        foreach ($groups as &$group) {
            usort($group['orders'], fn (array $a, array $b) => [mb_strtolower((string) ($a['customer']?->commercial_name ?: $a['customer']?->name)), $a['pre_sale_id']] <=> [mb_strtolower((string) ($b['customer']?->commercial_name ?: $b['customer']?->name)), $b['pre_sale_id']]);
            $group['products'] = array_values($group['products']);
            usort($group['products'], fn (array $a, array $b) => [mb_strtolower((string) $a['brand']), mb_strtolower((string) ($a['product']?->name))] <=> [mb_strtolower((string) $b['brand']), mb_strtolower((string) ($b['product']?->name))]);
        }
        unset($group);
        return ['batch_ids' => $ids, 'sellers' => array_values($groups)];
    }
}
