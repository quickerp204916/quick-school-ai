@extends('academic::layout')
@section('title', strtoupper($report).' · '.$run->label())
@section('content')
<div class="head"><h1>{{ ['pf' => 'PF (ECR)', 'esi' => 'ESI', 'pt' => 'Professional tax', 'tds' => 'TDS on salary'][$report] }} · {{ $run->label() }}</h1><div class="toolbar"><a class="btn" href="{{ request()->fullUrlWithQuery(['format' => 'csv']) }}">Download CSV</a><a class="btn" href="{{ route('app.hr.payroll.show', $run) }}">Back</a></div></div>
@if ($report === 'pf')<p class="small muted">Amounts in rupees, in the order of the EPFO ECR file. Check against the ECR template before uploading.</p>@endif
<section class="card"><div class="table-wrap"><table><thead><tr>@foreach (array_keys($rows->first() ?? []) as $h)<th>{{ str_replace('_', ' ', $h) }}</th>@endforeach</tr></thead><tbody>
@forelse ($rows as $r)<tr>@foreach ($r as $k => $v)<td class="{{ is_numeric($v) ? 'num' : '' }}">{{ in_array($report, ['esi', 'pt', 'tds'], true) && in_array($k, ['wages', 'ee', 'er', 'amount', 'tds'], true) ? $money((int) $v) : $v }}</td>@endforeach</tr>
@empty<tr><td class="empty">Nothing for this month.</td></tr>@endforelse</tbody></table></div></section>
@endsection
