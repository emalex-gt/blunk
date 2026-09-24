<?php

namespace App\Services\Routes;

use App\Models\RoutePreparationBatch;

class RoutePreparationDocuments
{
    public function __construct(private readonly RoutePreparationDocumentSnapshot $snapshots)
    {
    }

    /** @return array{snapshot:array<string,mixed>,legacy:bool,products:array<int,array<string,mixed>>} */
    public function document(RoutePreparationBatch $batch): array
    {
        $snapshot = $batch->document_snapshot;
        $legacy = ! is_array($snapshot) || ($snapshot['version'] ?? null) !== RoutePreparationDocumentSnapshot::VERSION;

        if ($legacy) {
            $snapshot = $this->snapshots->capture($batch, $batch->prepared_at ?? $batch->created_at ?? now());
        }

        return ['snapshot' => $snapshot, 'legacy' => $legacy, 'products' => $this->products($snapshot['orders'] ?? [])];
    }

    /** @param array<int,array<string,mixed>> $orders
     *  @return array<int,array<string,mixed>> */
    private function products(array $orders): array
    {
        $products = [];
        foreach ($orders as $order) {
            foreach ($order['lines'] ?? [] as $line) {
                $product = $line['product'] ?? [];
                $key = (string) ($product['id'] ?? '').'|'.(string) ($product['code'] ?? '').'|'.(string) ($product['name'] ?? '');
                $products[$key] ??= ['product' => $product, 'brand' => $product['brand'] ?? null, 'quantity' => '0.0000'];
                $products[$key]['quantity'] = bcadd($products[$key]['quantity'], (string) ($line['prepared_quantity'] ?? '0'), 4);
            }
        }
        usort($products, fn (array $a, array $b) => [mb_strtolower((string) $a['brand']), mb_strtolower((string) ($a['product']['name'] ?? ''))] <=> [mb_strtolower((string) $b['brand']), mb_strtolower((string) ($b['product']['name'] ?? ''))]);

        return $products;
    }
}
