@extends('layouts.app')

@section('title', __('Approval queue'))
@section('crumb', __('Moderation'))

@section('content')
    <div class="page-head"><div class="titles"><h1>{{ __('Moderation') }}</h1></div></div>

    <nav class="tabs">
        @if (forum_can('mod.manageReports'))
            <a href="{{ route('mod.reports') }}">{{ __('Open reports') }}</a>
            <a href="{{ route('mod.reports', ['status' => 'closed']) }}">{{ __('Closed reports') }}</a>
        @endif
        <a class="active" href="{{ route('mod.queue') }}">{{ __('Approval queue') }}</a>
    </nav>

    <section class="block">
        <div class="block-head"><h2>{{ __('Threads awaiting approval') }}</h2></div>
        @forelse ($threads as $thread)
            <div class="item">
                <x-avatar :user="$thread->user" />
                <div class="body">
                    <a class="title" href="{{ route('threads.show', $thread) }}">{{ $thread->title }}</a>
                    <div class="snippet">{{ app(\App\Forum\BbCode\Renderer::class)->plain($thread->firstPost?->body ?? '', 240) }}</div>
                    <div class="thread-meta"><x-username :user="$thread->user" /> <span>·</span> <x-time :at="$thread->created_at" /> <span>·</span> {{ $thread->node->title }}</div>
                </div>
                <form class="row" method="post" action="{{ route('threads.moderate', $thread) }}">@csrf
                    <button class="btn btn-sm btn-primary" name="action" value="approve">{{ __('Approve') }}</button>
                    <button class="btn btn-sm btn-danger" name="action" value="delete">{{ __('Delete') }}</button>
                </form>
            </div>
        @empty
            <div class="empty">{{ __('Nothing is waiting for approval.') }}</div>
        @endforelse
    </section>

    <section class="block">
        <div class="block-head"><h2>{{ __('Replies awaiting approval') }}</h2></div>
        @forelse ($posts as $post)
            <div class="item">
                <x-avatar :user="$post->user" />
                <div class="body">
                    <a class="title" href="{{ route('posts.show', $post->id) }}">{{ $post->thread->title }}</a>
                    <div class="snippet">{{ app(\App\Forum\BbCode\Renderer::class)->plain($post->body, 240) }}</div>
                    <div class="thread-meta"><x-username :user="$post->user" /> <span>·</span> <x-time :at="$post->created_at" /></div>
                </div>
                <div class="row">
                    <form method="post" action="{{ route('posts.moderate', $post) }}">@csrf
                        <button class="btn btn-sm btn-primary" name="action" value="approve">{{ __('Approve') }}</button>
                    </form>
                    <form method="post" action="{{ route('posts.destroy', $post) }}">@csrf @method('DELETE')
                        <button class="btn btn-sm btn-danger" type="submit">{{ __('Delete') }}</button>
                    </form>
                </div>
            </div>
        @empty
            <div class="empty">{{ __('Nothing is waiting for approval.') }}</div>
        @endforelse
    </section>
@endsection
