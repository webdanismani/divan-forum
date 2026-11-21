@extends('layouts.app')

@section('title', __('Search'))
@section('crumb', __('Search'))

@section('content')
    <div class="page-head"><div class="titles"><h1>{{ __('Search') }}</h1></div></div>

    <form class="block" action="{{ route('search') }}" method="get">
        <div class="block-body row">
            <input class="input" style="flex: 1; min-width: 200px" type="search" name="q" value="{{ $query }}" placeholder="{{ __('Search threads and posts…') }}" minlength="3" required autofocus>
            <button class="btn btn-primary" type="submit"><x-icon name="search" :size="16" /> {{ __('Search') }}</button>
        </div>
        <div class="block-foot row">
            <select class="input" name="node" style="width: auto; max-width: 240px" aria-label="{{ __('Forum') }}">
                <option value="">{{ __('All forums') }}</option>
                @foreach ($forums as $item)
                    <option value="{{ $item['node']->id }}" @selected($filters['node'] === $item['node']->id)>{{ str_repeat('— ', $item['depth']) }}{{ $item['node']->title }}</option>
                @endforeach
            </select>
            <input class="input" name="author" value="{{ $filters['author'] }}" placeholder="{{ __('Author') }}" maxlength="30" style="width: 150px">
            <select class="input" name="days" style="width: auto" aria-label="{{ __('Date') }}">
                @foreach (['' => __('Any time'), 1 => __('Last 24 hours'), 7 => __('Last week'), 30 => __('Last month'), 365 => __('Last year')] as $value => $label)
                    <option value="{{ $value }}" @selected((string) $filters['days'] === (string) $value)>{{ $label }}</option>
                @endforeach
            </select>
            <select class="input" name="sort" style="width: auto" aria-label="{{ __('Order') }}">
                <option value="newest" @selected($filters['sort'] === 'newest')>{{ __('Newest first') }}</option>
                <option value="relevance" @selected($filters['sort'] === 'relevance')>{{ __('Most relevant first') }}</option>
            </select>
            <label class="check"><input type="checkbox" name="titles" value="1" @checked($filters['titles'])> {{ __('Titles only') }}</label>
        </div>
    </form>

    @if ($query !== '' && ! $results)
        <div class="block"><div class="empty">{{ __('Please enter at least 3 characters.') }}</div></div>
    @elseif ($results)
        <section class="block">
            <div class="block-head">
                <h2>{{ trans_choice(':count result|:count results', $results->total(), ['count' => number_format($results->total())]) }}</h2>
            </div>
            @forelse ($results as $post)
                <div class="item">
                    <x-avatar :user="$post->user" />
                    <div class="body">
                        <a class="title" href="{{ route('posts.show', $post->id) }}">{{ $post->thread->title }}</a>
                        <div class="snippet">{{ app(\App\Forum\BbCode\Renderer::class)->plain($post->body, 260) }}</div>
                        <div class="thread-meta">
                            <x-username :user="$post->user" /> <span>·</span> <x-time :at="$post->created_at" /> <span>·</span>
                            <a href="{{ route('nodes.show', $post->thread->node) }}">{{ $post->thread->node->title }}</a>
                        </div>
                    </div>
                </div>
            @empty
                <div class="empty">{{ __('No results found.') }}</div>
            @endforelse
        </section>
        {{ $results->links() }}
    @endif
@endsection
