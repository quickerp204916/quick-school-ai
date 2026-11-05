@extends('platform::layout')
@section('title', 'Staff and API tokens')
@section('content')
<div class="head"><h1>Staff and API tokens</h1></div>

@if (session('plain_token'))
    <section class="card" style="border-color:var(--teal-500)"><h2>New API token</h2><p class="small">Copy it now. Only a hash is stored, so it cannot be shown again.</p><div class="secret">{{ session('plain_token') }}</div></section>
@endif

<section class="card"><h2>Console staff</h2><div class="table-wrap"><table>
    <thead><tr><th>Name</th><th>Role</th><th>Two-factor</th><th>Last sign-in</th><th>Status</th><th></th></tr></thead><tbody>
    @foreach ($admins as $a)
        <tr><td><b>{{ $a->name }}</b><div class="small muted">{{ $a->email }}</div></td><td>{{ $a->roleLabel() }}</td>
            <td>{{ $a->mfa_confirmed_at ? 'On' : 'Not set up yet' }}</td><td class="small">{{ $a->last_login_at?->format('j M Y, H:i') ?? 'Never' }}</td>
            <td><span class="chip {{ $a->is_active ? 'active' : 'suspended' }}">{{ $a->is_active ? 'active' : 'disabled' }}</span></td>
            <td style="white-space:nowrap">
                <form class="inline" method="post" action="{{ route('platform.admins.toggle', $a) }}">@csrf<button class="btn sm" type="submit">{{ $a->is_active ? 'Disable' : 'Enable' }}</button></form>
                @if ($a->mfa_confirmed_at)<form class="inline" method="post" action="{{ route('platform.admins.reset-mfa', $a) }}">@csrf<button class="btn sm" type="submit">Reset two-factor</button></form>@endif
            </td></tr>
    @endforeach
    </tbody></table></div>
    <form method="post" action="{{ route('platform.admins.store') }}" class="filters" style="margin-top:14px">@csrf
        <label class="field">Name<input type="text" name="name" required maxlength="150"></label>
        <label class="field">Email<input type="email" name="email" required maxlength="191"></label>
        <label class="field">Role<select name="role">@foreach ($roles as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></label>
        <label class="field">Temporary password<span class="hint">12+ characters, upper, lower and a number</span><input type="password" name="password" required minlength="12" autocomplete="new-password"></label>
        <button class="btn primary" type="submit">Add staff</button>
    </form>
    <p class="small muted">Super admin: everything. Billing: plans, invoices, subscriptions and credits. Support: support access and announcements. Everyone can view institutions and the audit log.</p>
</section>

<section class="card"><h2>API tokens</h2><div class="table-wrap"><table>
    <thead><tr><th>Name</th><th>Owner</th><th>Permissions</th><th>Last used</th><th>Expires</th><th></th></tr></thead><tbody>
    @forelse ($tokens as $t)
        <tr><td>{{ $t->name }}</td><td>{{ $t->admin->name }}</td><td>{{ implode(', ', $t->abilities) }}</td><td class="small">{{ $t->last_used_at?->format('j M, H:i') ?? 'Never' }}</td>
            <td class="small">{{ $t->revoked_at ? 'Revoked' : ($t->expires_at?->format('j M Y') ?? 'Never') }}</td>
            <td>@unless ($t->revoked_at)<form class="inline" method="post" action="{{ route('platform.tokens.revoke', $t) }}">@csrf<button class="btn sm danger" type="submit">Revoke</button></form>@endunless</td></tr>
    @empty <tr><td colspan="6" class="muted">No tokens.</td></tr> @endforelse
    </tbody></table></div>
    <form method="post" action="{{ route('platform.tokens.store') }}" class="filters" style="margin-top:14px">@csrf
        <label class="field grow">Token name<input type="text" name="name" required maxlength="100" placeholder="Website signup integration"></label>
        <label class="check"><input type="checkbox" name="abilities[]" value="read" checked> Read</label>
        <label class="check"><input type="checkbox" name="abilities[]" value="write"> Write</label>
        <label class="field">Expires in days<input type="number" name="expires_in_days" min="1" max="365" value="90"></label>
        <button class="btn" type="submit">Create token</button>
    </form>
</section>
@endsection
