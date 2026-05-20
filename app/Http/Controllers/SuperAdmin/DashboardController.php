<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Subscription;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('SuperAdmin/Dashboard', [
            'stats' => [
                'tenants' => Business::count(),
                'active_tenants' => Business::where('is_active', true)->count(),
                'users' => User::where('is_super_admin', false)->count(),
                'active_subscriptions' => Subscription::where('status', 'active')->count(),
            ],
        ]);
    }
}
