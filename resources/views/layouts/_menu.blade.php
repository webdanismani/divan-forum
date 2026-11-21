{{-- The main menu. Classic: a strip under the header, with tools on the right. Single: inside the header, the tools folded into "More". --}}
@php
    $marketOn = setting('market.enabled') && app(\App\Forum\NodeTree::class)->all()->contains('is_marketplace', true);
    $extraNav = trim(app(\App\Forum\Addons\Hooks::class)->render('layout.nav'));
@endphp
@if ($single)
    <nav class="main-nav" aria-label="{{ __('Main navigation') }}">
        <a href="{{ route('forum.index') }}" @class(['active' => request()->routeIs('forum.index', 'nodes.*', 'threads.*', 'posts.*')])><x-icon name="home" :size="16" /> {{ __('Forums') }}</a>
        <a href="{{ route('whats-new') }}" @class(['active' => request()->routeIs('whats-new')])>{{ __("What's new") }}</a>
        <a href="{{ route('members.index') }}" @class(['active' => request()->routeIs('members.*')])>{{ __('Members') }}</a>
        @if ($marketOn)
            <a href="{{ route('market.index') }}" @class(['active' => request()->routeIs('market.*')])>{{ __('Marketplace') }}</a>
        @endif
        @foreach ($site->links('header') as $link)
            <a href="{{ $link->url }}" @if ($link->new_tab) target="_blank" rel="noopener" @endif>{{ $link->title }}</a>
        @endforeach
        @if (setting('ads.enabled') || $me || $extraNav !== '')
            <details class="menu nav-more">
                <summary @class(['active' => request()->routeIs('mod.*', 'ads.*')])>{{ __('More') }} <x-icon name="chevron-down" :size="14" /></summary>
                <div class="menu-panel">
                    @if ($me && forum_can('mod.manageReports'))
                        <a href="{{ route('mod.reports') }}"><x-icon name="shield" :size="16" /> {{ __('Moderation') }}</a>
                    @endif
                    @if (setting('ads.enabled'))
                        <a href="{{ route('ads.index') }}"><x-icon name="megaphone" :size="16" /> {{ __('Advertise') }}</a>
                    @endif
                    {!! $extraNav !!}
                    @auth
                        <form method="post" action="{{ route('forum.mark-read') }}">@csrf
                            <button type="submit"><x-icon name="check" :size="16" /> {{ __('Mark forums read') }}</button>
                        </form>
                    @endauth
                </div>
            </details>
        @endif
    </nav>
@else
            <nav class="main-nav" aria-label="{{ __('Main navigation') }}">
                <a href="{{ route('forum.index') }}" @class(['active' => request()->routeIs('forum.index', 'nodes.*', 'threads.*', 'posts.*')])><x-icon name="message" :size="16" /> {{ __('Forums') }}</a>
                <a href="{{ route('whats-new') }}" @class(['active' => request()->routeIs('whats-new')])><x-icon name="bolt" :size="16" /> {{ __("What's new") }}</a>
                <a href="{{ route('members.index') }}" @class(['active' => request()->routeIs('members.*')])><x-icon name="users" :size="16" /> {{ __('Members') }}</a>
                @if ($marketOn)
                    <a href="{{ route('market.index') }}" @class(['active' => request()->routeIs('market.*')])><x-icon name="tag" :size="16" /> {{ __('Marketplace') }}</a>
                @endif
                @if ($me && forum_can('mod.manageReports'))
                    <a href="{{ route('mod.reports') }}" @class(['active' => request()->routeIs('mod.*')])><x-icon name="shield" :size="16" /> {{ __('Moderation') }}</a>
                @endif
                @foreach ($site->links('header') as $link)
                    <a href="{{ $link->url }}" @if ($link->new_tab) target="_blank" rel="noopener" @endif>{{ $link->title }}</a>
                @endforeach
                {!! $extraNav !!}
            </nav>
            <div class="nav-right">
                @if (setting('ads.enabled'))
                    <a href="{{ route('ads.index') }}" @class(['active' => request()->routeIs('ads.*')])><x-icon name="megaphone" :size="16" /> <span class="lbl">{{ __('Advertise') }}</span></a>
                @endif
                @auth
                    <form method="post" action="{{ route('forum.mark-read') }}">@csrf
                        <button type="submit" title="{{ __('Mark forums read') }}"><x-icon name="check" :size="16" /> <span class="lbl">{{ __('Mark forums read') }}</span></button>
                    </form>
                @endauth
            </div>
@endif
