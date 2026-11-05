<?php

namespace Modules\Platform\Http\Controllers;

use App\Core\Support\IndianStates;
use App\Core\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Platform\Models\PlatformInvoice;
use Modules\Platform\Models\PlatformPayment;
use Modules\Platform\Services\InvoiceService;

class InvoiceController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->input('status');
        $invoices = PlatformInvoice::with('tenant')
            ->when($status === 'overdue', fn ($q) => $q->where('status', 'open')->whereDate('due_date', '<', today()))
            ->when(in_array($status, ['open', 'paid', 'void'], true), fn ($q) => $q->where('status', $status))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->whereLike('number', '%'.$request->input('q').'%')
                ->orWhereHas('tenant', fn ($t) => $t->whereLike('name', '%'.$request->input('q').'%'))))
            ->latest('issue_date')->latest('number')->paginate(30)->withQueryString();

        return view('platform::invoices.index', ['invoices' => $invoices, 'status' => $status]);
    }

    public function show(PlatformInvoice $invoice): View
    {
        $invoice->load(['tenant', 'payments', 'subscription.plan']);

        return view('platform::invoices.show', [
            'invoice' => $invoice,
            'seller' => config('platform.seller'),
            'placeOfSupply' => IndianStates::name($invoice->place_of_supply),
            'methods' => PlatformPayment::METHODS,
        ]);
    }

    public function storePayment(Request $request, PlatformInvoice $invoice, InvoiceService $invoices): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'method' => ['required', Rule::in(PlatformPayment::METHODS)],
            'reference' => ['nullable', 'string', 'max:100'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'form_token' => ['required', 'string', 'max:100'],
        ], ['amount.regex' => 'Enter the amount in rupees, for example 3538.82.']);

        return $this->attempt(
            fn () => $invoices->recordPayment($invoice, Money::of($data['amount']), $data['method'], $data['reference'] ?? null,
                CarbonImmutable::parse($data['paid_on']), $this->admin(), 'web-'.$data['form_token']),
            'Payment recorded.'
        );
    }

    public function void(Request $request, PlatformInvoice $invoice, InvoiceService $invoices): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:300']]);

        return $this->attempt(fn () => $invoices->void($invoice, $data['reason'], $this->admin()), "Invoice {$invoice->number} voided.");
    }
}
