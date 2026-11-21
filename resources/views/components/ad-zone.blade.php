@props(['position'])
@php
    // Order and payment pages stay free of banners.
    $display = request()->routeIs('ads.*', 'login', 'register', 'password.*')
        ? null
        : app(\App\Forum\Ads::class)->display($position);
@endphp
@php
    // Add-ons may fill a space nobody has bought (their own ad codes).
    $fallback = ! request()->routeIs('ads.*', 'login', 'register', 'password.*') && (! $display || $display['ads']->isEmpty())
        ? trim(app(\App\Forum\Addons\Hooks::class)->render('ads.empty', ['position' => $position]))
        : '';
@endphp
@if ($fallback !== '')
    <div {{ $attributes->merge(['class' => 'ad-zone ad-code ad-'.$position]) }}>{!! $fallback !!}</div>
@elseif ($display)
    @php $zone = $display['zone']; @endphp
    @if ($zone->isText())
        <div {{ $attributes->merge(['class' => 'ad-links']) }}>
            <div class="container">
                <span class="muted">{{ $zone->title }}:</span>
                @foreach ($display['ads'] as $ad)
                    <a href="{{ route('ads.click', $ad) }}" target="_blank" rel="sponsored nofollow noopener">{{ $ad->title }}</a>
                @endforeach
                @if ($display['placeholders'] > 0)
                    <a class="ad-links-empty" href="{{ route('ads.index', ['zone' => $position]) }}">+ {{ __('Your link here') }}</a>
                @endif
            </div>
        </div>
    @else
    <div {{ $attributes->merge(['class' => 'ad-zone ad-'.$position]) }} style="--ad-w: {{ $zone->width }}px; --ad-ratio: {{ $zone->width }} / {{ $zone->height }}">
        @foreach ($display['ads'] as $ad)
            <a class="ad" href="{{ route('ads.click', $ad) }}" target="_blank" rel="sponsored nofollow noopener" title="{{ $ad->title }}">
                <img src="{{ $ad->imageUrl() }}" alt="{{ $ad->title }}" loading="lazy">
            </a>
        @endforeach
        @php $from = min($zone->priceList() ?: [0]); @endphp
        @for ($i = 0; $i < $display['placeholders']; $i++)
            <a class="ad ad-empty" href="{{ route('ads.index', ['zone' => $position]) }}">
                <x-icon name="megaphone" :size="20" />
                <span>
                    <b>{{ __('Advertise here') }}</b>
                    <small>{{ $zone->size() }}@if ($from > 0) · {{ __('from :price', ['price' => forum_money($from)]) }}@endif</small>
                </span>
            </a>
        @endfor
    </div>
    @endif
@endif
