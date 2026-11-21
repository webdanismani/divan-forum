@props(['user', 'link' => true])
@if (! $user)
    <span {{ $attributes->merge(['class' => 'username muted']) }}>{{ __('Deleted member') }}</span>
@else
    @php
        $color = $user->group?->color;
        $hooks = app(\App\Forum\Addons\Hooks::class);
        $extra = $hooks->has('username.class') ? trim((string) $hooks->filter('username.class', '', $user)) : '';
    @endphp
    <{{ $link ? 'a' : 'span' }} @if ($link) href="{{ route('members.show', $user) }}" @endif
        {{ $attributes->merge(['class' => 'username'.($color ? ' styled' : '').($extra !== '' ? ' '.$extra : '')]) }}
        @if ($color) style="--group-color: {{ $color }}" @endif>{{ $user->username }}</{{ $link ? 'a' : 'span' }}>
@endif
