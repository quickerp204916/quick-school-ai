@php($chip = ['paid' => 'active', 'partial' => 'offered', 'open' => 'enquiry', 'cancelled' => 'rejected', 'draft' => '', 'success' => 'active', 'bounced' => 'rejected',
    'requested' => 'offered', 'approved' => 'enquiry', 'processed' => 'active', 'rejected' => 'rejected', 'failed' => 'past_due', 'pending' => 'offered'][$s] ?? '')
<span class="chip {{ $chip }}">{{ $s === 'draft' ? 'scheduled' : str_replace('_', ' ', $s) }}</span>@if (($overdue ?? false))<span class="chip past_due" style="margin-left:4px">overdue</span>@endif
