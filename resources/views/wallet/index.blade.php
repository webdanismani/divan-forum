@extends('layouts.app')

@section('title', __('My balance'))
@section('crumb', __('My balance'))

@section('content')
    @php $labels = ['topup' => __('Top-up'), 'ad' => __('Ad'), 'promotion' => __('Thread promotion'), 'upgrade' => __('Membership package'), 'adjustment' => __('Adjustment')]; @endphp

    <div class="page-head">
        <div class="titles">
            <h1>{{ __('My balance') }}</h1>
            <div class="desc">{{ __('Top up once and use your balance for ads, thread promotion and membership packages.') }}</div>
        </div>
    </div>

    <div class="layout">
        <div>
            <section class="block">
                <div class="balance-box">
                    <span class="node-icon"><x-icon name="wallet" :size="20" /></span>
                    <div>
                        <div class="muted">{{ __('Current balance') }}</div>
                        <div class="amount">{{ forum_money($user->balance) }}</div>
                    </div>
                </div>
            </section>

            @if ($open->isNotEmpty())
                <section class="block">
                    <div class="block-head"><h2>{{ __('Open payments') }}</h2></div>
                    @foreach ($open as $payment)
                        <div class="item">
                            <div class="body">
                                <a class="title" href="{{ route('payments.show', $payment) }}">{{ $payment->title }}</a>
                                <div class="thread-meta"><span class="badge {{ $payment->stateColor() }}">{{ __($payment->stateLabel()) }}</span> <x-time :at="$payment->created_at" /></div>
                            </div>
                            <b>{{ forum_money($payment->amount) }}</b>
                        </div>
                    @endforeach
                </section>
            @endif

            <section class="block">
                <div class="block-head"><x-icon name="list" :size="16" /><h2>{{ __('Transactions') }}</h2></div>
                @if ($transactions->isEmpty())
                    <div class="empty">{{ __('No transactions yet.') }}</div>
                @else
                    <div class="table-wrap">
                        <table class="table">
                            <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Type') }}</th><th>{{ __('Description') }}</th><th>{{ __('Amount') }}</th><th>{{ __('Balance') }}</th></tr></thead>
                            <tbody>
                                @foreach ($transactions as $row)
                                    <tr>
                                        <td class="nowrap">{{ $row->created_at->translatedFormat('j M Y H:i') }}</td>
                                        <td>{{ $labels[$row->type] ?? __(ucfirst($row->type)) }}</td>
                                        <td>{{ $row->description }}</td>
                                        <td class="{{ $row->amount >= 0 ? 'amount-plus' : 'amount-minus' }}">{{ $row->amount >= 0 ? '+' : '−' }}{{ forum_money(abs($row->amount)) }}</td>
                                        <td class="nowrap">{{ forum_money($row->balance_after) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
            {{ $transactions->links() }}
        </div>

        <aside>
            <form class="block" method="post" action="{{ route('wallet.topup') }}">
                @csrf
                <div class="block-head"><x-icon name="plus" :size="16" /><h2>{{ __('Top up') }}</h2></div>
                @if (! $cardEnabled && ! $transferEnabled)
                    <div class="empty">{{ __('No payment method has been set up yet. Please contact the administrators.') }}</div>
                @else
                    <div class="block-body">
                        <div class="field">
                            <label for="amount">{{ __('Amount') }} ({{ config('forum.ads.currency_symbol') }})</label>
                            <input class="input" id="amount" type="number" name="amount" value="{{ old('amount', max($min, 100)) }}" min="{{ $min }}" max="{{ $max }}" step="1" required>
                            <div class="quick-amounts">
                                @foreach (array_filter([100, 250, 500, 1000], fn ($a) => $a >= $min && $a <= $max) as $quick)
                                    <button class="btn btn-sm" type="button" onclick="document.getElementById('amount').value = {{ $quick }}">{{ forum_money($quick) }}</button>
                                @endforeach
                            </div>
                            @error('amount')<div class="error">{{ $message }}</div>@enderror
                        </div>
                        <div class="field">
                            <span class="label">{{ __('Payment method') }}</span>
                            @if ($cardEnabled)<label class="check" style="margin-bottom: 6px"><input type="radio" name="method" value="card" checked> {{ __('Credit or debit card (PayTR secure payment)') }}</label>@endif
                            @if ($transferEnabled)<label class="check"><input type="radio" name="method" value="transfer" @checked(! $cardEnabled)> {{ __('Bank transfer / EFT') }}</label>@endif
                        </div>
                        <div class="field">
                            <label for="billing_name">{{ __('Full name / company') }}</label>
                            <input class="input" id="billing_name" name="billing_name" value="{{ old('billing_name', $user->billing_name) }}" maxlength="60" required>
                        </div>
                        <div class="field">
                            <label for="billing_phone">{{ __('Phone') }}</label>
                            <input class="input" id="billing_phone" type="tel" name="billing_phone" value="{{ old('billing_phone', $user->billing_phone) }}" maxlength="20" required>
                        </div>
                        <div class="field">
                            <label for="billing_address">{{ __('Address') }}</label>
                            <textarea class="input" id="billing_address" name="billing_address" maxlength="400" style="min-height: 60px" required>{{ old('billing_address', $user->billing_address) }}</textarea>
                        </div>
                        <button class="btn btn-primary btn-block" type="submit">{{ __('Continue to payment') }}</button>
                    </div>
                @endif
            </form>
        </aside>
    </div>
@endsection
