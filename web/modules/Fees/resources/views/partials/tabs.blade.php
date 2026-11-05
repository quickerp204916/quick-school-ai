{{-- Fee module tabs --}}
<nav class="toolbar no-print" aria-label="Fees">
    @foreach (['app.fees.dashboard' => 'Overview', 'app.fees.students.index' => 'Students and collection', 'app.fees.invoices.index' => 'Invoices', 'app.fees.payments.index' => 'Receipts',
        'app.fees.approvals' => 'Approvals', 'app.fees.reports.outstanding' => 'Reports', 'app.fees.structures.index' => 'Structures', 'app.fees.setup' => 'Setup'] as $name => $label)
        @if (! in_array($name, ['app.fees.structures.index', 'app.fees.setup'], true) || auth()->user()->can('academic.fees.configure'))
            <a class="btn sm {{ request()->routeIs(str_replace('.index', '.*', $name)) || request()->routeIs($name) ? 'primary' : '' }}" href="{{ route($name) }}">{{ $label }}</a>
        @endif
    @endforeach
</nav>
