<?php

namespace Modules\Platform\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Platform\Models\SupportSession;
use Modules\Platform\Models\Tenant;
use Modules\Platform\Services\SupportAccess;

class SupportSessionController extends Controller
{
    public function index(): View
    {
        return view('platform::support.index', [
            'active' => SupportSession::with('tenant', 'admin')->whereNull('ended_at')->where('expires_at', '>', now())->latest('started_at')->get(),
            'recent' => SupportSession::with('tenant', 'admin')->latest('started_at')->limit(30)->get(),
        ]);
    }

    public function store(Request $request, Tenant $tenant, SupportAccess $support): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'minutes' => ['required', 'integer'],
        ]);

        return $this->attempt(
            fn () => $support->start($this->admin(), $tenant, $data['reason'], (int) $data['minutes']),
            'Support session started. The institution owner can see it in their audit log.'
        );
    }

    public function end(SupportSession $session, SupportAccess $support): RedirectResponse
    {
        return $this->attempt(fn () => $support->end($session, $this->admin()), 'Support session ended.');
    }
}
