<?php

namespace App\Services\Routes;

use App\Models\RoutePreparationBatch;
use Illuminate\Validation\ValidationException;

class RouteGlobalPreparationDocuments
{
    public function __construct(private readonly RoutePreparationDocuments $documents)
    {
    }

    /** @return array{batch_ids:array<int,int>,sellers:array<int,array<string,mixed>>,legacy:bool} */
    public function forBatches(int $businessId, int $branchId, array $batchIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $batchIds)));
        if ($ids === []) throw ValidationException::withMessages(['batch_ids' => 'Seleccione al menos un lote de preparación.']);
        $batches = RoutePreparationBatch::query()->where('business_id', $businessId)->where('branch_id', $branchId)->whereIn('id', $ids)
            ->where('status', RoutePreparationBatch::STATUS_COMPLETED)->orderBy('id')->get();
        if ($batches->count() !== count($ids)) throw ValidationException::withMessages(['batch_ids' => 'Uno de los lotes no pertenece a la sucursal actual o no está completado.']);

        $groups = []; $legacy = false;
        foreach ($batches as $batch) {
            $source = $this->documents->document($batch);
            $legacy = $legacy || $source['legacy'];
            foreach ($source['snapshot']['orders'] ?? [] as $order) {
                $seller = $order['seller'] ?? null;
                if (! $seller || ! isset($seller['id'])) throw ValidationException::withMessages(['batch_ids' => 'Un lote no conserva vendedor documental.']);
                $sellerId = (int) $seller['id'];
                $groups[$sellerId] ??= ['seller' => ['id' => $sellerId, 'name' => $seller['name'] ?? '-'], 'orders' => [], 'products' => []];
                $items = array_map(fn (array $line) => (object) [
                    'product' => (object) ($line['product'] ?? []), 'picked_quantity' => $line['prepared_quantity'] ?? '0',
                    'unit_price' => $line['unit_price'] ?? '0', 'discount' => $line['discount'] ?? '0', 'quantity' => $line['prepared_quantity'] ?? '0',
                    'line_total' => $line['line_total'] ?? '0',
                ], $order['lines'] ?? []);
                $groups[$sellerId]['orders'][] = [
                    'batch_id' => (int) $batch->id, 'work_day_id' => (int) ($order['work_day_id'] ?? $batch->route_work_day_id),
                    'pre_sale_id' => (int) $order['pre_sale_id'], 'customer' => (object) ($order['customer'] ?? []),
                    'total' => $order['total_amount'] ?? '0', 'items' => $items,
                ];
                foreach ($order['lines'] ?? [] as $line) {
                    $product = $line['product'] ?? []; $key = (int) ($product['id'] ?? 0);
                    $groups[$sellerId]['products'][$key] ??= ['product' => (object) $product, 'brand' => $product['brand'] ?? null, 'quantity' => '0.0000'];
                    $groups[$sellerId]['products'][$key]['quantity'] = bcadd($groups[$sellerId]['products'][$key]['quantity'], (string) ($line['prepared_quantity'] ?? '0'), 4);
                }
            }
        }
        ksort($groups);
        foreach ($groups as &$group) {
            usort($group['orders'], fn (array $a, array $b) => [mb_strtolower((string) ($a['customer']->commercial_name ?: $a['customer']->name ?: '')), $a['pre_sale_id']] <=> [mb_strtolower((string) ($b['customer']->commercial_name ?: $b['customer']->name ?: '')), $b['pre_sale_id']]);
            $group['products'] = array_values($group['products']);
            foreach ($group['products'] as &$product) {
                $product['quantity'] = (float) $product['quantity'];
            }
            unset($product);
            usort($group['products'], fn (array $a, array $b) => [mb_strtolower((string) $a['brand']), mb_strtolower((string) ($a['product']->name ?? ''))] <=> [mb_strtolower((string) $b['brand']), mb_strtolower((string) ($b['product']->name ?? ''))]);
        } unset($group);

        return ['batch_ids' => $ids, 'sellers' => array_values($groups), 'legacy' => $legacy];
    }
}
