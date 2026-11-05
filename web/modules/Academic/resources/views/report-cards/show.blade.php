@extends('academic::layout')
@section('title', $card->title.' · '.$card->student->fullName())
@section('content')
@php($s = $card->student)
<div class="toolbar no-print"><button class="btn primary" type="button" onclick="print()">Print or save as PDF</button>
    <a class="btn" href="{{ route('app.students.show', $s) }}">Student record</a></div>
<article class="doc">
    <header><h1>{{ $tenant->name }}</h1>
        <div class="small">{{ $profile->address }} {{ $profile->affiliation_no ? '· Affiliation no '.$profile->affiliation_no : '' }} {{ $profile->udise_code ? '· UDISE '.$profile->udise_code : '' }}</div>
        <h2 style="margin-top:10px">{{ $card->title }}</h2></header>
    <dl class="kv" style="grid-template-columns:150px 1fr 150px 1fr">
        <dt>Student</dt><dd><b>{{ $s->fullName() }}</b></dd><dt>Admission no</dt><dd>{{ $s->admission_no }}</dd>
        <dt>{{ $t('section') }}</dt><dd>{{ $card->section->label() }}</dd><dt>Session</dt><dd>{{ $card->session->name }}</dd>
        <dt>Date of birth</dt><dd>{{ $s->date_of_birth->format('j M Y') }}</dd><dt>Parent</dt><dd>{{ $s->primaryGuardian()?->name }}</dd>
    </dl>
    <div class="table-wrap" style="margin-top:16px"><table><thead><tr><th>{{ $t('subject') }}</th>
        @foreach ($card->exams as $e)<th class="num">{{ $e['name'] }} <span class="small">({{ $e['weight'] + 0 }}%)</span></th>@endforeach<th class="num">Overall %</th><th>Grade</th></tr></thead><tbody>
    @foreach ($card->data['subjects'] as $sub)
        <tr><td>{{ $sub['name'] }}{{ $sub['credits'] ? ' ('.$sub['credits'].' cr)' : '' }}</td>
            @foreach ($card->exams as $e)@php($cell = collect($sub['exams'])->firstWhere('exam_id', $e['id']))
                <td class="num">{{ $cell ? ($cell['absent'] ? 'AB' : ($cell['marks'] !== null ? ($cell['marks'] + 0).' / '.($cell['max'] + 0) : '—')) : '' }}</td>@endforeach
            <td class="num" @if ($sub['passed'] === false) style="color:var(--bad);font-weight:600" @endif>{{ $sub['type'] === 'co_scholastic' ? '' : $sub['percent'] }}</td><td>{{ $sub['grade'] }}</td></tr>
    @endforeach</tbody></table></div>
    <dl class="kv" style="margin-top:16px;grid-template-columns:150px 1fr 150px 1fr">
        <dt>Overall</dt><dd><b>{{ $card->percentage }}%</b> · grade {{ $card->grade }}{{ $card->gpa !== null ? ' · GPA '.$card->gpa : '' }}</dd>
        <dt>Result</dt><dd><b>{{ strtoupper($card->result) }}</b>{{ $card->rank ? ' · rank '.$card->rank : '' }}</dd>
        <dt>Attendance</dt><dd>{{ $card->attendance_percent !== null ? $card->attendance_percent.'%' : '—' }} @if ($card->data['attendance']['total'] ?? null)({{ $card->data['attendance']['counts']['absent'] }} absent of {{ $card->data['attendance']['total'] }})@endif</dd>
        @if ($card->data['failed_subjects'])<dt>Needs improvement</dt><dd>{{ implode(', ', $card->data['failed_subjects']) }}</dd>@endif
    </dl>
    @if ($card->remarks)<p style="margin-top:16px"><b>Remarks:</b> {{ $card->remarks }}</p>@endif
    <div class="sign"><span>Class teacher</span><span>Parent</span><span>{{ $profile->principal_name ?: 'Principal' }}</span></div>
    <p class="small muted" style="margin-top:20px">Published {{ $card->published_at->format('j M Y') }}</p>
</article>
@endsection
