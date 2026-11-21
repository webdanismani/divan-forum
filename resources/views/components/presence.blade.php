{{-- Online or offline, as a small pill. The caller has already checked that the member lets this be seen. --}}
@props(['user', 'online' => false])
@php
    [$deviceIcon, $deviceLabel] = $online ? (\App\Models\User::DEVICES[$user->last_device] ?? [null, null]) : [null, null];
@endphp
@if ($online)
    <span {{ $attributes->class(['presence', 'on']) }}>
        <span class="presence-dot"></span><span class="presence-text">{{ __('Online') }}</span>
        @if ($deviceIcon)<span class="presence-extra" title="{{ __('Device in use') }}"><x-icon :name="$deviceIcon" :size="12" /> {{ __($deviceLabel) }}</span>@endif
    </span>
@else
    <span {{ $attributes->class(['presence', 'off']) }} @if ($user->last_seen_at) title="{{ __('Last seen') }}: {{ $user->last_seen_at->translatedFormat('j F Y H:i') }}" @endif>
        <span class="presence-dot"></span><span class="presence-text">{{ __('Offline') }}</span>
        @if ($user->last_seen_at)<span class="presence-extra">{{ $user->last_seen_at->diffForHumans() }}</span>@endif
    </span>
@endif
