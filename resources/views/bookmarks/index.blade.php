@extends('layouts.app')

@section('title', __('Bookmarks'))
@section('crumb', __('Bookmarks'))

@section('content')
    <div class="page-head"><div class="titles"><h1>{{ __('Bookmarks') }}</h1><div class="desc">{{ __('Posts you saved to find again later.') }}</div></div></div>

    <section class="block">
        @forelse ($bookmarks as $bookmark)
            @php $post = $bookmark->post; @endphp
            <div class="item">
                <x-avatar :user="$post->user" size="sm" />
                <div class="body">
                    <a class="title" href="{{ route('posts.show', $post->id) }}">{{ $post->thread->title }}</a>
                    <div class="snippet">{{ app(\App\Forum\BbCode\Renderer::class)->plain($post->body, 200) }}</div>
                    <div class="thread-meta">
                        <x-username :user="$post->user" /> <span>·</span> <a href="{{ route('nodes.show', $post->thread->node) }}">{{ $post->thread->node->title }}</a>
                        <span>·</span> <x-time :at="$bookmark->created_at" />
                    </div>
                </div>
                <form method="post" action="{{ route('posts.bookmark', $post) }}">@csrf
                    <button class="btn btn-sm btn-ghost btn-danger" type="submit" title="{{ __('Remove bookmark') }}"><x-icon name="trash" :size="14" /></button>
                </form>
            </div>
        @empty
            <div class="empty">{{ __('You have no bookmarks yet. Use the bookmark button under a post to save it.') }}</div>
        @endforelse
    </section>

    {{ $bookmarks->links() }}
@endsection
