<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Services\SuperAdmin\OperationsLogReader;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OperationsDiagnosticsController extends Controller
{
    public function index(Request $request, OperationsLogReader $reader): Response
    {
        $input = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'business_id' => ['nullable', 'integer', 'min:1'],
            'category' => ['nullable', 'string', 'max:20'],
            'result' => ['nullable', 'string', 'max:20'],
            'event' => ['nullable', 'string', 'max:100'],
            'quotation_id' => ['nullable', 'integer', 'min:1'],
            'sale_id' => ['nullable', 'integer', 'min:1'],
            'intent_id' => ['nullable', 'integer', 'min:1'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'reason_code' => ['nullable', 'string', 'max:80'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $filters = $reader->normalizeFilters($input);
        $result = $reader->read($filters);

        return Inertia::render('SuperAdmin/System/Operations', [
            'filters' => $filters,
            'result' => $result,
            'event_names' => $reader->eventNames(),
            'businesses' => Business::query()->orderBy('name')->get(['id', 'name']),
            'max_days' => $reader->maxDays(),
        ]);
    }
}
