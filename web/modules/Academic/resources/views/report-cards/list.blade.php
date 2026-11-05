@extends('academic::layout')
@section('title', $title)
@section('content')
<div class="head"><div><h1>{{ $title }}</h1><p class="muted" style="margin:4px 0 0">{{ $section->label() }} · {{ $section->session->name }} · {{ $cards->count() }} cards · {{ $cards->where('result', 'pass')->count() }} passed</p></div></div>
<section class="card"><div class="table-wrap"><table><thead><tr><th>Rank</th><th>Student</th><th class="num">%</th><th>Grade</th><th>Result</th><th></th></tr></thead><tbody>
@foreach ($cards as $c)
    <tr><td>{{ $c->rank ?? '—' }}</td><td>{{ $c->student->fullName() }}</td><td class="num">{{ $c->percentage }}</td><td>{{ $c->grade }}</td><td><span class="chip {{ $c->result }}">{{ $c->result }}</span></td>
        <td><a class="btn sm" href="{{ route('app.report-cards.show', $c) }}">Open</a></td></tr>
@endforeach</tbody></table></div></section>
@endsection
