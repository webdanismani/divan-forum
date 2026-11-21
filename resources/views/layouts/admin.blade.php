@php
    $theme = request()->cookie('theme') ?: (auth()->user()?->theme ?: setting('board.default_theme', 'auto'));
    $theme = in_array($theme, ['auto', 'light', 'dark'], true) ? $theme : 'auto';
    $me = auth()->user();
    $acpPending ??= app(\App\Forum\Setup\Pending::class);
    $waiting = $acpPending->waiting();

    // Group => [icon, links]; a link is [route, text, pattern that marks it as the page in view].
    $nav = [
        'general' => [__('Overview'), 'sliders', [
            ['admin.settings.edit', __('Settings'), 'admin.settings.*'],
            ['admin.appearance.edit', __('Appearance'), 'admin.appearance.*'],
            ['admin.home.edit', __('Home page'), 'admin.home.*'],
            ['admin.phrases', __('Texts'), 'admin.phrases*'],
            ['admin.templates', __('Templates'), 'admin.templates*'],
            ['admin.addons', __('Add-ons'), 'admin.addons*'],
            ['admin.market', __('Market'), 'admin.market*'],
        ]],
        'forums' => [__('Forums'), 'folder', [
            ['admin.nodes.index', __('Nodes'), 'admin.nodes.*'],
            ['admin.prefixes', __('Thread prefixes'), 'admin.prefixes*'],
            ['admin.reactions', __('Reactions'), 'admin.reactions*'],
        ]],
        'content' => [__('Content'), 'list', [
            ['admin.pages', __('Pages'), 'admin.pages*'],
            ['admin.notices', __('Notices and menu links'), 'admin.notices*'],
            ['admin.messages', __('Contact messages'), 'admin.messages*'],
            ['admin.mail', __('E-mail to members'), 'admin.mail*'],
        ]],
        'members' => [__('Members'), 'users', [
            ['admin.users.index', __('Members'), 'admin.users.*'],
            ['admin.groups.index', __('User groups'), 'admin.groups.*'],
            ['admin.warnings', __('Warnings'), 'admin.warnings*'],
            ['admin.bans', __('Bans and IP addresses'), 'admin.bans*'],
            ['admin.duplicates', __('Shared accounts'), 'admin.duplicates*'],
            ['admin.deletions', __('Account deletion requests'), 'admin.deletions*'],
            ['admin.profile', __('Profile fields and titles'), 'admin.profile*'],
            ['admin.rewards', __('Badges and promotions'), 'admin.rewards*'],
        ]],
        'advertising' => [__('Advertising'), 'megaphone', [
            ['admin.ads.index', __('Ads'), 'admin.ads.*'],
            ['admin.ad-zones.index', __('Ad spaces'), 'admin.ad-zones.*'],
            ['admin.coupons', __('Coupon codes'), 'admin.coupons*'],
        ]],
        'sales' => [__('Sales'), 'card', [
            ['admin.payments.index', __('Payments'), 'admin.payments.*'],
            ['admin.wallet', __('Member balances'), 'admin.wallet'],
            ['admin.promotions', __('Thread promotion'), 'admin.promotions*'],
            ['admin.upgrades', __('Membership packages'), 'admin.upgrades*'],
        ]],
        'tools' => [__('Tools'), 'key', [
            ['admin.logs', __('Moderator log'), 'admin.logs'],
            ['admin.integrations', __('API and webhooks'), 'admin.integrations*'],
            ['admin.import', __('Import from another forum'), 'admin.import*'],
            ['admin.update', __('Maintenance and updates'), 'admin.update*'],
            ['admin.backups', __('Backups'), 'admin.backups*'],
            ['admin.license', __('Licence'), 'admin.license*'],
        ]],
    ];

    // Where the page in view sits in the menu: its group is open and named above the title.
    $crumb = null;
    foreach ($nav as [$groupLabel, $groupIcon, $links]) {
        foreach ($links as [$route, $text, $pattern]) {
            if (request()->routeIs($pattern)) {
                $crumb = $groupLabel;
                break 2;
            }
        }
    }

    // Add-ons print a heading and their links; each heading becomes a group like the ones above.
    $extra = [];
    $parts = preg_split('~<div class="group-label">(.*?)</div>~s', (string) app(\App\Forum\Addons\Hooks::class)->render('admin.nav'), -1, PREG_SPLIT_DELIM_CAPTURE);
    for ($i = 1; $i < count($parts); $i += 2) {
        $extra[] = [trim(strip_tags($parts[$i])), $parts[$i + 1] ?? ''];
    }
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" data-theme="{{ $theme }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') | {{ __('Admin panel') }}</title>
    {{-- The panel has colours of its own: board colours, themes and custom CSS stop at its door, so a bad choice there can never lock the administrator out. --}}
    <link rel="stylesheet" href="{{ asset('assets/forum.css') }}?v={{ @filemtime(public_path('assets/forum.css')) }}">
    <link rel="stylesheet" href="{{ asset('assets/admin.css') }}?v={{ @filemtime(public_path('assets/admin.css')) }}">
</head>
<body class="acp" data-page="{{ \Illuminate\Support\Facades\Route::currentRouteName() }}">
<header class="acp-top">
    <button class="acp-burger" type="button" data-admin-menu aria-expanded="false" aria-label="{{ __('Menu') }}"><x-icon name="menu" :size="20" /></button>
    <a class="acp-brand" href="{{ route('admin.dashboard') }}">
        <span class="acp-mark"><x-icon name="shield" :size="17" /></span>
        <b>{{ config('forum.product.name') }}</b><span>{{ __('Administration') }}</span>
    </a>
    <div class="admin-search" data-admin-search="{{ route('admin.search', [], false) }}" data-l-settings="{{ __('Settings') }}" data-l-members="{{ __('Members') }}" data-l-pages="{{ __('Pages of the panel') }}" data-l-empty="{{ __('Nothing found.') }}">
        <x-icon name="search" :size="15" />
        <input type="search" placeholder="{{ __('Search the panel…') }}" aria-label="{{ __('Search the panel…') }}" autocomplete="off">
        <div class="admin-search-results" hidden></div>
    </div>
    <div class="acp-tools">
        <a class="acp-tool acp-visit" href="{{ route('forum.index') }}"><x-icon name="home" :size="16" /> <span>{{ __('View forum') }}</span></a>
        <details class="menu">
            <summary class="acp-tool" title="{{ __('Waiting for you') }}" aria-label="{{ __('Waiting for you') }}"><x-icon name="bell" :size="17" />@if ($acpPending->total())<span class="acp-count">{{ $acpPending->total() }}</span>@endif</summary>
            <div class="menu-panel acp-waiting">
                <div class="acp-menu-head">{{ __('Waiting for you') }}</div>
                @forelse ($waiting as $item)
                    <a href="{{ $item['url'] }}"><x-icon :name="$item['icon']" :size="16" /> {{ __($item['label']) }} <span class="badge amber">{{ $item['count'] }}</span></a>
                @empty
                    <div class="acp-menu-empty">{{ __('Nothing is waiting.') }}</div>
                @endforelse
            </div>
        </details>
        <button class="acp-tool" type="button" data-theme-toggle title="{{ __('Switch theme') }}" aria-label="{{ __('Switch theme') }}"><x-icon name="moon" :size="17" class="i-moon" /><x-icon name="sun" :size="17" class="i-sun" /></button>
        <details class="menu">
            <summary class="acp-tool acp-user"><x-avatar :user="$me" size="xs" :link="false" /> <span>{{ $me->username }}</span> <x-icon name="chevron-down" :size="13" /></summary>
            <div class="menu-panel">
                <a href="{{ route('members.show', $me) }}"><x-icon name="user" :size="16" /> {{ __('My profile') }}</a>
                <a href="{{ route('account.edit') }}"><x-icon name="sliders" :size="16" /> {{ __('Account settings') }}</a>
                <a href="{{ route('forum.index') }}"><x-icon name="home" :size="16" /> {{ __('Back to the forums') }}</a>
                <div class="menu-sep"></div>
                <form method="post" action="{{ route('logout') }}">@csrf
                    <button type="submit" class="danger"><x-icon name="logout" :size="16" /> {{ __('Log out') }}</button>
                </form>
            </div>
        </details>
    </div>
</header>

<div class="admin-shell">
    <aside class="admin-side">
        <nav aria-label="{{ __('Admin panel') }}">
            <a @class(['acp-home', 'active' => request()->routeIs('admin.dashboard')]) href="{{ route('admin.dashboard') }}"><x-icon name="chart" :size="17" /> {{ __('Dashboard') }}</a>
            @foreach ($nav as $key => [$groupLabel, $groupIcon, $links])
                @php
                    $here = collect($links)->contains(fn ($link) => request()->routeIs($link[2]));
                    $groupCount = collect($links)->sum(fn ($link) => $acpPending->forRoute($link[0]));
                @endphp
                <details class="acp-group" data-acp-group="{{ $key }}" @if ($here) open data-here @endif>
                    <summary><x-icon :name="$groupIcon" :size="17" /> <span>{{ $groupLabel }}</span>@if ($groupCount)<i class="acp-dot" title="{{ __('Waiting for you') }}"></i>@endif<x-icon name="chevron-right" :size="14" class="acp-arrow" /></summary>
                    @foreach ($links as [$route, $text, $pattern])
                        <a href="{{ route($route) }}" @class(['active' => request()->routeIs($pattern)])>{{ $text }}@if ($count = $acpPending->forRoute($route))<span class="badge amber">{{ $count }}</span>@endif</a>
                    @endforeach
                </details>
            @endforeach
            @if ($extra)
                @php
                    $extraHtml = implode('', array_column($extra, 1));
                    $here = str_contains($extraHtml, 'class="active"');
                @endphp
                <details class="acp-group acp-ext" data-acp-group="modules" @if ($here) open data-here @endif>
                    <summary><x-icon name="grid" :size="17" /> <span>{{ __('Modules') }}</span>@if (str_contains($extraHtml, 'class="badge'))<i class="acp-dot" title="{{ __('Waiting for you') }}"></i>@endif<x-icon name="chevron-right" :size="14" class="acp-arrow" /></summary>
                    @foreach ($extra as [$groupLabel, $html])
                        <div class="acp-cap">{{ $groupLabel }}</div>
                        {!! $html !!}
                    @endforeach
                </details>
            @endif
        </nav>
        <div class="acp-side-foot">{{ config('forum.product.name') }} {{ config('forum.product.version') }}</div>
    </aside>

    <main class="admin-main">
        <div class="acp-head">
            <div class="titles">
                @if ($crumb)<div class="acp-crumb"><a href="{{ route('admin.dashboard') }}">{{ __('Administration') }}</a> <x-icon name="chevron-right" :size="11" /> {{ $crumb }}</div>@endif
                <h1>@yield('title')</h1>
                @hasSection('subtitle')<div class="desc">@yield('subtitle')</div>@endif
            </div>
            <div class="row">@yield('actions')</div>
        </div>
        @include('partials.flash')
        @yield('content')
    </main>
</div>
<script src="{{ asset('assets/forum.js') }}?v={{ @filemtime(public_path('assets/forum.js')) }}" defer></script>
</body>
</html>
