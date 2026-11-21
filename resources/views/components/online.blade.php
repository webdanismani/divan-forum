{{-- "Online" with the kind of device the member is using. $user is assumed to be online and visible as such. --}}
@props(['user', 'compact' => false])
@php
    [$deviceIcon, $deviceLabel] = \App\Models\User::DEVICES[$user->last_device] ?? [null, null];
@endphp
@if ($compact)
    <span class="online-mark" title="{{ __('Online') }}@if ($deviceLabel) · {{ __($deviceLabel) }}@endif">
        <span class="online-dot"></span>@if ($deviceIcon)<x-icon :name="$deviceIcon" :size="12" />@endif
    </span>
@else
    <span {{ $attributes->merge(['class' => 'badge green online-badge']) }}>
        <span class="online-dot"></span> {{ __('Online') }}
        @if ($deviceIcon)<span class="device" title="{{ __('Device in use') }}"><x-icon :name="$deviceIcon" :size="12" /> {{ __($deviceLabel) }}</span>@endif
    </span>
@endif
