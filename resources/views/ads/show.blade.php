@extends('layouts.app')

@section('title', __('Ad order #:id', ['id' => $ad->id]))
@section('crumb', __('Ad order #:id', ['id' => $ad->id]))

@section('content')
    @php
        $state = $ad->state();
        $mine = $ad->user_id === auth()->id();
    @endphp

    <div class="page-head">
        <div class="titles">
            <h1>{{ $ad->title }} <span class="badge {{ $ad->stateColor() }}" style="vertical-align: 3px">{{ __($ad->stateLabel()) }}</span></h1>
            <div class="desc">{{ __('Ad order #:id', ['id' => $ad->id]) }} · {{ $ad->zone->title }} · {{ __(':days days', ['days' => $ad->days]) }}</div>
        </div>
        <a class="btn" href="{{ route('ads.mine') }}"><x-icon name="list" :size="16" /> {{ __('My ads') }}</a>
    </div>

    @if ($state === 'unpaid' && request()->boolean('paid'))
        <div class="flash info">{{ __('Your payment is being processed. This page will show the result within a few seconds; refresh it if it does not change.') }}</div>
    @elseif ($state === 'unpaid' && request()->boolean('failed'))
        <div class="flash error">{{ __('The payment could not be completed. You can try again.') }}</div>
    @endif

    <div class="layout">
        <div>
            @if ($state === 'unpaid' && $mine)
                <section class="block" id="payment">
                    <div class="block-head"><x-icon name="card" :size="16" /><h2>{{ __('Payment') }}</h2><span class="spacer"></span><b>{{ forum_money($ad->price) }}</b></div>

                    @error('balance')<div class="block-body" style="padding-bottom: 0"><div class="flash error" style="margin: 0">{{ $message }} <a href="{{ route('wallet.index') }}">{{ __('Top up') }}</a></div></div>@enderror

                    @if ($balance !== null)
                        <div class="pay-option">
                            <span class="node-icon"><x-icon name="wallet" :size="19" /></span>
                            <div class="body">
                                <h3>{{ __('Pay from your balance') }}</h3>
                                <div class="muted">{{ __('Your balance') }}: <b>{{ forum_money($balance) }}</b>. {{ __('Your ad is published as soon as the payment succeeds.') }}</div>
                                <form method="post" action="{{ route('ads.pay', $ad) }}" style="margin-top: 10px">@csrf
                                    @if ($balance + 0.001 >= $ad->price)
                                        <button class="btn btn-primary" name="method" value="balance">{{ __('Pay :amount from balance', ['amount' => forum_money($ad->price)]) }}</button>
                                    @else
                                        <a class="btn" href="{{ route('wallet.index') }}">{{ __('Top up') }}</a>
                                    @endif
                                </form>
                            </div>
                        </div>
                    @endif

                    @if ($cardEnabled)
                        <div class="pay-option">
                            <span class="node-icon"><x-icon name="card" :size="19" /></span>
                            <div class="body">
                                <h3>{{ __('Pay by card') }}</h3>
                                <div class="muted">{{ __('Credit or debit card through PayTR secure payment. Your ad is published as soon as the payment succeeds.') }}</div>
                                <form method="post" action="{{ route('ads.pay', $ad) }}" style="margin-top: 10px">@csrf
                                    <button class="btn btn-primary" name="method" value="card">{{ __('Pay :amount by card', ['amount' => forum_money($ad->price)]) }}</button>
                                </form>
                            </div>
                        </div>
                    @endif

                    @if (filled($transferInfo))
                        <div class="pay-option">
                            <span class="node-icon"><x-icon name="bank" :size="19" /></span>
                            <div class="body">
                                <h3>{{ __('Bank transfer / EFT') }}</h3>
                                <div class="muted">{{ __('Send :amount to the account below and write ":ref" in the description. Your ad is published after the transfer has been confirmed.', ['amount' => forum_money($ad->price), 'ref' => __('Ad').' #'.$ad->id]) }}</div>
                                <div class="transfer-info">{{ $transferInfo }}</div>
                                <form method="post" action="{{ route('ads.transfer', $ad) }}">@csrf
                                    <button class="btn" type="submit"><x-icon name="check" :size="16" /> {{ __('I have made the transfer') }}</button>
                                </form>
                            </div>
                        </div>
                    @endif

                    @if (! $cardEnabled && blank($transferInfo) && $balance === null)
                        <div class="empty">{{ __('No payment method has been set up yet. Please contact the administrators.') }}</div>
                    @endif
                </section>
            @elseif ($state === 'review')
                <div class="flash info">
                    @if ($ad->paid_at)
                        {{ __('Your payment has been received. The ad will be published once an administrator has approved it.') }}
                    @elseif ($ad->payment_method === 'transfer')
                        {{ __('Your transfer notice has been received. The ad will be published once the payment has been confirmed.') }}
                    @else
                        {{ __('Your order is waiting for an administrator.') }}
                    @endif
                </div>
            @elseif ($state === 'rejected')
                <div class="flash error">{{ __('This ad was not accepted.') }}@if ($ad->admin_note) {{ $ad->admin_note }}@endif</div>
            @endif

            <section class="block">
                <div class="block-head"><x-icon name="image" :size="16" /><h2>{{ __('Banner') }}</h2></div>
                <div class="block-body">
                    @if ($ad->imageUrl())
                        <img class="banner-preview" src="{{ $ad->imageUrl() }}" alt="{{ $ad->title }}" style="width: {{ $ad->zone->width }}px">
                    @else
                        <strong>{{ $ad->title }}</strong>
                    @endif
                    <div class="muted small" style="margin-top: 8px; overflow-wrap: anywhere"><x-icon name="link" :size="13" /> {{ $ad->target_url }}</div>
                </div>
            </section>

            @if ($mine && $state === 'active' && $ad->zone->priceList())
                <section class="block" id="renew">
                    <div class="block-head"><x-icon name="refresh" :size="16" /><h2>{{ __('Renew this ad') }}</h2></div>
                    <div class="block-body">
                        <p class="muted" style="margin-top: 0">{{ __('The new period starts when the current one ends (:date), so your ad keeps running without a gap.', ['date' => $ad->ends_at?->translatedFormat('j M Y')]) }}@if ($balance !== null) {{ __('If your balance covers the price, it is paid straight away.') }}@endif</p>
                        <div class="row">
                            @foreach ($ad->zone->priceList() as $days => $price)
                                <form method="post" action="{{ route('ads.renew', $ad) }}" data-confirm="{{ __('Renew for :days days (:price)?', ['days' => $days, 'price' => forum_money($price)]) }}">@csrf
                                    <input type="hidden" name="days" value="{{ $days }}">
                                    <button class="btn" type="submit">{{ __(':days days', ['days' => $days]) }} · <b>{{ forum_money($price) }}</b></button>
                                </form>
                            @endforeach
                        </div>
                        @error('days')<div class="error" style="margin-top: 6px">{{ $message }}</div>@enderror
                    </div>
                </section>
            @endif

            @if ($ad->paid_at)
                <a class="btn" href="{{ route('ads.receipt', $ad) }}" target="_blank" rel="noopener" style="margin-top: 14px"><x-icon name="list" :size="16" /> {{ __('Receipt') }}</a>
            @endif

            @if ($mine && in_array($ad->status, ['unpaid', 'rejected'], true) && ! $ad->paid_at)
                <form method="post" action="{{ route('ads.destroy', $ad) }}" style="margin-top: 14px" data-confirm="{{ __('Cancel this order?') }}">
                    @csrf @method('DELETE')
                    <button class="btn btn-danger" type="submit"><x-icon name="trash" :size="16" /> {{ __('Cancel the order') }}</button>
                </form>
            @endif
        </div>

        <aside>
            <section class="block">
                <div class="block-head"><h2>{{ __('Order details') }}</h2></div>
                <div class="block-body">
                    <dl class="kv">
                        <div><dt>{{ __('Ad space') }}</dt><dd>{{ $ad->zone->title }}</dd></div>
                        <div><dt>{{ __('Banner size') }}</dt><dd>{{ $ad->zone->size() }}{{ $ad->zone->isText() ? '' : ' px' }}</dd></div>
                        <div><dt>{{ __('Period') }}</dt><dd>{{ __(':days days', ['days' => $ad->days]) }}</dd></div>
                        @if ($ad->discount > 0)<div><dt>{{ __('Discount') }}</dt><dd>−{{ forum_money($ad->discount) }} <span class="muted">({{ $ad->coupon_code }})</span></dd></div>@endif
                        <div><dt>{{ __('Total') }}</dt><dd>{{ forum_money($ad->price) }}</dd></div>
                        <div><dt>{{ __('Payment') }}</dt><dd>
                            @if ($ad->paid_at)
                                {{ __(\App\Models\Ad::METHODS[$ad->payment_method] ?? 'Paid') }} · {{ $ad->paid_at->translatedFormat('j M Y') }}
                            @elseif ($ad->payment_method === 'manual')
                                —
                            @else
                                {{ __('Not paid yet') }}
                            @endif
                        </dd></div>
                        @if ($ad->starts_at)
                            <div><dt>{{ __('Starts') }}</dt><dd>{{ $ad->starts_at->translatedFormat('j M Y H:i') }}</dd></div>
                            <div><dt>{{ __('Ends') }}</dt><dd>{{ $ad->ends_at?->translatedFormat('j M Y H:i') }}</dd></div>
                        @endif
                    </dl>
                </div>
                @if ($ad->starts_at)
                    <div class="mini-stats">
                        <div><span class="stat-num">{{ forum_number($ad->impressions) }}</span><span class="stat-label">{{ __('Impressions') }}</span></div>
                        <div><span class="stat-num">{{ forum_number($ad->clicks) }}</span><span class="stat-label">{{ __('Clicks') }}</span></div>
                        <div><span class="stat-num">{{ $ad->impressions ? number_format($ad->clicks / $ad->impressions * 100, 1, ',', '.') : '0' }}%</span><span class="stat-label">{{ __('Click rate') }}</span></div>
                    </div>
                @endif
            </section>
        </aside>
    </div>
@endsection
