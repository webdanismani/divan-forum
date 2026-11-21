{{-- "Go to forum" under every page, the up/down buttons, and the bar at the bottom of a phone screen. --}}
@php
    $jumpTree = app(\App\Forum\NodeTree::class);
    $jumpNodes = $jumpTree->flat($jumpTree->viewable(auth()->user()));
@endphp
@if (count($jumpNodes) > 1)
    <form class="forum-jump" action="{{ route('forum.index') }}" method="get">
        <label for="forum-jump"><x-icon name="folder" :size="15" /> {{ __('Go to forum') }}</label>
        <select class="input" id="forum-jump" data-jump>
            <option value="">{{ __('Choose a forum…') }}</option>
            @foreach ($jumpNodes as $item)
                @continue($item['node']->isLink())
                <option value="{{ route('nodes.show', $item['node']) }}" @selected(($node ?? null)?->id === $item['node']->id)>{{ str_repeat('— ', $item['depth']) }}{{ $item['node']->title }}</option>
            @endforeach
        </select>
    </form>
@endif

<div class="scroll-buttons" data-scroll-buttons hidden>
    <button type="button" data-scroll="top" title="{{ __('Back to the top') }}" aria-label="{{ __('Back to the top') }}"><x-icon name="chevron-down" :size="18" /></button>
    <button type="button" data-scroll="bottom" title="{{ __('To the bottom') }}" aria-label="{{ __('To the bottom') }}"><x-icon name="chevron-down" :size="18" /></button>
</div>

<nav class="mobile-nav" aria-label="{{ __('Quick menu') }}">
    <a href="{{ route('forum.index') }}" @class(['active' => request()->routeIs('forum.index', 'nodes.*', 'threads.*')])><x-icon name="home" :size="20" /><span>{{ __('Forums') }}</span></a>
    <a href="{{ route('whats-new') }}" @class(['active' => request()->routeIs('whats-new', 'my')])><x-icon name="bolt" :size="20" /><span>{{ __('New') }}</span></a>
    @auth
        <a href="{{ route('notifications.index') }}" @class(['active' => request()->routeIs('notifications.*')])><x-icon name="bell" :size="20" /><span>{{ __('Alerts') }}</span><i class="count" data-live="alerts" @if (! $unreadAlerts) hidden @endif>{{ $unreadAlerts }}</i></a>
        <a href="{{ route('conversations.index') }}" @class(['active' => request()->routeIs('conversations.*')])><x-icon name="mail" :size="20" /><span>{{ __('Messages') }}</span><i class="count" data-live="conversations" @if (! $unreadConversations) hidden @endif>{{ $unreadConversations }}</i></a>
        <a href="{{ route('my') }}" @class(['active' => request()->routeIs('members.show', 'account.*')])><x-icon name="user" :size="20" /><span>{{ __('Mine') }}</span></a>
    @else
        @if (forum_can('search.use'))
            <a href="{{ route('search') }}" @class(['active' => request()->routeIs('search')])><x-icon name="search" :size="20" /><span>{{ __('Search') }}</span></a>
        @endif
        <a href="{{ route('login') }}" @class(['active' => request()->routeIs('login')])><x-icon name="user" :size="20" /><span>{{ __('Log in') }}</span></a>
        @if (setting('registration.enabled'))
            <a href="{{ route('register') }}" @class(['active' => request()->routeIs('register')])><x-icon name="plus" :size="20" /><span>{{ __('Register') }}</span></a>
        @endif
    @endauth
</nav>
