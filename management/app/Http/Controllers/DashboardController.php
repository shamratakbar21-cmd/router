<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\RouterNode;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $this->authorize('viewAny', RouterNode::class);

        return view('dashboard', [
            'routerNodeCount' => RouterNode::count(),
            'appliedRouterNodeCount' => RouterNode::where('status', 'applied')->count(),
            'recentAuditLogs' => AuditLog::query()->latest()->limit(10)->get(),
        ]);
    }
}
