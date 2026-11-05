@component('academic::partials.guest', ['title' => 'No access', 'tenant' => \Modules\Academic\Support\Institution::tenant()])
    <h1>No access</h1><p>{{ $message }}</p><a class="btn" href="{{ route('app.login') }}">Sign in with another account</a>
@endcomponent
