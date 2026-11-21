{{-- News cards: the newest threads of the forum chosen in Admin > Home page. --}}
@php
    $home = app(\App\Forum\Home::class);
    $newsOptions = $home->newsOptions();
    $newsThreads = $home->news($viewable);
    $newsNode = app(\App\Forum\NodeTree::class)->get($newsOptions['node_id']);
@endphp
@if ($newsThreads->isNotEmpty())
    <section class="block home-news">
        <div class="block-head">
            <x-icon name="message" :size="16" /><h2>{{ $newsOptions['title'] !== '' ? $newsOptions['title'] : __('News') }}</h2>
            <span class="spacer"></span>
            <a class="more" href="{{ $newsNode && $viewable->has($newsNode->id) ? route('nodes.show', $newsNode) : route('whats-new') }}">{{ __('See all') }} <x-icon name="arrow-right" :size="13" /></a>
        </div>
        <div class="news-grid" style="--news-count: {{ min(4, $newsThreads->count()) }}">
            @foreach ($newsThreads as $thread)
                <a class="news-card" href="{{ route('threads.show', $thread) }}">
                    <span class="news-pic">
                        @if ($thread->home_picture)
                            <img src="{{ $thread->home_picture }}" alt="" loading="lazy">
                        @else
                            <x-icon name="image" :size="30" />
                        @endif
                        @if ($thread->prefix)<span class="news-tag prefix {{ $thread->prefix->color }}">{{ $thread->prefix->title }}</span>@endif
                    </span>
                    <b class="news-title">{{ $thread->title }}</b>
                    <span class="news-meta">
                        <span><x-icon name="calendar" :size="13" /> <x-time :at="$thread->created_at" /></span>
                        <span><x-icon name="eye" :size="13" /> {{ \App\Forum\Home::short($thread->view_count) }}</span>
                    </span>
                </a>
            @endforeach
        </div>
    </section>
@endif
