{{-- The receipt itself, shared by the staff view and the printable parent link. --}}
<div class="doc">
    <header><b style="font-size:18px">{{ \Modules\Academic\Support\Institution::tenant()->name }}</b><div>Fee receipt</div></header>
    <dl class="kv"><dt>Receipt no</dt><dd><b>{{ $p->number }}</b></dd><dt>Date</dt><dd>{{ $p->date->format('j M Y') }}</dd>
        <dt>Student</dt><dd>{{ $p->student->fullName() }} ({{ $p->student->admission_no }})</dd><dt>Class</dt><dd>{{ $p->student->currentEnrollment?->section?->label() }}</dd>
        <dt>Paid by</dt><dd>{{ \Modules\Fees\Models\FeePayment::MODES[$p->mode] }}{{ $p->reference ? ' · '.$p->reference : '' }}</dd></dl>
    <table style="margin-top:12px"><thead><tr><th>Towards</th><th class="num">Amount</th></tr></thead><tbody>
    @foreach ($p->allocations->where('via', 'payment') as $a)<tr><td>{{ $a->invoice->number }} · {{ $a->invoice->period }}</td><td class="num">{{ \Modules\Accounting\Support\Amount::format($a->amount) }}</td></tr>@endforeach
    @php($advanceAtReceipt = $p->amount - $p->allocations->where('via', 'payment')->sum('amount'))
    @if ($advanceAtReceipt > 0)<tr><td>Advance (used on later invoices)</td><td class="num">{{ \Modules\Accounting\Support\Amount::format($advanceAtReceipt) }}</td></tr>@endif
    <tr><td><b>Total received</b></td><td class="num"><b>{{ \Modules\Accounting\Support\Amount::format($p->amount) }}</b></td></tr></tbody></table>
    <p>Rupees {{ \Modules\Academic\Support\Words::number(intdiv($p->amount, 100)) }}{{ $p->amount % 100 ? ' and '.\Modules\Academic\Support\Words::number($p->amount % 100).' paise' : '' }} only.</p>
    @if ($p->status === 'bounced')<p><b>This cheque was returned unpaid. The receipt is cancelled.</b></p>@endif
    @if ($p->refunded)<p>Refunded: {{ \Modules\Accounting\Support\Amount::format($p->refunded) }}</p>@endif
    @if ($note)<p class="small">{{ $note }}</p>@endif
    <p class="small muted">Computer-generated receipt. No signature is needed.</p>
</div>
