@props(['user', 'size' => null, 'link' => true])
@php
    $class = trim('avatar '.$size);
    $hooks = app(\App\Forum\Addons\Hooks::class);
    if ($user && $hooks->has('avatar.class')) {
        $class = trim($class.' '.trim((string) $hooks->filter('avatar.class', '', $user)));
    }
@endphp
@if (! $user)
    <span {{ $attributes->merge(['class' => $class]) }} style="--hue: 220">?</span>
@elseif ($link)
    <a href="{{ route('members.show', $user) }}" {{ $attributes->merge(['class' => $class]) }} style="--hue: {{ $user->avatarHue() }}" title="{{ $user->username }}">
        @if ($user->avatarUrl())<img src="{{ $user->avatarUrl() }}" alt="{{ $user->username }}" loading="lazy">@else{{ $user->initial() }}@endif
    </a>
@else
    <span {{ $attributes->merge(['class' => $class]) }} style="--hue: {{ $user->avatarHue() }}">
        @if ($user->avatarUrl())<img src="{{ $user->avatarUrl() }}" alt="{{ $user->username }}" loading="lazy">@else{{ $user->initial() }}@endif
    </span>
@endif
