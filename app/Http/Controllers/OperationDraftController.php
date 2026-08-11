<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\OperationDraft;
use App\Models\Supplier;
use App\Support\OperationDrafts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OperationDraftController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', Rule::in(OperationDrafts::allowedTypes())],
        ]);

        $drafts = OperationDrafts::activeQueryFor($request, $data['type'])
            ->with(['branch:id,name', 'sourceBranch:id,name', 'destinationBranch:id,name', 'customer:id,name,doc_number', 'supplier:id,name', 'user:id,name'])
            ->latest('updated_at')
            ->limit(50)
            ->get()
            ->map(fn (OperationDraft $draft) => $this->serializeDraft($draft));

        return response()->json(['drafts' => $drafts]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', Rule::in(OperationDrafts::allowedTypes())],
            'title' => ['nullable', 'string', 'max:255'],
            'branch_id' => ['nullable', 'integer'],
            'customer_id' => ['nullable', 'integer'],
            'supplier_id' => ['nullable', 'integer'],
            'source_branch_id' => ['nullable', 'integer'],
            'destination_branch_id' => ['nullable', 'integer'],
            'payload' => ['required', 'array'],
            'payload_version' => ['nullable', 'integer', 'min:1'],
        ]);

        OperationDrafts::assertTypePermission($request->user(), $data['type']);

        $draft = OperationDraft::query()->create([
            'business_id' => currentBusinessId(),
            'branch_id' => OperationDrafts::validateBusinessReference($data['branch_id'] ?? null, Branch::class, 'branch_id'),
            'user_id' => $request->user()->id,
            'type' => $data['type'],
            'title' => $data['title'] ?? null,
            'customer_id' => OperationDrafts::validateBusinessReference($data['customer_id'] ?? null, Customer::class, 'customer_id'),
            'supplier_id' => OperationDrafts::validateBusinessReference($data['supplier_id'] ?? null, Supplier::class, 'supplier_id'),
            'source_branch_id' => OperationDrafts::validateBusinessReference($data['source_branch_id'] ?? null, Branch::class, 'source_branch_id'),
            'destination_branch_id' => OperationDrafts::validateBusinessReference($data['destination_branch_id'] ?? null, Branch::class, 'destination_branch_id'),
            'payload' => $data['payload'],
            'payload_version' => OperationDrafts::normalizePayloadVersion($data['payload_version'] ?? null),
            'status' => OperationDraft::STATUS_ACTIVE,
            'last_used_at' => now(),
        ]);

        $draft->load(['branch:id,name', 'sourceBranch:id,name', 'destinationBranch:id,name', 'customer:id,name,doc_number', 'supplier:id,name', 'user:id,name']);

        return response()->json(['draft' => $this->serializeDraft($draft)], 201);
    }

    public function discard(Request $request, OperationDraft $draft): JsonResponse
    {
        abort_unless((int) $draft->business_id === (int) currentBusinessId(), 403);
        OperationDrafts::assertTypePermission($request->user(), $draft->type);
        abort_unless((int) $draft->user_id === (int) $request->user()->id || OperationDrafts::canManageOthers($request->user()), 403);

        if ($draft->status === OperationDraft::STATUS_ACTIVE) {
            $draft->update([
                'status' => OperationDraft::STATUS_DISCARDED,
                'discarded_at' => now(),
                'last_used_at' => now(),
            ]);
        }

        return response()->json(['ok' => true]);
    }

    private function serializeDraft(OperationDraft $draft): array
    {
        $payload = $draft->payload ?? [];
        $items = collect($payload['cart'] ?? $payload['items'] ?? []);
        $total = (float) ($payload['total'] ?? $items->sum(function ($item) {
            $quantity = (float) ($item['quantity'] ?? 0);
            $price = (float) ($item['unit_price'] ?? $item['unit_cost'] ?? 0);

            return $quantity * $price;
        }));

        return [
            'id' => $draft->id,
            'type' => $draft->type,
            'title' => $draft->title,
            'payload' => $payload,
            'payload_version' => $draft->payload_version,
            'customer' => $draft->customer ? ['id' => $draft->customer->id, 'name' => $draft->customer->name, 'doc_number' => $draft->customer->doc_number] : null,
            'supplier' => $draft->supplier ? ['id' => $draft->supplier->id, 'name' => $draft->supplier->name] : null,
            'branch' => $draft->branch ? ['id' => $draft->branch->id, 'name' => $draft->branch->name] : null,
            'source_branch' => $draft->sourceBranch ? ['id' => $draft->sourceBranch->id, 'name' => $draft->sourceBranch->name] : null,
            'destination_branch' => $draft->destinationBranch ? ['id' => $draft->destinationBranch->id, 'name' => $draft->destinationBranch->name] : null,
            'user' => $draft->user ? ['id' => $draft->user->id, 'name' => $draft->user->name] : null,
            'item_count' => $items->count(),
            'total' => round($total, 2),
            'updated_at' => $draft->updated_at?->toISOString(),
            'last_used_at' => $draft->last_used_at?->toISOString(),
        ];
    }
}
