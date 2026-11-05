{{-- One group with its ledgers and child groups. $node, $depth --}}
<tr><td style="padding-left:{{ 10 + $depth * 18 }}px"><b>{{ $node['group']->name }}</b></td><td class="num"><b>{{ $money($node['total']) }}</b></td></tr>
@foreach ($node['ledgers'] as $l)
    <tr><td style="padding-left:{{ 28 + $depth * 18 }}px"><a href="{{ route('app.accounts.reports.ledger', ['ledger' => $l['ledger']->id] + request()->only('from', 'to', 'branch')) }}">{{ $l['ledger']->name }}</a></td><td class="num">{{ $money($l['amount']) }}</td></tr>
@endforeach
@foreach ($node['children'] as $child)
    @include('accounting::partials.group-tree', ['node' => $child, 'depth' => $depth + 1])
@endforeach
