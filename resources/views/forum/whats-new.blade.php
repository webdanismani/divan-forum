@extends('layouts.app')

@section('title', __("What's new"))
@section('crumb', __("What's new"))

@section('content')
    <section class="block">
        <div class="bar">
            <x-icon name="bolt" :size="16" />
            <h2>{{ __("What's new") }}</h2>
            <span class="muted">— {{ __('The most recently active threads across the forums.') }}</span>
        </div>
        @if ($threads->isEmpty())
            <div class="empty">{{ __('No posts yet') }}</div>
        @else
            @include('forum._thread-rows', ['threads' => $threads, 'showNode' => true])
        @endif
    </section>

    {{ $threads->links() }}
@endsection
