@extends('layouts.app')

@isset($category)
    @section('title', $category->title)
    @section('crumb', $category->title)
@endisset

@php $home = app(\App\Forum\Home::class); @endphp

@if ($sidebar && ($home->enabled('hero') || $home->enabled('tiles')))
    @section('hero')
        @include('home._top')
    @endsection
@endif

@section('content')
{{-- The hero takes the place of the welcome area. --}}
@if ($sidebar && ! ($home->enabled('hero') && $home->slides()))
    @include('forum._welcome')
@endif
<div @class(['layout', 'single' => ! $sidebar])>
<div>
    @if ($sidebar)
        @include('forum._shoutbox')
    @endif
    @if ($sidebar && $home->enabled('latest'))
        @php
            $lists = [
                'replied' => ['bolt', __('Latest replies'), $sidebar['recentReplied']],
                'created' => ['edit', __('Newest threads'), $sidebar['recentCreated']],
                'popular' => ['chart', __('Popular'), $sidebar['popular']],
            ];
        @endphp
        <section class="block" data-tabs>
            <div class="bar tabbar" role="tablist">
                @foreach ($lists as $key => [$icon, $label])
                    <button type="button" role="tab" data-tab="{{ $key }}" @class(['active' => $loop->first])><x-icon :name="$icon" :size="15" /> {{ $label }}</button>
                @endforeach
            </div>
            <div class="thead latest-grid latest-head">
                <span>{{ __('Thread title') }}</span>
                <span class="center">{{ __('Replies / Views') }}</span>
                <span>{{ __('Last reply') }}</span>
                <span>{{ __('Forum') }}</span>
            </div>
            @foreach ($lists as $key => [$icon, $label, $threads])
                <div data-pane="{{ $key }}" @class(['hidden' => ! $loop->first])>
                    @forelse ($threads as $thread)
                        <div @class(['latest-row', 'latest-grid', 'unread' => $thread->isUnreadFor(auth()->user()), 'hl' => $thread->isHighlighted()])>
                            <div class="truncate">
                                @if ($thread->prefix)<span class="prefix {{ $thread->prefix->color }}">{{ $thread->prefix->title }}</span>@endif
                                <a class="t" href="{{ route(auth()->check() ? 'threads.unread' : 'threads.show', $thread) }}">{{ $thread->title }}</a>
                            </div>
                            <div class="counts"><span>{{ forum_number($thread->reply_count) }}</span><i class="dot"></i><span>{{ forum_number($thread->view_count) }}</span></div>
                            <div class="truncate"><x-username :user="$thread->lastPostUser" /></div>
                            <div class="truncate"><a class="cat" href="{{ route('nodes.show', $thread->node) }}">{{ $thread->node->title }}</a></div>
                        </div>
                    @empty
                        <div class="empty">{{ __('No posts yet') }}</div>
                    @endforelse
                </div>
            @endforeach
        </section>
    @endif

    <section class="block">
        <div class="bar forum-grid forum-head">
            <span>{{ __('Forum') }}</span>
            <span>{{ __('Last post') }}</span>
            <span class="center">{{ __('Threads / Posts') }}</span>
        </div>
        @forelse ($sections as $section)
            @php $catKey = 'cat-'.($section['category']->id ?? 0); @endphp
            @php $forumCount = count($section['rows']); @endphp
            <div class="catbar" data-catbar>
                @if ($section['category']?->iconUrl())
                    <span class="cat-mark has-image"><img src="{{ $section['category']->iconUrl() }}" alt="" loading="lazy"></span>
                @else
                    <span class="cat-mark"><x-icon name="folder" :size="16" /></span>
                @endif
                <div class="cat-text">
                    @if ($section['category'])
                        <a class="cat-title" href="{{ route('nodes.show', $section['category']) }}">{{ $section['category']->title }}</a>
                        @if (filled($section['category']->description))<div class="cat-desc">{{ $section['category']->description }}</div>@endif
                    @else
                        <span class="cat-title">{{ __('Forums') }}</span>
                    @endif
                </div>
                <span class="spacer"></span>
                <span class="cat-count">{{ trans_choice(':count forum|:count forums', $forumCount, ['count' => $forumCount]) }}<span class="when-collapsed"> · {{ __('hidden') }}</span></span>
                <button class="cat-toggle" type="button" data-collapse="{{ $catKey }}" data-label-open="{{ __('Collapse') }}" data-label-closed="{{ __('Expand') }}" aria-expanded="true" title="{{ __('Collapse / expand') }}"><x-icon name="chevron-down" :size="16" /></button>
            </div>
            <div data-collapsible="{{ $catKey }}">
                @if ($section['rows'])
                    {{-- Column names per category: hidden in the classic look, for themes that draw every category as a card of its own. --}}
                    <div class="thead forum-grid forum-cols" aria-hidden="true">
                        <span></span><span>{{ __('Forum') }}</span><span>{{ __('Last post') }}</span>
                        <span class="cols-counts"><span>{{ __('Threads') }}</span><span>{{ __('Posts') }}</span></span>
                    </div>
                    @include('forum._node-rows', ['rows' => $section['rows']])
                @else
                    <div class="empty">{{ __('There are no forums here yet.') }}</div>
                @endif
            </div>
        @empty
            <div class="empty">
                @guest
                    {{ __('You need to sign in to view the forums.') }}
                    <div style="margin-top: 12px"><a class="btn btn-primary" href="{{ route('login') }}">{{ __('Log in') }}</a></div>
                @else
                    {{ __('There are no forums here yet.') }}
                @endguest
            </div>
        @endforelse
    </section>

    @if ($sidebar && $home->enabled('news'))
        @include('home._news')
    @endif

    @if ($sidebar && $home->enabled('statistics'))
        <section class="block">
            <div class="bar"><x-icon name="chart" :size="16" /><h2>{{ __('Forum statistics') }}</h2></div>
            <div class="stats-row">
                <div><x-icon name="users" :size="24" /></div>
                <div>
                    <h3>{{ __('Members online') }} ({{ $sidebar['online']->count() }})</h3>
                    @if ($sidebar['online']->isEmpty())
                        <span class="muted">{{ __('Nobody is online right now.') }}</span>
                    @else
                        <div class="online-list">
                            @foreach ($sidebar['online'] as $member)
                                <span class="online-who"><x-username :user="$member" />@if ($deviceIcon = (\App\Models\User::DEVICES[$member->last_device][0] ?? null))<span title="{{ __(\App\Models\User::DEVICES[$member->last_device][1]) }}"><x-icon :name="$deviceIcon" :size="12" /></span>@endif</span>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
            <div class="stats-row">
                <div><x-icon name="chart" :size="24" /></div>
                <div>
                    <h3>{{ __('Statistics') }}</h3>
                    {{ __('Threads') }}: <b>{{ forum_number($sidebar['threadCount']) }}</b> &nbsp;·&nbsp;
                    {{ __('Posts') }}: <b>{{ forum_number($sidebar['postCount']) }}</b> &nbsp;·&nbsp;
                    {{ __('Members') }}: <b>{{ forum_number($sidebar['memberCount']) }}</b>
                    @if ($sidebar['newestMember'])
                        &nbsp;·&nbsp; {{ __('Newest member') }}: <x-username :user="$sidebar['newestMember']" />
                    @endif
                </div>
            </div>
        </section>
    @endif
</div>
@if ($sidebar)
    @include('forum._sidebar')
@endif
</div>
@if ($sidebar && $home->enabled('band'))
    @include('home._band')
@endif
@endsection
