@extends('academic::layout')
@section('title', 'Bank reconciliation')
@section('content')
<div class="head"><div><h1>Bank reconciliation</h1><p class="muted" style="margin:4px 0 0">Import the bank statement, match it to the books, and see what is still outstanding.</p></div></div>
@if (! $bank)<div class="card empty">No bank ledgers yet. Add one in the chart of accounts with type Bank.</div>@else
<form method="get" class="filters">
    <label class="field">Bank<select name="bank" onchange="this.form.submit()">@foreach ($banks as $b)<option value="{{ $b->id }}" @selected($b->id === $bank->id)>{{ $b->name }}</option>@endforeach</select></label>
    <label class="field">As on<input type="date" name="as_on" value="{{ $asOn }}"></label><button class="btn" type="submit">Show</button>
</form>
<section class="card"><h2>Reconciliation statement as on {{ \Illuminate\Support\Carbon::parse($asOn)->format('j M Y') }}</h2>
    <div class="table-wrap"><table><tbody>
        <tr><td>Balance as per books</td><td class="num"><b>{{ $drcr($brs['books']) }}</b></td></tr>
        <tr><td>Less: deposits not yet credited by the bank ({{ $brs['deposits_not_credited']->count() }})</td><td class="num">{{ $money($brs['deposits_not_credited']->sum('debit')) }}</td></tr>
        <tr><td>Add: cheques and payments not yet presented ({{ $brs['payments_not_presented']->count() }})</td><td class="num">{{ $money($brs['payments_not_presented']->sum('credit')) }}</td></tr>
        <tr><td>Add or less: bank entries not in the books ({{ $brs['not_in_books']->count() }}), such as charges and interest</td><td class="num">{{ $drcr($brs['not_in_books']->sum('amount')) }}</td></tr>
        <tr><td><b>Balance the bank statement should show</b></td><td class="num"><b>{{ $drcr($brs['bank']) }}</b></td></tr>
    </tbody></table></div>
    @if ($brs['not_in_books']->isNotEmpty())<p class="small muted">Bank charges or interest on the statement but not in the books: post a payment or receipt voucher for them, then match.</p>@endif
</section>
@can('academic.accounts.post')
<form class="card" method="post" action="{{ route('app.accounts.bank.import') }}" enctype="multipart/form-data">@csrf
    <input type="hidden" name="bank" value="{{ $bank->id }}">
    <h2>Import a statement</h2>
    <p class="small muted" style="margin-top:-6px">CSV from net banking with a header row: Date, Description and either Amount or Withdrawal and Deposit (Reference optional). Lines already imported are skipped.</p>
    <div class="filters"><label class="field grow">CSV file<input type="file" name="file" accept=".csv,text/csv" style="height:auto;padding:6px"></label><button class="btn primary" type="submit">Import and match</button></div>
    <details style="margin-top:8px"><summary>Or paste the CSV</summary><textarea name="csv" rows="5" style="margin-top:6px" placeholder="Date,Description,Ref No,Withdrawal,Deposit&#10;31/10/2026,NEFT FEES UTR77,UTR77,,15000.00"></textarea></details>
</form>
@endcan
<div class="cols">
<section class="card"><div class="head"><h2>Statement lines</h2>@can('academic.accounts.post')<form method="post" action="{{ route('app.accounts.bank.auto') }}">@csrf<input type="hidden" name="bank" value="{{ $bank->id }}"><button class="btn sm" type="submit">Match automatically</button></form>@endcan</div>
    <div class="table-wrap"><table><thead><tr><th>Date</th><th>Description</th><th class="num">Amount</th><th>Matched to</th></tr></thead><tbody>
    @forelse ($lines as $s)<tr><td>{{ $s->date->format('j M') }}</td><td class="small">{{ $s->description }}<div class="muted">{{ $s->reference }}</div></td><td class="num">{{ $drcr($s->amount) }}</td>
        <td>@if ($s->entry)<a href="{{ route('app.accounts.vouchers.show', $s->entry->voucher_id) }}">{{ $s->entry->voucher->number }}</a>
                @can('academic.accounts.post')<form class="inline" method="post" action="{{ route('app.accounts.bank.unmatch', $s) }}">@csrf @method('delete')<button class="btn sm" type="submit">Unmatch</button></form>@endcan
            @else @php($cands = $open->filter(fn ($e) => ($e->debit - $e->credit) === $s->amount))
                @if ($cands->isNotEmpty())@can('academic.accounts.post')<form method="post" action="{{ route('app.accounts.bank.match', $s) }}" class="toolbar">@csrf<select name="entry_id" aria-label="Book entry">@foreach ($cands as $e)<option value="{{ $e->id }}">{{ $e->date->format('j M') }} · {{ $e->voucher->number }}</option>@endforeach</select><button class="btn sm" type="submit">Match</button></form>@endcan
                @else<span class="chip warning">not in books</span>@endif @endif</td></tr>
    @empty<tr><td colspan="4" class="empty">No statement lines imported yet.</td></tr>@endforelse</tbody></table></div></section>
<section class="card"><h2>Book entries not on the statement</h2><div class="table-wrap"><table><thead><tr><th>Date</th><th>Voucher</th><th class="num">Amount</th></tr></thead><tbody>
    @forelse ($open as $e)<tr><td>{{ $e->date->format('j M') }}</td><td><a href="{{ route('app.accounts.vouchers.show', $e->voucher_id) }}">{{ $e->voucher->number }}</a><div class="small muted">{{ \Illuminate\Support\Str::limit($e->voucher->narration, 40) }} {{ $e->voucher->reference }}</div></td><td class="num">{{ $drcr($e->debit - $e->credit) }}</td></tr>
    @empty<tr><td colspan="3" class="empty">Everything in the books is matched.</td></tr>@endforelse</tbody></table></div></section>
</div>
@endif
@endsection
