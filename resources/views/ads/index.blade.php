@extends('layouts.app')

@section('title', __('Advertise'))
@section('crumb', __('Advertise'))

@section('content')
    <div class="page-head">
        <div class="titles">
            <h1>{{ __('Advertise') }}</h1>
            <div class="desc">{{ __('Choose an ad space and a period, upload your banner and pay online. Your ad goes live for the period you paid for.') }}</div>
        </div>
        @auth
            <a class="btn" href="{{ route('ads.mine') }}"><x-icon name="list" :size="16" /> {{ __('My ads') }}</a>
        @endauth
    </div>

    @if ($zones->isEmpty())
        <div class="block"><div class="empty">{{ __('No ad spaces are on sale at the moment.') }}</div></div>
    @else
        <form class="layout" method="post" action="{{ route('ads.store') }}" enctype="multipart/form-data" data-ad-form>
            @csrf
            <div>
                <section class="block">
                    <div class="block-head"><x-icon name="grid" :size="16" /><h2>1. {{ __('Choose an ad space') }}</h2></div>
                    @foreach ($zones as $zone)
                        @php
                            $free = $availability[$zone->id]['free'];
                            $next = $availability[$zone->id]['next'];
                            $prices = $zone->priceList();
                        @endphp
                        <label class="zone-option">
                            <input type="radio" name="zone_id" value="{{ $zone->id }}" data-zone="{{ $zone->position }}" data-title="{{ $zone->title }}" data-size="{{ $zone->size() }}" data-kind="{{ $zone->kind }}" @checked($selected?->id === $zone->id && $free > 0) @disabled($free < 1) required>
                            <span>
                                <span class="name">{{ $zone->title }}</span>
                                <span class="desc">{{ $zone->description }}</span>
                            </span>
                            <span class="muted nowrap">{{ $zone->size() }}{{ $zone->isText() ? '' : ' px' }}</span>
                            <span>
                                @if ($free > 0)
                                    <span class="badge green">{{ __('Space available') }}@if ($zone->slots > 1) · {{ $free }}/{{ $zone->slots }}@endif</span>
                                @else
                                    <span class="badge red">{{ __('Full') }}</span>
                                    @if ($next)<span class="desc">{{ __('Free again on :date', ['date' => $next->translatedFormat('j M Y')]) }}</span>@endif
                                @endif
                            </span>
                            <span class="price">{{ forum_money(min($prices)) }}<small>{{ __(':days days and up', ['days' => array_key_first($prices)]) }}</small></span>
                        </label>
                    @endforeach
                    @error('zone_id')<div class="block-foot"><span class="field"><span class="error">{{ $message }}</span></span></div>@enderror
                </section>

                <section class="block">
                    <div class="block-head"><x-icon name="clock" :size="16" /><h2>2. {{ __('Choose a period') }}</h2></div>
                    <div class="block-body">
                        @foreach ($zones as $zone)
                            <div class="period-list" data-periods="{{ $zone->id }}" @if ($selected?->id !== $zone->id) hidden @endif>
                                @foreach ($zone->priceList() as $days => $price)
                                    <label class="period">
                                        <input type="radio" name="days" value="{{ $days }}" data-price="{{ forum_money($price) }}" data-label="{{ __(':days days', ['days' => $days]) }}"
                                            @checked($selected?->id === $zone->id && ((int) old('days') === $days || (! old('days') && $loop->first))) @disabled($selected?->id !== $zone->id)>
                                        <span class="days">{{ __(':days days', ['days' => $days]) }}</span>
                                        <span class="amount">{{ forum_money($price) }}</span>
                                        <span class="per-day">{{ __(':price per day', ['price' => forum_money(round($price / $days, 2))]) }}</span>
                                    </label>
                                @endforeach
                            </div>
                        @endforeach
                        @error('days')<div class="field" style="margin: 8px 0 0"><div class="error">{{ $message }}</div></div>@enderror
                    </div>
                </section>

                <section class="block">
                    <div class="block-head"><x-icon name="image" :size="16" /><h2>3. {{ __('Your ad') }}</h2></div>
                    <div class="block-body">
                        <div class="form-grid">
                            <div class="field">
                                <label for="title">{{ __('Ad title') }}</label>
                                <input class="input" id="title" name="title" value="{{ old('title') }}" maxlength="80" required>
                                <div class="hint">{{ __('Shown when visitors hover over the banner.') }}</div>
                                @error('title')<div class="error">{{ $message }}</div>@enderror
                            </div>
                            <div class="field">
                                <label for="target_url">{{ __('Link address') }}</label>
                                <input class="input" id="target_url" type="url" name="target_url" value="{{ old('target_url') }}" maxlength="255" placeholder="https://" required>
                                <div class="hint">{{ __('Where visitors go when they click the banner.') }}</div>
                                @error('target_url')<div class="error">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="field" data-banner-only @if ($selected?->isText()) hidden @endif>
                            <label for="image">{{ __('Banner image') }}</label>
                            <input class="input" id="image" type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp">
                            <div class="hint">{{ __('Size:') }} <b data-summary="size">{{ $selected?->size() }}</b> px · JPG, PNG, GIF, WebP · {{ __('1 MB at most') }}</div>
                            @error('image')<div class="error">{{ $message }}</div>@enderror
                        </div>
                        <div class="field">
                            <label for="coupon">{{ __('Coupon code') }} <span class="muted">({{ __('optional') }})</span></label>
                            <input class="input" id="coupon" name="coupon" value="{{ old('coupon') }}" maxlength="30" style="max-width: 240px; text-transform: uppercase" autocomplete="off">
                            <div class="hint">{{ __('The discount is applied when the order is created.') }}</div>
                            @error('coupon')<div class="error">{{ $message }}</div>@enderror
                        </div>
                        <div class="field" style="margin-bottom: 0">
                            <label for="note">{{ __('Note for the administrators') }} <span class="muted">({{ __('optional') }})</span></label>
                            <textarea class="input" id="note" name="note" maxlength="500" style="min-height: 70px">{{ old('note') }}</textarea>
                        </div>
                    </div>
                </section>

                <section class="block">
                    <div class="block-head"><x-icon name="user" :size="16" /><h2>4. {{ __('Billing details') }}</h2></div>
                    <div class="block-body">
                        <div class="form-grid">
                            <div class="field">
                                <label for="billing_name">{{ __('Full name / company') }}</label>
                                <input class="input" id="billing_name" name="billing_name" value="{{ old('billing_name') }}" maxlength="60" autocomplete="name" required>
                                @error('billing_name')<div class="error">{{ $message }}</div>@enderror
                            </div>
                            <div class="field">
                                <label for="billing_phone">{{ __('Phone') }}</label>
                                <input class="input" id="billing_phone" type="tel" name="billing_phone" value="{{ old('billing_phone') }}" maxlength="20" autocomplete="tel" required>
                                @error('billing_phone')<div class="error">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="field" style="margin-bottom: 0">
                            <label for="billing_address">{{ __('Address') }}</label>
                            <textarea class="input" id="billing_address" name="billing_address" maxlength="400" style="min-height: 60px" autocomplete="street-address" required>{{ old('billing_address') }}</textarea>
                            @error('billing_address')<div class="error">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </section>
            </div>

            <aside>
                <section class="block order-summary">
                    <div class="block-head"><x-icon name="card" :size="16" /><h2>{{ __('Order summary') }}</h2></div>
                    <div class="block-body">
                        <div class="zone-map" data-zone="{{ $selected?->position }}" data-summary-map aria-hidden="true">
                            <i class="m-head"></i><i class="m-top"></i>
                            <i class="m-main"></i><i class="m-side"></i>
                            <i class="m-thread"></i><i class="m-main2"></i>
                            <i class="m-bottom"></i>
                        </div>
                        <dl class="kv">
                            <div><dt>{{ __('Ad space') }}</dt><dd data-summary="title">{{ $selected?->title }}</dd></div>
                            <div><dt>{{ __('Banner size') }}</dt><dd><span data-summary="size">{{ $selected?->size() }}</span> px</dd></div>
                            <div><dt>{{ __('Period') }}</dt><dd data-summary="days">—</dd></div>
                            <div><dt>{{ __('Starts') }}</dt><dd>{{ __('When the payment is confirmed') }}</dd></div>
                        </dl>
                        <div class="order-total"><span class="muted">{{ __('Total') }}</span><b data-summary="price">—</b></div>

                        @auth
                            <button class="btn btn-primary btn-block" style="margin-top: 14px; height: 42px" type="submit">{{ __('Continue to payment') }} <x-icon name="arrow-right" :size="16" /></button>
                        @else
                            <a class="btn btn-primary btn-block" style="margin-top: 14px; height: 42px" href="{{ route('login') }}">{{ __('Log in to advertise') }}</a>
                        @endauth
                    </div>
                    <div class="block-foot small muted">
                        @if ($cardEnabled)<div><x-icon name="card" :size="14" /> {{ __('Credit or debit card (PayTR secure payment)') }}</div>@endif
                        @if ($transferEnabled)<div><x-icon name="bank" :size="14" /> {{ __('Bank transfer / EFT') }}</div>@endif
                        @if (! $cardEnabled && ! $transferEnabled)<div>{{ __('Payment is arranged with the administrators after you place the order.') }}</div>@endif
                    </div>
                </section>
            </aside>
        </form>
    @endif
@endsection
