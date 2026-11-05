@component('academic::partials.guest', ['title' => 'Account suspended', 'tenant' => $tenant])
    <h1>Account suspended</h1>
    <p>{{ $tenant->name }}’s Quick School AI account is suspended, so nobody can sign in right now. Your data is safe.</p>
    <p class="small muted">School management: contact Quick ERP Technologies support to restore access.</p>
@endcomponent
