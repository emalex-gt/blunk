<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Exceptions\ProductCostRecalculationSnapshotChanged;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Product;
use App\Services\HistoricalProductCostAverageService;
use App\Services\ProductCostRecalculationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProductCostRecalculationController extends Controller
{
    public function __construct(
        private readonly HistoricalProductCostAverageService $averages,
        private readonly ProductCostRecalculationService $recalculations,
    ) {
    }

    public function index(Request $request): Response
    {
        $data = $request->validate([
            'business_id' => ['nullable', 'integer', 'exists:businesses,id'],
        ]);
        $business = isset($data['business_id'])
            ? Business::query()->findOrFail($data['business_id'])
            : null;

        $summary = null;
        $products = null;
        $simulationToken = null;

        if ($business) {
            $summary = $this->averages->summary($business->id);
            $products = $this->simulationRows($business);
            $simulationToken = Crypt::encryptString(json_encode([
                'business_id' => $business->id,
                'fingerprint' => $summary['fingerprint'],
                'different' => $summary['different'],
                'expires_at' => now()->addMinutes(20)->timestamp,
            ], JSON_THROW_ON_ERROR));
        }

        return Inertia::render('SuperAdmin/ProductCostRecalculation/Index', [
            'businesses' => Business::query()
                ->orderBy('name')
                ->get(['id', 'name', 'currency'])
                ->map(fn (Business $item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'currency' => $item->currency,
                ]),
            'selectedBusiness' => $business ? [
                'id' => $business->id,
                'name' => $business->name,
                'currency' => $business->currency,
            ] : null,
            'summary' => $summary,
            'products' => $products,
            'simulationToken' => $simulationToken,
        ]);
    }

    public function apply(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'simulation_token' => ['required', 'string'],
            'confirmed' => ['accepted'],
        ]);

        try {
            $snapshot = json_decode(Crypt::decryptString($data['simulation_token']), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'simulation_token' => 'La confirmación de simulación no es válida. Genera una nueva simulación.',
            ]);
        }

        if (! is_array($snapshot)
            || ! isset($snapshot['business_id'], $snapshot['fingerprint'], $snapshot['different'], $snapshot['expires_at'])
            || ! is_int($snapshot['business_id'])
            || ! is_string($snapshot['fingerprint'])
            || ! is_int($snapshot['expires_at'])
            || $snapshot['expires_at'] < now()->timestamp) {
            throw ValidationException::withMessages([
                'simulation_token' => 'La simulación venció o no es válida. Genera una nueva simulación.',
            ]);
        }

        $business = Business::query()->findOrFail($snapshot['business_id']);

        try {
            $run = $this->recalculations->apply($business, $request->user(), [
                'fingerprint' => $snapshot['fingerprint'],
                'different' => (int) $snapshot['different'],
            ]);
        } catch (ProductCostRecalculationSnapshotChanged $exception) {
            throw ValidationException::withMessages(['simulation_token' => $exception->getMessage()]);
        }

        return redirect()
            ->route('super-admin.tools.product-cost-recalculation.index', ['business_id' => $business->id])
            ->with('success', "Recálculo aplicado. {$run->metadata['applied_count']} productos actualizados.");
    }

    private function simulationRows(Business $business): LengthAwarePaginator
    {
        $products = Product::query()
            ->where('business_id', $business->id)
            ->with('category:id,name')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString();
        $rowsByProduct = $this->averages
            ->simulateProducts($business->id, $products->getCollection()->pluck('id')->all())
            ->keyBy('product_id');

        $products->setCollection($products->getCollection()->map(function (Product $product) use ($rowsByProduct): array {
            $row = $rowsByProduct->get($product->id);

            return [
                'id' => $product->id,
                'code' => $product->code,
                'name' => $product->name,
                'category' => $product->category?->name,
                'current_cost' => $row['current_cost'],
                'proposed_cost' => $row['proposed_cost'],
                'difference' => $row['difference'],
                'weighted_quantity' => $row['weighted_quantity'],
                'acquisition_count' => $row['acquisition_count'],
                'source' => $row['source'],
                'status' => $row['status'],
                'invalid_acquisition_count' => $row['invalid_acquisition_count'],
            ];
        }));

        return $products;
    }
}
