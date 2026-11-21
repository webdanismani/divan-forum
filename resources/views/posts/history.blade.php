@extends('layouts.app')

@section('title', __('Edit history'))
@section('crumb', __('Edit history'))

@section('content')
    <div class="medium">
        <div class="page-head">
            <div class="titles"><h1>{{ __('Edit history') }}</h1><div class="desc">{{ $thread->title }}</div></div>
            <a class="btn" href="{{ route('posts.show', $post->id) }}">{{ __('Back to the post') }}</a>
        </div>

        <section class="block">
            <div class="block-head"><h2>{{ __('Current version') }}</h2><span class="spacer"></span>@if ($post->edited_at)<span class="muted"><x-time :at="$post->edited_at" /></span>@endif</div>
            <pre class="history-text" style="border-top: 0">{{ $post->body }}</pre>
        </section>

        @forelse ($edits as $edit)
            <section class="block">
                <div class="block-head">
                    <h2>{{ __('Before the edit of :date', ['date' => $edit->created_at->translatedFormat('j M Y H:i')]) }}</h2>
                    <span class="spacer"></span>
                    <span class="muted">{{ __('edited by :name', ['name' => $edit->editor?->username ?? '—']) }}</span>
                </div>
                <pre class="history-text" style="border-top: 0">{{ $edit->old_body }}</pre>
            </section>
        @empty
            <div class="block"><div class="empty">{{ __('This post has not been edited.') }}</div></div>
        @endforelse
    </div>
@endsection
