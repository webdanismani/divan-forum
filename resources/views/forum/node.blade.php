@extends('layouts.app')

@section('title', $node->title)
@section('description', $node->description ?: setting('board.description'))
@section('crumb', $node->title)
@section('head')
    <link rel="alternate" type="application/rss+xml" title="{{ $node->title }}" href="{{ route('nodes.feed', $node) }}">
@endsection

@php
    $modActions = array_filter([
        'approve' => forum_can('mod.approve', $node) ? __('Approve') : null,
        'lock' => forum_can('mod.lock', $node) ? __('Lock') : null,
        'unlock' => forum_can('mod.lock', $node) ? __('Unlock') : null,
        'stick' => forum_can('mod.stick', $node) ? __('Stick') : null,
        'unstick' => forum_can('mod.stick', $node) ? __('Unstick') : null,
        'move' => forum_can('mod.move', $node) ? __('Move') : null,
        'merge' => forum_can('mod.merge', $node) ? __('Merge') : null,
        'copy' => forum_can('mod.merge', $node) ? __('Copy to another forum') : null,
        'delete' => forum_can('mod.deleteAny', $node) ? __('Delete') : null,
        'restore' => forum_can('mod.deleteAny', $node) ? __('Restore') : null,
        'hard_delete' => forum_can('mod.hardDelete', $node) ? __('Delete permanently') : null,
    ]);
    $moveTargets = $modActions ? array_filter(app(\App\Forum\NodeTree::class)->flat(app(\App\Forum\NodeTree::class)->viewable(auth()->user())), fn ($item) => $item['node']->isForum()) : [];
@endphp

@section('content')
    <div class="page-head">
        <div class="titles">
            <h1>{{ $node->title }}</h1>
            @if ($node->description)<div class="desc">{{ $node->description }}</div>@endif
            @if ($moderators->isNotEmpty())
                <div class="thread-meta" style="margin-top: 4px"><x-icon name="shield" :size="13" /> {{ __('Moderators') }}: @foreach ($moderators as $moderator)<x-username :user="$moderator" />@if (! $loop->last), @endif @endforeach</div>
            @endif
        </div>
        @auth
            <form method="post" action="{{ route('nodes.watch', $node) }}">@csrf
                <button class="btn" type="submit"><x-icon name="eye" :size="16" /> {{ $watching ? __('Unwatch forum') : __('Watch forum') }}</button>
            </form>
        @endauth
        @if (forum_can('thread.create', $node))
            <a class="btn btn-primary" href="{{ route('threads.create', $node) }}"><x-icon name="plus" :size="16" /> {{ __('New thread') }}</a>
        @elseif (auth()->guest())
            <a class="btn" href="{{ route('login') }}">{{ __('Log in to post') }}</a>
        @endif
    </div>

    @if ($subforums)
        <section class="block">
            <div class="bar forum-grid forum-head">
                <span>{{ __('Sub-forums') }}</span>
                <span>{{ __('Last post') }}</span>
                <span class="center">{{ __('Threads / Posts') }}</span>
            </div>
            @include('forum._node-rows', ['rows' => $subforums])
        </section>
    @endif

    <section class="block">
        <div class="bar">
            <x-icon name="list" :size="16" />
            <h2>{{ __('Threads') }}</h2>
            <span class="spacer"></span>
            <span class="muted">{{ trans_choice(':count thread|:count threads', $threads->total(), ['count' => forum_number($threads->total())]) }}</span>
        </div>
        <form class="filter-bar" method="get" action="{{ route('nodes.show', $node) }}" data-auto-submit>
            <label>{{ __('Show') }}
                <select name="show">
                    <option value="">{{ __('All threads') }}</option>
                    <option value="unanswered" @selected($filter['show'] === 'unanswered')>{{ __('Unanswered') }}</option>
                    @auth<option value="mine" @selected($filter['show'] === 'mine')>{{ __('My threads') }}</option>@endauth
                    @if ($node->allow_solutions)
                        <option value="unsolved" @selected($filter['show'] === 'unsolved')>{{ __('Not solved yet') }}</option>
                        <option value="solved" @selected($filter['show'] === 'solved')>{{ __('Solved') }}</option>
                    @endif
                </select>
            </label>
            @if ($filter['prefixes']->isNotEmpty())
                <label>{{ __('Prefix') }}
                    <select name="prefix">
                        <option value="">{{ __('Any') }}</option>
                        @foreach ($filter['prefixes'] as $option)
                            <option value="{{ $option->id }}" @selected($filter['prefix'] === $option->id)>{{ $option->title }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
            <label>{{ __('Sort by') }}
                <select name="sort">
                    <option value="">{{ __('Latest activity') }}</option>
                    <option value="new" @selected($filter['sort'] === 'new')>{{ __('Newest threads') }}</option>
                    <option value="replies" @selected($filter['sort'] === 'replies')>{{ __('Most replies') }}</option>
                    <option value="views" @selected($filter['sort'] === 'views')>{{ __('Most views') }}</option>
                </select>
            </label>
            <noscript><button class="btn btn-sm" type="submit">{{ __('Apply') }}</button></noscript>
            @unless ($filter['plain'])<a class="filter-clear" href="{{ route('nodes.show', $node) }}"><x-icon name="x" :size="13" /> {{ __('Clear') }}</a>@endunless
        </form>
        @if ($threads->isEmpty())
            <div class="empty">{{ $filter['plain'] ? __('There are no threads in this forum yet. Be the first to start one!') : __('No thread matches this choice.') }}</div>
        @else
            @include('forum._thread-rows', ['threads' => $threads, 'selectable' => $modActions ? 'thread-mod-form' : null, 'sections' => $filter['plain']])
        @endif
    </section>

    @if ($modActions)
        <form id="thread-mod-form" class="mod-bar" method="post" action="{{ route('mod.threads', $node) }}" data-mod-bar hidden>
            @csrf
            <span>{{ __('Selected threads') }}: <b data-count>0</b></span>
            <select class="input" name="action" data-mod-action required>
                @foreach ($modActions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <span data-for="move copy" hidden>
                <select class="input" name="node_id">
                    @foreach ($moveTargets as $item)
                        <option value="{{ $item['node']->id }}">{{ str_repeat('— ', $item['depth']) }}{{ $item['node']->title }}</option>
                    @endforeach
                </select>
            </span>
            <button class="btn btn-primary" type="submit" data-confirm="{{ __('Apply this action to the selected threads?') }}">{{ __('Apply') }}</button>
        </form>
    @endif

    {{ $threads->links() }}
@endsection
