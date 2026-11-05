<?php

namespace Modules\Accounting\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Accounting\Models\CostCentre;
use Modules\Accounting\Models\Ledger;
use Modules\Accounting\Models\Voucher;
use Modules\Accounting\Services\VoucherService;

class VoucherController extends Controller
{
    public function __construct(private readonly VoucherService $vouchers)
    {
    }

    public function index(Request $request): View
    {
        [$from, $to] = $this->range($request);
        $list = Voucher::query()->whereBetween('date', [$from, $to])
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('branch'), fn ($q) => $q->where('branch_id', $request->branch))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->whereLike('number', '%'.$request->q.'%')->orWhereLike('narration', '%'.$request->q.'%')->orWhereLike('reference', '%'.$request->q.'%')))
            ->orderByDesc('date')->orderByDesc('created_at')->paginate(40)->withQueryString();

        return view('accounting::vouchers.index', ['list' => $list, 'from' => $from, 'to' => $to, 'branches' => $this->branches()]);
    }

    public function approvals(): View
    {
        return view('accounting::vouchers.approvals', ['list' => Voucher::where('status', 'pending')->orderBy('submitted_at')->get(),
            'ledgers' => Ledger::pluck('name', 'id')]);
    }

    public function create(Request $request): View
    {
        $type = in_array($request->input('type'), config('accounting.manual_types'), true) ? $request->input('type') : 'payment';

        return view('accounting::vouchers.form', ['v' => new Voucher(['type' => $type, 'date' => today(), 'lines' => []]), ...$this->options()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $v = $this->vouchers->saveDraft($request->all());
        if ($request->input('action') === 'submit') {
            $v = $this->vouchers->submit($v);
        }

        return $this->done($this->message($v), route('app.accounts.vouchers.show', $v));
    }

    public function show(Voucher $voucher): View
    {
        $voucher->load(['entries.ledger', 'entries.costCentre', 'reversalOf', 'reversedBy']);

        return view('accounting::vouchers.show', ['v' => $voucher, 'ledgers' => Ledger::pluck('name', 'id'), 'costCentres' => CostCentre::pluck('name', 'id'),
            'users' => \Modules\Identity\Models\User::whereIn('id', array_filter([$voucher->created_by, $voucher->approved_by, $voucher->posted_by]))->pluck('name', 'id'),
            'needsApproval' => $voucher->status === 'draft' && $this->vouchers->needsApproval($voucher)]);
    }

    public function edit(Voucher $voucher): View
    {
        return view('accounting::vouchers.form', ['v' => $voucher, ...$this->options()]);
    }

    public function update(Request $request, Voucher $voucher): RedirectResponse
    {
        $v = $this->vouchers->saveDraft($request->all(), $voucher);
        if ($request->input('action') === 'submit') {
            $v = $this->vouchers->submit($v);
        }

        return $this->done($this->message($v), route('app.accounts.vouchers.show', $v));
    }

    public function submit(Voucher $voucher): RedirectResponse
    {
        return $this->done($this->message($this->vouchers->submit($voucher)));
    }

    public function approve(Voucher $voucher): RedirectResponse
    {
        $v = $this->vouchers->approve($voucher);

        return $this->done("Approved and posted as {$v->number}.");
    }

    public function reject(Request $request, Voucher $voucher): RedirectResponse
    {
        $this->vouchers->reject($voucher, (string) $request->input('reason'));

        return $this->done('Rejected. It goes back to the person who entered it.');
    }

    public function reverse(Request $request, Voucher $voucher): RedirectResponse
    {
        $rev = $this->vouchers->reverse($voucher, (string) $request->input('reason'), $request->input('date') ?: null);

        return $this->done("Reversed by {$rev->number}.", route('app.accounts.vouchers.show', $rev));
    }

    public function destroy(Voucher $voucher): RedirectResponse
    {
        $this->member()->authorize('accounts.post');
        $voucher->delete();

        return $this->done('Draft deleted.', route('app.accounts.vouchers.index'));
    }

    private function message(Voucher $v): string
    {
        return match ($v->status) {
            'posted' => "Posted as {$v->number}.",
            'pending' => 'Sent for approval: it is at or above the approval limit for this voucher type.',
            default => 'Saved as a draft.',
        };
    }

    private function options(): array
    {
        return ['ledgers' => Ledger::with('group')->where('is_active', true)->orderBy('name')->get(), 'costCentres' => CostCentre::where('is_active', true)->orderBy('name')->get(),
            'branches' => $this->branches()];
    }
}
