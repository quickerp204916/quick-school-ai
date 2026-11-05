@extends('academic::layout')
@section('title', 'Chart of accounts')
@section('content')
<div class="head"><div><h1>Chart of accounts</h1><p class="muted" style="margin:4px 0 0">Ledger groups and ledgers. Balances are as on today. Built-in ledgers receive automatic postings from fees, payroll and payment gateways.</p></div></div>
@include('accounting::partials.setup-tabs')
@php($byParent = $groups->groupBy(fn ($g) => $g->parent_id ?? 'root'))
@php($render = function ($g, $depth) use (&$render, $byParent, $balances, $money, $drcr) {
    $html = '<tr><td style="padding-left:'.(10 + $depth * 18).'px"><b>'.e($g->name).'</b> <span class="small muted">'.e($g->code).' · '.e(config('accounting.natures.'.$g->nature)).'</span></td><td></td><td></td><td></td></tr>';
    foreach ($g->ledgers as $l) {
        $html .= '<tr><td style="padding-left:'.(28 + $depth * 18).'px"><a href="'.route('app.accounts.reports.ledger', ['ledger' => $l->id]).'">'.e($l->name).'</a> <span class="small muted">'.e($l->code).'</span>'
            .($l->system_key ? ' <span class="chip">built-in</span>' : '').($l->is_active ? '' : ' <span class="chip closed">inactive</span>').'</td><td>'.e(config('accounting.ledger_types.'.$l->type)).'</td>'
            .'<td class="num">'.e($drcr($balances[$l->id] ?? 0)).'</td><td>'.(auth()->user()->can('academic.accounts.configure') ? '<a class="btn sm" href="'.route('app.accounts.ledgers.edit', $l).'">Edit</a>' : '').'</td></tr>';
    }
    foreach ($byParent[$g->id] ?? [] as $c) { $html .= $render($c, $depth + 1); }
    return $html;
})
<section class="card"><div class="table-wrap"><table><thead><tr><th>Group or ledger</th><th>Type</th><th class="num">Balance</th><th></th></tr></thead><tbody>
    @foreach ($byParent['root'] ?? [] as $g){!! $render($g, 0) !!}@endforeach
</tbody></table></div></section>
@can('academic.accounts.configure')
<div class="cols">
<form class="card" method="post" action="{{ route('app.accounts.ledgers.store') }}">@csrf
    <h2>New ledger</h2>
    @include('accounting::chart.ledger-fields', ['l' => new \Modules\Accounting\Models\Ledger(['type' => 'general', 'is_active' => true])])
    <p><button class="btn primary" type="submit">Add ledger</button></p>
</form>
<form class="card" method="post" action="{{ route('app.accounts.groups.store') }}">@csrf
    <h2>New group</h2>
    <div class="form">
        <label class="field">Code<input type="text" name="code" required maxlength="20" placeholder="X21"></label>
        <label class="field">Name<input type="text" name="name" required maxlength="120" placeholder="Hostel expenses"></label>
        <label class="field">Under<select name="parent_id" required>@foreach ($groups as $g)<option value="{{ $g->id }}">{{ $g->code }} · {{ $g->name }}</option>@endforeach</select></label>
        <label class="field">Cash flow <span class="hint">default: from the parent</span><select name="cash_flow"><option value="">Same as parent</option><option value="operating">Operating</option><option value="investing">Investing</option><option value="financing">Financing</option></select></label>
    </div>
    <p class="small muted">A new group takes its nature (asset, liability, income…) from the group it sits under.</p>
    <p><button class="btn" type="submit">Add group</button></p>
</form>
</div>
@endcan
@endsection
