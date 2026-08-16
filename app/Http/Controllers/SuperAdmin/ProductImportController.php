<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Exports\ArrayTableExport;
use App\Exports\MultiSheetArrayExport;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Services\SuperAdmin\ProductImportService;
use App\Support\BranchInventory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProductImportController extends Controller
{
    public function __construct(
        private readonly ProductImportService $service,
    ) {
    }

    public function create(Business $business): Response
    {
        return Inertia::render('SuperAdmin/ProductImports/Create', [
            'business' => $this->businessPayload($business),
            'branches' => $this->branchOptions($business),
            'preview' => null,
            'result' => session('product_import_result'),
            'reportUrl' => session('product_import_report_url'),
        ]);
    }

    public function preview(Request $request, Business $business): Response|RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer'],
            'file' => ['required', 'file', 'max:'.ProductImportService::MAX_FILE_SIZE_KB],
        ]);

        $branch = $this->service->validateBranch($business, (int) $data['branch_id']);
        $file = $request->file('file');
        $this->service->validateUploadedFile($file);

        $token = Str::random(48);
        $path = $file->storeAs('product-imports/tmp', "{$token}.xlsx", 'local');
        $preview = $this->service->preview(
            $business,
            $branch,
            Storage::disk('local')->path($path),
            $token,
            $file->getClientOriginalName(),
        );

        return Inertia::render('SuperAdmin/ProductImports/Create', [
            'business' => $this->businessPayload($business),
            'branches' => $this->branchOptions($business),
            'preview' => $preview,
            'result' => null,
            'reportUrl' => null,
        ]);
    }

    public function confirm(Request $request, Business $business): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer'],
            'token' => ['required', 'string'],
            'filename' => ['nullable', 'string', 'max:255'],
        ]);

        $branch = $this->service->validateBranch($business, (int) $data['branch_id']);
        $tmpPath = "product-imports/tmp/{$data['token']}.xlsx";

        if (! Storage::disk('local')->exists($tmpPath)) {
            throw ValidationException::withMessages([
                'file' => 'El archivo temporal ya no está disponible. Sube el archivo nuevamente.',
            ]);
        }

        $result = $this->service->import(
            $business,
            $branch,
            Storage::disk('local')->path($tmpPath),
            (int) $request->user()->id,
            $data['filename'] ?? null,
        );

        $reportToken = Str::random(48);
        $reportPath = "product-imports/reports/{$reportToken}.xlsx";
        Excel::store(new MultiSheetArrayExport($this->service->reportSheets($result)), $reportPath, 'local');
        Storage::disk('local')->delete($tmpPath);

        return redirect()
            ->route('super-admin.product-imports.create', $business)
            ->with('success', 'Importación completada.')
            ->with('product_import_result', $result['summary'])
            ->with('product_import_report_url', route('super-admin.product-imports.report', $reportToken));
    }

    public function template(): BinaryFileResponse
    {
        return Excel::download(
            new ArrayTableExport($this->service->templateRows(), 'Plantilla productos'),
            'plantilla-importacion-productos.xlsx',
        );
    }

    public function report(string $token): BinaryFileResponse
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{48}$/', $token) === 1, 404);
        $path = "product-imports/reports/{$token}.xlsx";
        abort_unless(Storage::disk('local')->exists($path), 404);

        return response()->download(
            Storage::disk('local')->path($path),
            'resultado-importacion-productos.xlsx',
        );
    }

    private function businessPayload(Business $business): array
    {
        return [
            'id' => $business->id,
            'name' => $business->name,
            'settings' => [
                'allow_duplicate_product_codes' => (bool) ($business->tenantSetting?->allow_duplicate_product_codes ?? false),
                'allow_duplicate_product_barcodes' => (bool) ($business->tenantSetting?->allow_duplicate_product_barcodes ?? false),
            ],
        ];
    }

    private function branchOptions(Business $business): array
    {
        BranchInventory::defaultBranchForBusiness($business);

        return $business->branches()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->map(fn ($branch) => [
                'id' => $branch->id,
                'name' => $branch->name,
                'code' => $branch->code,
            ])
            ->all();
    }
}
