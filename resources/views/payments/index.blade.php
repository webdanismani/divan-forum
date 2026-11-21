@extends('layouts.app')

@section('title', __('My payments'))
@section('crumb', __('My payments'))

@section('content')
    @php
        $states = ['pending' => [__('Awaiting payment'), 'amber'], 'review' => [__('Awaiting approval'), 'amber'], 'paid' => [__('Paid'), 'green'], 'cancelled' => [__('Cancelled'), ''], 'failed' => [__('Failed'), 'red']];
    @endphp
    <div class="page-head">
        <div class="titles"><h1>{{ __('My payments') }}</h1><div class="desc">{{ __('Everything you have paid for, with a printable receipt for each payment.') }}</div></div>
        <div class="row">
            @if (setting('wallet.enabled'))<a class="btn" href="{{ route('wallet.index') }}"><x-icon name="wallet" :size="16" /> {{ __('My balance') }}</a>@endif
            @if (setting('ads.enabled'))<a class="btn" href="{{ route('ads.mine') }}"><x-icon name="megaphone" :size="16" /> {{ __('My ads') }}</a>@endif
        </div>
    </div>

    <section class="block">
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>#</th><th>{{ __('Date') }}</th><th>{{ __('Description') }}</th><th>{{ __('Amount') }}</th><th>{{ __('State') }}</th><th></th></tr></thead>
                <tbody>
                    @forelse ($payments as $payment)
                        @php [$label, $color] = $states[$payment->status] ?? [$payment->status, '']; @endphp
                        <tr>
                            <td class="muted">P-{{ $payment->id }}</td>
                            <td class="muted">{{ ($payment->paid_at ?? $payment->created_at)?->format('d.m.Y H:i') }}</td>
                            <td><a href="{{ route('payments.show', $payment) }}">{{ $payment->title }}</a></td>
                            <td><strong>{{ forum_money($payment->paid_amount ?? $payment->amount) }}</strong></td>
                            <td><span class="badge {{ $color }}">{{ $label }}</span></td>
                            <td class="actions">
                                @if ($payment->status === 'paid')
                                    <a class="btn btn-sm" href="{{ route('payments.receipt', $payment) }}" target="_blank" rel="noopener"><x-icon name="list" :size="14" /> {{ __('Receipt') }}</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><div class="empty">{{ __('You have not made any payments yet.') }}</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{ $payments->links() }}
@endsection
