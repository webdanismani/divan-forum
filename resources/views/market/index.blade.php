@extends('layouts.app')

@section('title', __('Marketplace'))
@section('crumb', __('Marketplace'))
@section('description', __('Listings from the members of :board: for sale, wanted and services.', ['board' => setting('board.name')]))

@section('content')
    @php $types = \App\Models\Listing::TYPES; $states = \App\Models\Listing::STATES; @endphp
    <div class="page-head">
        <div class="titles">
            <h1>{{ __('Marketplace') }}</h1>
            <div class="desc">{{ __('Listings from the members: for sale, wanted and services.') }}</div>
        </div>
        @if ($forums->count() === 1)
            <a class="btn btn-primary" href="{{ route('threads.create', $forums->first()) }}"><x-icon name="edit" :size="16" /> {{ __('Post a listing') }}</a>
        @elseif ($forums->isNotEmpty())
            <details class="menu">
                <summary class="btn btn-primary"><x-icon name="edit" :size="16" /> {{ __('Post a listing') }} <x-icon name="chevron-down" :size="14" /></summary>
                <div class="menu-panel">
                    @foreach ($forums as $forum)<a href="{{ route('threads.create', $forum) }}">{{ $forum->title }}</a>@endforeach
                </div>
            </details>
        @endif
    </div>

    <form class="block" method="get" action="{{ route('market.index') }}">
        <div class="block-body row">
            <input class="input" style="flex: 2; min-width: 180px" type="search" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="80" placeholder="{{ __('Search listings…') }}">
            <select class="input" style="width: auto" name="type" aria-label="{{ __('Type') }}">
                <option value="">{{ __('All types') }}</option>
                @foreach ($types as $key => $label)<option value="{{ $key }}" @selected(($filters['type'] ?? '') === $key)>{{ __($label) }}</option>@endforeach
            </select>
            @if ($forums->count() > 1)
                <select class="input" style="width: auto" name="forum" aria-label="{{ __('Forum') }}">
                    <option value="">{{ __('All categories') }}</option>
                    @foreach ($forums as $forum)<option value="{{ $forum->id }}" @selected(($filters['forum'] ?? '') == $forum->id)>{{ $forum->title }}</option>@endforeach
                </select>
            @endif
            <input class="input" style="width: 110px" type="number" name="min" value="{{ $filters['min'] ?? '' }}" min="0" placeholder="{{ __('Min. price') }}">
            <input class="input" style="width: 110px" type="number" name="max" value="{{ $filters['max'] ?? '' }}" min="0" placeholder="{{ __('Max. price') }}">
            <label class="check"><input type="checkbox" name="status" value="all" @checked(($filters['status'] ?? '') === 'all')> {{ __('Also sold and closed') }}</label>
            <button class="btn btn-primary" type="submit"><x-icon name="search" :size="15" /> {{ __('Filter') }}</button>
        </div>
    </form>

    @if ($forums->isEmpty())
        <div class="block"><div class="empty">{{ __('There is no marketplace forum yet.') }}</div></div>
    @else
        @if ($featured->isNotEmpty() && $listings->currentPage() === 1)
            <section class="block">
                <div class="block-head"><x-icon name="star" :size="16" /><h2>{{ __('Showcase') }}</h2></div>
                <div class="showcase">
                    @foreach ($featured as $listing)
                        <a class="showcase-card" href="{{ route('threads.show', $listing->thread) }}">
                            <span class="badge blue">{{ __($types[$listing->type] ?? 'For sale') }}</span>
                            <b>{{ $listing->thread->title }}</b>
                            <span class="listing-price sm">{{ $listing->price !== null ? forum_money($listing->price) : __('Ask for the price') }}</span>
                            <span class="muted small">{{ $listing->thread->user?->username }}</span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="block">
            <div class="thead market-grid">
                <span>{{ __('Listing') }}</span>
                <span>{{ __('Price') }}</span>
                <span>{{ __('Seller') }}</span>
                <span>{{ __('Last post') }}</span>
            </div>
            @forelse ($listings as $listing)
                @php $thread = $listing->thread; $score = $thread->user ? \App\Forum\Market::score($thread->user) : null; @endphp
                <div @class(['market-row', 'market-grid', 'hl' => $listing->isFeatured(), 'dim' => $listing->status !== 'open'])>
                    <div style="min-width: 0">
                        <span class="badge blue">{{ __($types[$listing->type] ?? 'For sale') }}</span>
                        @if ($listing->status !== 'open')<span class="badge {{ $states[$listing->status][1] ?? '' }}">{{ __($states[$listing->status][0] ?? 'Closed') }}</span>@endif
                        <a class="thread-title" href="{{ route('threads.show', $thread) }}">{{ $thread->title }}</a>
                        <div class="thread-meta"><a href="{{ route('nodes.show', $thread->node) }}">{{ $thread->node->title }}</a> <span>·</span> {{ trans_choice(':count reply|:count replies', $thread->reply_count, ['count' => forum_number($thread->reply_count)]) }}</div>
                    </div>
                    <div class="listing-price sm">{{ $listing->price !== null ? forum_money($listing->price) : '—' }}</div>
                    <div style="min-width: 0">
                        <x-username :user="$thread->user" class="truncate" />
                        <div class="muted small">@if ($score !== null){{ app()->getLocale() === 'tr' ? '%'.$score : $score.'%' }} · +{{ $thread->user->trade_positive }} / −{{ $thread->user->trade_negative }}@else{{ __('No feedback') }}@endif</div>
                    </div>
                    <div class="muted small"><x-time :at="$thread->last_post_at" /></div>
                </div>
            @empty
                <div class="empty">{{ __('No listings match these filters.') }}</div>
            @endforelse
        </section>

        {{ $listings->links() }}
    @endif
@endsection
