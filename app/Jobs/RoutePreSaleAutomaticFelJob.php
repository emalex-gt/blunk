<?php

namespace App\Jobs;

use App\Models\PreSale;
use App\Models\RouteDeliveryBatchPreSale;
use App\Models\User;
use App\Services\Routes\RoutePreSaleFelService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class RoutePreSaleAutomaticFelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 120;

    public function __construct(public int $preSaleId, public int $userId)
    {
        $this->onQueue((string) config('fel.route_automation_queue', 'fel'));
    }

    public function handle(RoutePreSaleFelService $fel): void
    {
        if (! config('fel.route_automation_enabled')) {
            return;
        }

        $preSale = PreSale::query()->find($this->preSaleId);
        $user = User::query()->find($this->userId);
        if (! $preSale || ! $user) {
            return;
        }

        try {
            $fel->certify($preSale, $user, "route-auto-fel-{$preSale->id}");
            RouteDeliveryBatchPreSale::query()->where('pre_sale_id', $preSale->id)->update(['fel_dispatch_status' => 'certified']);
        } catch (Throwable $exception) {
            $status = $preSale->refresh()->convertedSale?->electronicDocument?->status === 'unknown' ? 'unknown' : 'failed';
            RouteDeliveryBatchPreSale::query()->where('pre_sale_id', $preSale->id)->update(['fel_dispatch_status' => $status, 'error_message' => $exception->getMessage()]);
            Log::warning('route_pre_sale.automatic_fel_failed', ['pre_sale_id' => $preSale->id, 'error_class' => $exception::class]);
        }
    }
}
