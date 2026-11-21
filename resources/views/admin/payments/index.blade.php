@extends('layouts.admin')

@section('title', __('Payments'))
@section('subtitle', __('Card payments and bank transfers for ads, balance top-ups and membership packages.'))

@section('content')
    @php
        $tabs = ['all' => __('All'), 'review' => __('Awaiting approval'), 'pending' => __('Awaiting payment'), 'paid' => __('Paid'), 'cancelled' => __('Cancelled')];
        $methods = ['card' => __('Card'), 'transfer' => __('Bank transfer')];
    @endphp

    <div class="stat-grid">
        <div class="block stat-card"><div class="num">{{ forum_money($income) }}</div><div class="muted">{{ __('Income received') }}</div></div>
        <div class="block stat-card"><div class="num">{{ forum_number($counts['review'] ?? 0) }}</div><div class="muted">{{ __('Awaiting approval') }}</div></div>
        <div class="block stat-card"><div class="num">{{ forum_number($counts['paid'] ?? 0) }}</div><div class="muted">{{ __('Paid') }}</div></div>
        <div class="block stat-card"><div class="num">{{ forum_money($balances) }}</div><div class="muted">{{ __('Total member balance') }}</div></div>
    </div>

    <nav class="tabs">
        @foreach ($tabs as $key => $label)
            <a href="{{ route('admin.payments.index', $key === 'all' ? [] : ['status' => $key]) }}" @class(['active' => $filter === $key])>{{ $label }}@if ($key !== 'all') ({{ $counts[$key] ?? 0 }})@endif</a>
        @endforeach
    </nav>

    <section class="block">
        @if ($payments->isEmpty())
            <div class="empty">{{ __('There are no payments here.') }}</div>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>#</th><th>{{ __('Member') }}</th><th>{{ __('Payment') }}</th><th>{{ __('Amount') }}</th><th>{{ __('Method') }}</th><th>{{ __('Status') }}</th><th>{{ __('Date') }}</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($payments as $payment)
                            <tr>
                                <td>{{ $payment->id }}</td>
                                <td><x-username :user="$payment->user" /></td>
                                <td>{{ $payment->title }}<div class="muted small">{{ __(\App\Models\Payment::PURPOSES[$payment->purpose] ?? $payment->purpose) }}@if ($payment->reference) · {{ $payment->reference }}@endif</div></td>
                                <td class="nowrap">{{ forum_money($payment->amount) }}@if ($payment->paid_amount !== null && abs($payment->paid_amount - $payment->amount) > 0.001)<div class="muted small">{{ __('received') }}: {{ forum_money($payment->paid_amount) }}</div>@endif</td>
                                <td>{{ $methods[$payment->method] ?? '—' }}</td>
                                <td><span class="badge {{ $payment->stateColor() }}">{{ __($payment->stateLabel()) }}</span></td>
                                <td class="nowrap">{{ $payment->created_at->translatedFormat('j M Y H:i') }}</td>
                                <td class="actions">
                                    @if (in_array($payment->status, ['pending', 'review'], true))
                                        <form style="display: inline" method="post" action="{{ route('admin.payments.action', $payment) }}">@csrf
                                            <button class="btn btn-sm btn-primary" name="action" value="approve" data-confirm="{{ __('Mark this payment as received and deliver what was bought?') }}"><x-icon name="check" :size="14" /> {{ __('Approve') }}</button>
                                            <button class="btn btn-sm btn-danger" name="action" value="reject" data-confirm="{{ __('Cancel this payment?') }}"><x-icon name="x" :size="14" /></button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{ $payments->links() }}
@endsection
