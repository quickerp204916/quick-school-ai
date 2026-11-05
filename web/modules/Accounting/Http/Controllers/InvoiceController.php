<?php

namespace Modules\Accounting\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Accounting\Models\CostCentre;
use Modules\Accounting\Models\Invoice;
use Modules\Accounting\Models\Ledger;
use Modules\Accounting\Services\InvoiceService;

/** Sales and purchase invoices, credit and debit notes, expenses. */
class InvoiceController extends Controller
{
    public function __construct(private readonly InvoiceService $invoices)
    {
    }

    public function index(Request $request): View
    {
        $kind = in_array($request->input('kind'), Invoice::KINDS, true) ? $request->input('kind') : 'sales';
        [$from, $to] = $this->range($request);
        $list = Invoice::with('party')->where('kind', $kind)->whereBetween('date', [$from, $to])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))->orderByDesc('date')->orderByDesc('created_at')->paginate(40)->withQueryString();

        return view('accounting::invoices.index', ['list' => $list, 'kind' => $kind, 'from' => $from, 'to' => $to,
            'due' => $list->getCollection()->mapWithKeys(fn ($i) => [$i->id => $this->invoices->outstanding($i)])]);
    }

    public function expenses(Request $request): View
    {
        return $this->index($request->merge(['kind' => 'expense']));
    }

    public function create(Request $request): View
    {
        $kind = in_array($request->input('kind'), Invoice::KINDS, true) ? $request->input('kind') : 'sales';

        return view('accounting::invoices.form', ['inv' => new Invoice(['kind' => $kind, 'date' => today(), 'against_invoice_id' => $request->input('against')]), 'kind' => $kind, ...$this->options($kind)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $kind = (string) $request->input('kind');
        $inv = $this->invoices->save($kind, self::lines($request));
        if ($request->input('action') === 'submit') {
            $inv = $this->invoices->submit($inv);
        }

        return $this->done($inv->status === 'posted' ? "Posted as {$inv->number}." : ($inv->status === 'pending' ? 'Sent for approval.' : 'Saved as a draft.'), route('app.accounts.invoices.show', $inv));
    }

    public function show(Invoice $bill): View
    {
        $bill->load(['lines.ledger', 'party', 'voucher', 'against', 'tdsSection']);

        return view('accounting::invoices.show', ['inv' => $bill, 'due' => $this->invoices->outstanding($bill),
            'notes' => Invoice::where('against_invoice_id', $bill->id)->get()]);
    }

    public function submit(Invoice $bill): RedirectResponse
    {
        $inv = $this->invoices->submit($bill);

        return $this->done($inv->status === 'posted' ? "Posted as {$inv->number}." : 'Sent for approval.');
    }

    public function destroy(Invoice $bill): RedirectResponse
    {
        $this->member()->authorize('accounts.post');
        abort_unless($bill->status === 'draft', 422, 'Only drafts can be deleted.');
        $kind = $bill->kind;
        $bill->delete();

        return $this->done('Draft deleted.', route('app.accounts.invoices.index', ['kind' => $kind]));
    }

    /** Drops empty rows from the line grid. */
    private static function lines(Request $request): array
    {
        $lines = collect($request->input('lines', []))->filter(fn ($l) => trim((string) ($l['description'] ?? '')) !== '' || trim((string) ($l['rate'] ?? '')) !== '')->values()->all();

        return ['lines' => $lines] + $request->all();
    }

    private function options(string $kind): array
    {
        $sales = in_array($kind, ['sales', 'credit_note'], true);

        return ['parties' => Ledger::whereIn('type', $sales ? ['customer', 'student'] : ['vendor', 'employee'])->where('is_active', true)->orderBy('name')->get(),
            'itemLedgers' => Ledger::with('group')->where('is_active', true)->whereHas('group', fn ($q) => $q->whereIn('nature', $sales ? ['income'] : ['expense', 'asset']))
                ->whereNotIn('type', ['cash', 'bank', 'tax', 'gateway'])->orderBy('name')->get(),
            'costCentres' => CostCentre::where('is_active', true)->orderBy('name')->get(), 'branches' => $this->branches(),
            'againstList' => in_array($kind, ['credit_note', 'debit_note'], true) ? Invoice::where('status', 'posted')->whereIn('kind', $kind === 'credit_note' ? ['sales'] : ['purchase', 'expense'])->orderByDesc('date')->get() : collect()];
    }
}
