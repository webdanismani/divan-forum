@extends('layouts.app')

@section('title', __('Tag: :name', ['name' => $tag->name]))
@section('crumb', $tag->name)

@section('content')
    <div class="page-head">
        <div class="titles">
            <h1><x-icon name="tag" :size="19" /> {{ $tag->name }}</h1>
            <div class="desc">{{ trans_choice(':count thread|:count threads', $threads->total(), ['count' => forum_number($threads->total())]) }}</div>
        </div>
        <a class="btn" href="{{ route('tags.index') }}">{{ __('All tags') }}</a>
    </div>

    <section class="block">
        @if ($threads->isEmpty())
            <div class="empty">{{ __('There are no threads with this tag that you can see.') }}</div>
        @else
            @include('forum._thread-rows', ['threads' => $threads, 'showNode' => true])
        @endif
    </section>

    {{ $threads->links() }}
@endsection
