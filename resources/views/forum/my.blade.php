@extends('layouts.app')

@section('title', __(\App\Http\Controllers\MyController::TABS[$tab]))
@section('crumb', __('My content'))

@section('content')
    <div class="tabs">
        @foreach (\App\Http\Controllers\MyController::TABS as $key => $label)
            <a href="{{ route('my', $key === 'unread' ? [] : ['tab' => $key]) }}" @class(['active' => $tab === $key])>{{ __($label) }}</a>
        @endforeach
    </div>

    <section class="block">
        @if ($tab === 'posts')
            @forelse ($posts as $post)
                <div class="item">
                    <div class="body">
                        <a class="title" href="{{ route('posts.show', $post->id) }}">{{ $post->thread->title }}</a>
                        <div class="snippet">{{ app(\App\Forum\BbCode\Renderer::class)->plain($post->body, 240) }}</div>
                        <div class="thread-meta"><x-time :at="$post->created_at" /></div>
                    </div>
                </div>
            @empty
                <div class="empty">{{ __('You have not posted anything yet.') }}</div>
            @endforelse
        @elseif ($threads->isEmpty())
            <div class="empty">
                {{ match ($tab) {
                    'unread' => __('You are up to date: there is nothing you have not read.'),
                    'threads' => __('You have not started a thread yet.'),
                    default => __('You have not replied to anybody\'s thread yet.'),
                } }}
            </div>
        @else
            @include('forum._thread-rows', ['threads' => $threads, 'showNode' => true])
        @endif
    </section>

    {{ ($posts ?? $threads)->links() }}

    @if ($tab === 'unread' && $threads->isNotEmpty())
        <form method="post" action="{{ route('forum.mark-read') }}" style="margin-top: 12px">@csrf
            <button class="btn" type="submit"><x-icon name="check" :size="16" /> {{ __('Mark forums read') }}</button>
        </form>
    @endif
@endsection
