@extends('academic::layout')
@section('title', 'Fee setup')
@section('content')
<div class="head"><h1>Fee setup</h1></div>
@include('fees::partials.tabs')
<section class="card"><h2>Fee heads</h2><p class="small muted">Each head posts to its own ledger. Optional heads (transport, hostel) are charged only to students who take them. Deposits go to a liability ledger and can be refunded.</p>
<div class="table-wrap"><table><thead><tr><th>Code</th><th>Name</th><th>Kind</th><th>Ledger</th><th>Active</th><th></th></tr></thead><tbody>
@foreach ($heads as $h)
    <tr><td>{{ $h->code }}</td><td>{{ $h->name }}</td><td>{{ \Modules\Fees\Models\FeeHead::KINDS[$h->kind] }}</td><td>{{ $h->ledger?->name }}</td><td>{{ $h->is_active ? 'Yes' : 'No' }}</td>
    <td><details><summary>Edit</summary><form method="post" action="{{ route('app.fees.heads.update', $h) }}" class="form">@csrf @method('put')
        @include('fees::partials.head-fields', ['h' => $h])<button class="btn sm primary" type="submit">Save</button></form></details></td></tr>
@endforeach
</tbody></table></div>
<details><summary>Add a fee head</summary><form method="post" action="{{ route('app.fees.heads.store') }}" class="form">@csrf @include('fees::partials.head-fields', ['h' => null])<button class="btn primary" type="submit">Add head</button></form></details>
</section>

<section class="card"><h2>Concessions and scholarships</h2><p class="small muted">Assigning a concession to a student needs approval by an owner or administrator, unless they assign it themselves.</p>
<div class="table-wrap"><table><thead><tr><th>Name</th><th>Category</th><th>Value</th><th>Heads</th><th>Active</th><th></th></tr></thead><tbody>
@forelse ($concessions as $c)
    <tr><td>{{ $c->name }}</td><td>{{ \Modules\Fees\Models\FeeConcession::CATEGORIES[$c->category] }}</td><td>{{ $c->type === 'percent' ? ($c->value / 100).'%' : $money($c->value).' per instalment' }}</td>
    <td>{{ $c->fee_head_ids ? $heads->whereIn('id', $c->fee_head_ids)->pluck('name')->join(', ') : 'All heads' }}</td><td>{{ $c->is_active ? 'Yes' : 'No' }}</td>
    <td><details><summary>Edit</summary><form method="post" action="{{ route('app.fees.concessions.update', $c) }}" class="form">@csrf @method('put') @include('fees::partials.concession-fields', ['c' => $c])<button class="btn sm primary" type="submit">Save</button></form></details></td></tr>
@empty<tr><td colspan="6" class="empty">No concessions yet.</td></tr>@endforelse
</tbody></table></div>
<details><summary>Add a concession</summary><form method="post" action="{{ route('app.fees.concessions.store') }}" class="form">@csrf @include('fees::partials.concession-fields', ['c' => null])<button class="btn primary" type="submit">Add concession</button></form></details>
</section>

@can('academic.fees.approve')
<section class="card"><h2>Online payments and reminders</h2>
<form method="post" action="{{ route('app.fees.settings') }}" class="form">@csrf @method('put')
    <fieldset class="field wide"><legend>Gateways parents can pay through</legend>
        <label class="check"><input type="checkbox" name="gateways[]" value="razorpay" @checked(in_array('razorpay', $settings->gateways ?? []))> Razorpay</label>
        <label class="check"><input type="checkbox" name="gateways[]" value="cashfree" @checked(in_array('cashfree', $settings->gateways ?? []))> Cashfree</label></fieldset>
    <label class="field">Razorpay key ID<input name="razorpay_key_id" value="{{ old('razorpay_key_id', $settings->razorpay_key_id) }}" autocomplete="off"></label>
    <label class="field">Razorpay key secret <span class="hint">{{ $settings->razorpay_key_secret ? 'saved; leave blank to keep' : 'not set' }}</span><input type="password" name="razorpay_key_secret" autocomplete="new-password"></label>
    <label class="field">Razorpay webhook secret <span class="hint">{{ $settings->razorpay_webhook_secret ? 'saved' : 'not set' }}</span><input type="password" name="razorpay_webhook_secret" autocomplete="new-password"></label>
    <label class="field">Cashfree app ID<input name="cashfree_app_id" value="{{ old('cashfree_app_id', $settings->cashfree_app_id) }}" autocomplete="off"></label>
    <label class="field">Cashfree secret key <span class="hint">{{ $settings->cashfree_secret_key ? 'saved; leave blank to keep' : 'not set' }}</span><input type="password" name="cashfree_secret_key" autocomplete="new-password"></label>
    <label class="field">Cashfree environment<select name="cashfree_env"><option value="sandbox" @selected($settings->cashfree_env !== 'production')>Sandbox (testing)</option><option value="production" @selected($settings->cashfree_env === 'production')>Production</option></select></label>
    <p class="small muted wide">Webhook URLs to enter in the gateway dashboards: <code>{{ \Modules\Fees\Support::webhookUrl('razorpay') }}</code> (events payment.captured, refund.processed) and <code>{{ \Modules\Fees\Support::webhookUrl('cashfree') }}</code>.</p>
    <label class="check wide"><input type="checkbox" name="reminders_enabled" value="1" @checked($settings->reminders_enabled)> Email fee reminders to parents</label>
    <label class="field">Days before the due date<input name="reminder_days_before" value="{{ implode(', ', $settings->reminder_days_before ?? []) }}" placeholder="7, 1"></label>
    <label class="field">Days after the due date<input name="reminder_days_after" value="{{ implode(', ', $settings->reminder_days_after ?? []) }}" placeholder="1, 7, 15"></label>
    <label class="field wide">Note printed on receipts<input name="receipt_note" value="{{ old('receipt_note', $settings->receipt_note) }}" maxlength="300" placeholder="Fees once paid are not refundable except caution money."></label>
    <div><button class="btn primary" type="submit">Save settings</button></div>
</form></section>
@endcan
@endsection
