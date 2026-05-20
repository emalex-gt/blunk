<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Subscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TenantSubscriptionController extends Controller
{
    public function edit(Business $business): Response
    {
        return Inertia::render('SuperAdmin/Tenants/Subscription', [
            'tenant' => $business,
            'subscription' => $business->latestSubscription,
            'statuses' => Subscription::STATUSES,
        ]);
    }

    public function update(Request $request, Business $business): RedirectResponse
    {
        $data = $request->validate([
            'plan_name' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::in(Subscription::STATUSES)],
            'price_amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $subscription = $business->latestSubscription ?: new Subscription(['business_id' => $business->id]);
        $subscription->fill([
            ...$data,
            'currency' => strtoupper($data['currency']),
        ]);
        $this->applyStatusDates($subscription, $data['status']);
        $subscription->save();

        return back();
    }

    public function setStatus(Business $business, string $status): RedirectResponse
    {
        abort_unless(in_array($status, Subscription::STATUSES, true), 404);

        $subscription = $business->latestSubscription ?: new Subscription([
            'business_id' => $business->id,
            'plan_name' => 'Manual',
            'price_amount' => 0,
            'currency' => $business->currency ?: 'GTQ',
        ]);
        $subscription->status = $status;
        $this->applyStatusDates($subscription, $status);
        $subscription->save();

        return back();
    }

    private function applyStatusDates(Subscription $subscription, string $status): void
    {
        if ($status === 'paused') {
            $subscription->paused_at = now();
        }

        if ($status === 'cancelled') {
            $subscription->cancelled_at = now();
        }

        if ($status === 'active') {
            $subscription->paused_at = null;
            $subscription->cancelled_at = null;
        }
    }
}
