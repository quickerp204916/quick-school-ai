@extends('academic::layout')
@section('title', 'Admission register')
@section('content')
<div class="head"><div><h1>Admission register</h1><p class="muted" style="margin:4px 0 0">{{ $session->name }} · {{ $rows->count() }} admissions</p></div>
    <a class="btn" href="{{ request()->fullUrlWithQuery(['format' => 'csv']) }}">Download CSV</a></div>
@include('academic::partials.session-filter')
<section class="card"><div class="table-wrap"><table><thead><tr><th>Admission no</th><th>Name</th><th>Gender</th><th>Date of birth</th><th>Admitted</th><th>{{ $t('level') }}</th><th>Father</th><th>Mother</th><th>Phone</th><th>Status</th></tr></thead><tbody>
@forelse ($rows as $s)
    <tr><td>{{ $s->admission_no }}</td><td><a href="{{ route('app.students.show', $s) }}">{{ $s->fullName() }}</a></td><td>{{ ucfirst($s->gender) }}</td><td>{{ $s->date_of_birth->format('d/m/Y') }}</td>
        <td>{{ $s->admitted_on->format('d/m/Y') }}</td><td>{{ $s->enrollments->last()?->section?->label() }}</td><td>{{ $s->guardianBy('father')?->name }}</td><td>{{ $s->guardianBy('mother')?->name }}</td>
        <td>{{ $s->primaryGuardian()?->phone }}</td><td><span class="chip {{ $s->status }}">{{ $s->status }}</span></td></tr>
@empty<tr><td colspan="10" class="empty">No admissions in this session.</td></tr>@endforelse
</tbody></table></div></section>
@endsection
