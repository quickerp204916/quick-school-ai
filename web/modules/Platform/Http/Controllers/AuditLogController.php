<?php

namespace Modules\Platform\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Platform\Models\PlatformAdmin;
use Modules\Platform\Models\PlatformAuditLog;

class AuditLogController extends Controller
{
    public function __invoke(Request $request): View
    {
        $logs = PlatformAuditLog::with('admin', 'tenant')
            ->when($request->filled('action'), fn ($q) => $q->whereLike('action', $request->input('action').'%'))
            ->when($request->filled('admin'), fn ($q) => $q->where('platform_admin_id', $request->input('admin')))
            ->when($request->filled('tenant'), fn ($q) => $q->where('tenant_id', $request->input('tenant')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->input('to')))
            ->latest('id')->paginate(50)->withQueryString();

        return view('platform::audit.index', ['logs' => $logs, 'admins' => PlatformAdmin::orderBy('name')->get()]);
    }
}
