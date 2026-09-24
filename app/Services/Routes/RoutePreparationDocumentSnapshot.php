<?php

namespace App\Services\Routes;

use App\Models\RoutePreparationBatch;
use App\Models\RoutePreparationBatchPreSale;
use Carbon\CarbonInterface;

class RoutePreparationDocumentSnapshot
{
    public const VERSION = 1;

    /** @return array<string, mixed> */
    public function capture(RoutePreparationBatch $batch, CarbonInterface $preparedAt): array
    {
        $batch->load([
            'business:id,name,phone,email',
            'branch:id,name,address,phone',
            'zone:id,name',
            'workDay:id,work_date,seller_id',
            'workDay.seller:id,name',
            'preparedBy:id,name',
            'preSales' => fn ($query) => $query->orderBy('pre_sale_id'),
            'preSales.preSale.customer:id,name,commercial_name,contact_name,phone,address',
            'preSales.preSale.seller:id,name',
            'preSales.preSale.items.product.brand',
        ]);

        return [
            'version' => self::VERSION,
            'batch' => [
                'id' => (int) $batch->id,
                'business' => $this->person($batch->business, ['phone', 'email']),
                'branch' => $this->person($batch->branch, ['address', 'phone']),
                'zone' => $this->person($batch->zone),
                'work_day' => ['id' => (int) $batch->route_work_day_id, 'work_date' => $batch->workDay?->work_date?->toDateString()],
                'prepared_at' => $preparedAt->toIso8601String(),
                'prepared_by' => $this->person($batch->preparedBy),
                'total_pre_sales' => (int) $batch->total_pre_sales,
                'total_items' => (int) $batch->total_items,
                'total_amount' => $this->decimal($batch->total_amount, 2),
            ],
            'orders' => $batch->preSales->map(fn (RoutePreparationBatchPreSale $entry) => $this->order($entry, $batch))->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function order(RoutePreparationBatchPreSale $entry, RoutePreparationBatch $batch): array
    {
        $preSale = $entry->preSale;

        return [
            'pre_sale_id' => (int) $entry->pre_sale_id,
            'work_day_id' => (int) $batch->route_work_day_id,
            'customer' => $this->person($preSale?->customer, ['commercial_name', 'contact_name', 'phone', 'address']),
            'seller' => $this->person($preSale?->seller ?? $batch->workDay?->seller),
            'notes' => $preSale?->notes,
            'total_items' => (int) $entry->total_items,
            'total_amount' => $this->decimal($entry->total_amount, 2),
            'lines' => $preSale?->items->filter(fn ($item) => bccomp((string) ($item->picked_quantity ?? '0'), '0', 4) === 1)
                ->sortBy('id')->map(fn ($item) => $this->line($item))->values()->all() ?? [],
        ];
    }

    /** @return array<string, mixed> */
    private function line(object $item): array
    {
        $quantity = $this->decimal($item->quantity, 4);
        $prepared = $this->decimal($item->picked_quantity, 4);
        $unitPrice = $this->decimal($item->unit_price, 2);
        $discount = $this->decimal($item->discount, 2);
        $gross = bcmul($unitPrice, $prepared, 6);
        $discounted = $quantity === '0.0000' ? '0' : bcmul(bcdiv($discount, $quantity, 6), $prepared, 6);

        return [
            'product' => [
                'id' => (int) $item->product_id,
                'code' => $item->product?->code,
                'name' => $item->product?->name,
                'brand' => $item->product?->brand?->name,
            ],
            'prepared_quantity' => $prepared,
            'unit_price' => $unitPrice,
            'discount' => $discount,
            'line_total' => $this->roundMoney(bcsub($gross, $discounted, 6)),
        ];
    }

    /** @return array<string, mixed>|null */
    private function person(?object $model, array $extra = []): ?array
    {
        if (! $model) {
            return null;
        }

        return ['id' => (int) $model->id, 'name' => $model->name, ...collect($extra)->mapWithKeys(fn (string $key) => [$key => $model->{$key}])->all()];
    }

    private function decimal(mixed $value, int $scale): string
    {
        return bcadd((string) ($value ?? '0'), '0', $scale);
    }

    private function roundMoney(string $value): string
    {
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $fraction = str_pad($fraction, 3, '0');
        $cents = $whole.str_pad(substr($fraction, 0, 2), 2, '0');
        if ((int) $fraction[2] >= 5) {
            $cents = bcadd($cents, '1', 0);
        }
        $cents = str_pad($cents, 3, '0', STR_PAD_LEFT);
        $result = substr($cents, 0, -2).'.'.substr($cents, -2);

        return $negative ? '-'.$result : $result;
    }
}
