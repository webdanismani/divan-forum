@extends('layouts.app')

@section('title', __('Payment #:id', ['id' => $payment->id]))
@section('crumb', __('Payment #:id', ['id' => $payment->id]))

@section('content')
    <div class="page-head">
        <div class="titles">
            <h1>{{ $payment->title }} <span class="badge {{ $payment->stateColor() }}" style="vertical-align: 3px">{{ __($payment->stateLabel()) }}</span></h1>
            <div class="desc">{{ __('Payment #:id', ['id' => $payment->id]) }} · {{ forum_money($payment->amount) }}</div>
        </div>
        <a class="btn" href="{{ $target[1] }}">{{ $target[0] }}</a>
    </div>

    @if ($payment->status === 'pending' && request()->boolean('paid'))
        <div class="flash info">{{ __('Your payment is being processed. This page will show the result within a few seconds; refresh it if it does not change.') }}</div>
    @elseif ($payment->status === 'pending' && request()->boolean('failed'))
        <div class="flash error">{{ __('The payment could not be completed. You can try again.') }}</div>
    @elseif ($payment->status === 'paid')
        <div class="flash success">{{ __('The payment has been received. Thank you!') }}</div>
    @elseif ($payment->status === 'review')
        <div class="flash info">{{ $payment->paid_at ? __('The payment is being checked by an administrator.') : __('Your transfer notice has been received. It will be processed once the payment has been confirmed.') }}</div>
    @endif

    <div class="medium" style="margin-left: 0">
        @if ($payment->isOpen() && $mine)
            <section class="block" id="payment">
                <div class="block-head"><x-icon name="card" :size="16" /><h2>{{ __('Payment') }}</h2><span class="spacer"></span><b>{{ forum_money($payment->amount) }}</b></div>

                @if ($card && isset($card['token']))
                    <div class="block-body">
                        <iframe src="{{ \App\Forum\Payments\PayTr::FRAME_URL.$card['token'] }}" id="paytriframe" frameborder="0" scrolling="no" style="width: 100%; min-height: 520px" title="{{ __('Payment') }}"></iframe>
                    </div>
                    <script src="{{ \App\Forum\Payments\PayTr::RESIZER_URL }}"></script>
                    <script>iFrameResize({}, '#paytriframe');</script>
                @else
                    @if ($card && isset($card['error']))
                        <div class="block-body" style="padding-bottom: 0"><div class="flash error" style="margin: 0">{{ $card['error'] }}</div></div>
                    @endif

                    @if ($cardEnabled)
                        <div class="pay-option">
                            <span class="node-icon"><x-icon name="card" :size="19" /></span>
                            <div class="body">
                                <h3>{{ __('Pay by card') }}</h3>
                                <div class="muted">{{ __('Credit or debit card through PayTR secure payment.') }}</div>
                                <a class="btn btn-primary" style="margin-top: 10px" href="{{ route('payments.show', [$payment, 'pay' => 'card']) }}#payment">{{ __('Pay :amount by card', ['amount' => forum_money($payment->amount)]) }}</a>
                            </div>
                        </div>
                    @endif

                    @if ($transferInfo !== '' && $payment->purpose !== 'ad')
                        <div class="pay-option">
                            <span class="node-icon"><x-icon name="bank" :size="19" /></span>
                            <div class="body">
                                <h3>{{ __('Bank transfer / EFT') }}</h3>
                                <div class="muted">{{ __('Send :amount to the account below and write ":ref" in the description.', ['amount' => forum_money($payment->amount), 'ref' => __('Payment').' #'.$payment->id]) }}</div>
                                <div class="transfer-info">{{ $transferInfo }}</div>
                                <form method="post" action="{{ route('payments.transfer', $payment) }}">@csrf
                                    <button class="btn" type="submit"><x-icon name="check" :size="16" /> {{ __('I have made the transfer') }}</button>
                                </form>
                            </div>
                        </div>
                    @endif

                    @if (! $cardEnabled && $transferInfo === '')
                        <div class="empty">{{ __('No payment method has been set up yet. Please contact the administrators.') }}</div>
                    @endif
                @endif
            </section>

            <form method="post" action="{{ route('payments.destroy', $payment) }}" style="margin-top: 14px" data-confirm="{{ __('Cancel this payment?') }}">
                @csrf @method('DELETE')
                <button class="btn btn-danger" type="submit"><x-icon name="x" :size="16" /> {{ __('Cancel the payment') }}</button>
            </form>
        @else
            <section class="block">
                <div class="block-body">
                    <dl class="kv">
                        <div><dt>{{ __('Amount') }}</dt><dd>{{ forum_money($payment->amount) }}</dd></div>
                        <div><dt>{{ __('Status') }}</dt><dd>{{ __($payment->stateLabel()) }}</dd></div>
                        @if ($payment->paid_at)<div><dt>{{ __('Paid') }}</dt><dd>{{ $payment->paid_at->translatedFormat('j M Y H:i') }}</dd></div>@endif
                    </dl>
                </div>
            </section>
        @endif
    </div>
@endsection
