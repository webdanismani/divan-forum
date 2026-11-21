@php
    $theme = request()->cookie('theme') ?: (auth()->user()?->theme ?: setting('board.default_theme', 'auto'));
    $theme = in_array($theme, ['auto', 'light', 'dark'], true) ? $theme : 'auto';
    $boardName = setting('board.name');
    $nameParts = explode(' ', $boardName, 2);
    $me = auth()->user();
    $site = app(\App\Forum\Site::class);
    $custom = app(\App\Forum\Customizer::class);
    // A theme can ask for the menu inside the header (one row) instead of the strip under it.
    $singleHeader = app(\App\Forum\Themes::class)->option('header') === 'single';
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" data-theme="{{ $theme }}" @if (setting('board.thread_preview')) data-thread-preview @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@hasSection('title')@yield('title') | @endif{{ $boardName }}</title>
    <meta name="description" content="@yield('description', setting('board.description'))">
    <meta property="og:site_name" content="{{ $boardName }}">
    <meta property="og:title" content="@hasSection('title')@yield('title')@else{{ $boardName }}@endif">
    <meta property="og:description" content="@yield('description', setting('board.description'))">
    <meta property="og:type" content="@yield('og_type', 'website')">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta name="twitter:card" content="summary">
    <link rel="alternate" type="application/rss+xml" title="{{ $boardName }}" href="{{ route('feed') }}">
    @yield('head')
    @unless (str_contains($__env->yieldContent('head'), 'rel="canonical"'))
        <link rel="canonical" href="{{ url()->current() }}{{ (int) request()->query('page') > 1 ? '?page='.(int) request()->query('page') : '' }}">
    @endunless
    @if (setting('pwa.enabled'))
        <link rel="manifest" href="{{ route('pwa.manifest', [], false) }}">
        <link rel="apple-touch-icon" href="{{ route('pwa.icon', 180, false) }}">
        <meta name="service-worker" content="{{ route('pwa.sw', [], false) }}">
    @endif
    @auth
        @if ((int) setting('live.poll_seconds') > 0)
            <meta name="live-alerts" content="{{ route('live.poll', [], false) }}" data-seconds="{{ max(10, (int) setting('live.poll_seconds')) }}">
        @endif
    @endauth
    <meta name="theme-color" content="{{ app(\App\Forum\Theme::class)->colors()['header'] }}">
    <link rel="preload" href="{{ asset('assets/fonts/roboto-latin.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ asset('assets/forum.css') }}?v={{ @filemtime(public_path('assets/forum.css')) }}">
    @if ($themeCss = app(\App\Forum\Theme::class)->css())
        <style id="theme-colors">{!! $themeCss !!}</style>
    @endif
    @if ($installedThemeCss = app(\App\Forum\Themes::class)->css())
        <style id="theme-css">{!! $installedThemeCss !!}</style>
    @endif
    @if ($customCss = $custom->css())
        <style id="custom-css">{!! $customCss !!}</style>
    @endif
    {!! $custom->html('head') !!}
    @hook('layout.head')
</head>
{{-- data-page and data-guest are there for themes and custom CSS: they let a stylesheet tell the pages apart. --}}
<body data-page="{{ \Illuminate\Support\Facades\Route::currentRouteName() }}" @guest data-guest @endguest @if ($singleHeader) data-header="single" @endif>
@hook('layout.body_start')
<header class="site-header">
    <div class="topbar">
        <div class="container topbar-inner">
            <a class="brand" href="{{ route('forum.index') }}">
                @if ($logo = $custom->logoUrl())
                    <img class="brand-logo" src="{{ $logo }}" alt="{{ $boardName }}">
                @else
                    <span class="brand-mark"><x-icon name="message" :size="20" /></span>
                    <span class="truncate"><b>{{ $nameParts[0] }}</b>@isset($nameParts[1]) {{ $nameParts[1] }}@endisset</span>
                @endif
            </a>

            @if ($singleHeader)
                @include('layouts._menu', ['single' => true])
            @endif

            @if (forum_can('search.use'))
                <form class="header-search" action="{{ route('search') }}" method="get" role="search">
                    <x-icon name="search" :size="17" />
                    <input type="search" name="q" value="{{ request()->routeIs('search') ? request('q') : '' }}" placeholder="{{ __('Search the forum…') }}" aria-label="{{ __('Search') }}" data-search-input autocomplete="off"
                        data-suggest="{{ route('search.suggest', [], false) }}" data-label-threads="{{ __('Threads') }}" data-label-members="{{ __('Members') }}" data-label-empty="{{ __('Nothing found.') }}" data-label-all="{{ __('All results for ":query"') }}">
                    <kbd aria-hidden="true">/</kbd>
                </form>
            @endif

            <div class="header-tools">
                @if (forum_can('search.use'))
                    <a class="header-btn icon-only search-link" href="{{ route('search') }}" title="{{ __('Search') }}" aria-label="{{ __('Search') }}"><x-icon name="search" :size="19" /></a>
                @endif
                <button class="header-btn icon-only" type="button" data-theme-toggle title="{{ __('Switch theme') }}" aria-label="{{ __('Switch theme') }}"><x-icon name="moon" :size="19" class="i-moon" /><x-icon name="sun" :size="19" class="i-sun" /></button>
                @auth
                    {{-- Conversations and alerts open as a pop-over; its list is fetched when it opens. --}}
                    <details class="menu header-pop" data-pop="{{ route('conversations.panel', [], false) }}">
                        <summary class="header-btn icon-only" title="{{ __('Conversations') }}" aria-label="{{ __('Conversations') }}">
                            <x-icon name="mail" :size="19" /><span class="count" data-live="conversations" @if (! $unreadConversations) hidden @endif>{{ $unreadConversations }}</span>
                        </summary>
                        <div class="pop-panel">
                            <div class="pop-head">
                                <b>{{ __('Conversations') }}</b>
                                @if (forum_can('conversation.start'))
                                    <a href="{{ route('conversations.create') }}"><x-icon name="plus" :size="14" /> {{ __('New conversation') }}</a>
                                @endif
                            </div>
                            <div class="pop-list" data-pop-list><div class="pop-empty">{{ __('Loading…') }}</div></div>
                            <a class="pop-foot" href="{{ route('conversations.index') }}">{{ __('Show all conversations') }}</a>
                        </div>
                    </details>
                    <details class="menu header-pop" data-pop="{{ route('notifications.panel', [], false) }}">
                        <summary class="header-btn icon-only" title="{{ __('Alerts') }}" aria-label="{{ __('Alerts') }}">
                            <x-icon name="bell" :size="19" /><span class="count" data-live="alerts" @if (! $unreadAlerts) hidden @endif>{{ $unreadAlerts }}</span>
                        </summary>
                        <div class="pop-panel">
                            <div class="pop-head">
                                <b>{{ __('Alerts') }}</b>
                                <button type="button" data-pop-read="{{ route('notifications.read-all', [], false) }}"><x-icon name="check" :size="14" /> {{ __('Mark all as read') }}</button>
                            </div>
                            <div class="pop-list" data-pop-list><div class="pop-empty">{{ __('Loading…') }}</div></div>
                            <a class="pop-foot" href="{{ route('notifications.index') }}">{{ __('Show all alerts') }}</a>
                        </div>
                    </details>

                    <details class="menu">
                        <summary class="header-btn header-user">
                            <x-avatar :user="$me" size="xs" :link="false" />
                            <span class="truncate name" style="max-width: 130px">{{ $me->username }}</span>
                            <x-icon name="chevron-down" :size="14" />
                        </summary>
                        <div class="menu-panel">
                            <a href="{{ route('members.show', $me) }}"><x-icon name="user" :size="16" /> {{ __('My profile') }}</a>
                            <a href="{{ route('my') }}"><x-icon name="list" :size="16" /> {{ __('My content') }}</a>
                            <a href="{{ route('bookmarks.index') }}"><x-icon name="bookmark" :size="16" /> {{ __('Bookmarks') }}</a>
                            <a href="{{ route('friends.index') }}"><x-icon name="users" :size="16" /> {{ __('Friends') }}</a>
                            @if (forum_can('replies.saved'))
                                <a href="{{ route('replies.index') }}"><x-icon name="edit" :size="16" /> {{ __('Saved replies') }}</a>
                            @endif
                            @hook('layout.user_menu', ['user' => $me])
                            <a href="{{ route('account.edit') }}"><x-icon name="sliders" :size="16" /> {{ __('Account settings') }}</a>
                            @if (setting('wallet.enabled'))
                                <a href="{{ route('wallet.index') }}"><x-icon name="wallet" :size="16" /> {{ __('My balance') }} <span class="muted" style="margin-left: auto">{{ forum_money($me->balance) }}</span></a>
                                @if (app(\App\Forum\Edition::class)->has('paid_memberships'))
                                    <a href="{{ route('upgrades.index') }}"><x-icon name="star" :size="16" /> {{ __('Membership packages') }}</a>
                                @endif
                            @endif
                            @if (setting('ads.enabled'))
                                <a href="{{ route('ads.mine') }}"><x-icon name="megaphone" :size="16" /> {{ __('My ads') }}</a>
                                <a href="{{ route('payments.index') }}"><x-icon name="card" :size="16" /> {{ __('My payments') }}</a>
                            @endif
                            @can('admin')
                                <div class="menu-sep"></div>
                                <a href="{{ route('admin.dashboard') }}"><x-icon name="shield" :size="16" /> {{ __('Admin panel') }}</a>
                            @endcan
                            <div class="menu-sep"></div>
                            <form method="post" action="{{ route('logout') }}">@csrf
                                <button type="submit" class="danger"><x-icon name="logout" :size="16" /> {{ __('Log out') }}</button>
                            </form>
                        </div>
                    </details>
                @else
                    <a class="header-cta" href="{{ route('login') }}">{{ __('Log in') }}</a>
                    @if (setting('registration.enabled'))
                        <a class="header-cta primary" href="{{ route('register') }}">{{ __('Register') }}</a>
                    @endif
                @endauth
            </div>
        </div>
    </div>
</header>

@unless ($singleHeader)
<div class="navbar">
        <div class="container navbar-inner">
            @include('layouts._menu', ['single' => false])
        </div>
</div>
@endunless

@yield('hero')

<main class="container">
    @foreach ($site->notices($me) as $notice)
        <div class="notice notice-{{ $notice->style }}" data-notice="{{ $notice->id }}-{{ $notice->updated_at?->getTimestamp() }}">
            <div class="notice-body">@if ($notice->title)<b>{{ $notice->title }}</b> @endif<span class="bb">{!! $notice->body_html !!}</span></div>
            @if ($notice->is_dismissible)<button type="button" data-notice-close title="{{ __('Hide') }}" aria-label="{{ __('Hide') }}"><x-icon name="x" :size="15" /></button>@endif
        </div>
    @endforeach
    <x-ad-zone position="top" />
    @if (! request()->routeIs('forum.index'))
        <nav class="breadcrumbs" aria-label="{{ __('Breadcrumbs') }}">
            <a href="{{ route('forum.index') }}"><x-icon name="home" :size="14" /> {{ __('Forums') }}</a>
            @foreach ($breadcrumbs ?? [] as $crumb)
                <x-icon name="chevron-right" :size="12" />
                <a href="{{ route('nodes.show', $crumb) }}">{{ $crumb->title }}</a>
            @endforeach
            @hasSection('crumb')
                <x-icon name="chevron-right" :size="12" /> <span>@yield('crumb')</span>
            @endif
        </nav>
    @endif

    @if ($me && ! $me->isVerified() && setting('registration.verify_email') && ! request()->routeIs('verification.*'))
        <div class="notice-bar">
            <x-icon name="mail" :size="17" />
            <span style="flex: 1">{{ __('Please confirm your e-mail address to start posting.') }}</span>
            <a class="btn btn-sm" href="{{ route('verification.notice') }}">{{ __('Confirm e-mail address') }}</a>
        </div>
    @endif

    @include('partials.flash')
    @yield('content')
    <x-ad-zone position="bottom" />
</main>

@include('layouts._jump')
@include('layouts._footer')

<script src="{{ asset('assets/forum.js') }}?v={{ @filemtime(public_path('assets/forum.js')) }}" defer></script>
{!! $custom->html('footer') !!}
</body>
</html>
