@extends('layouts.app')

@section('title', __('Tags'))
@section('crumb', __('Tags'))

@section('content')
    <div class="page-head"><div class="titles"><h1>{{ __('Tags') }}</h1><div class="desc">{{ __('Browse threads by subject.') }}</div></div></div>

    <section class="block">
        @if ($tags->isEmpty())
            <div class="empty">{{ __('No tags have been used yet.') }}</div>
        @else
            <div class="block-body tag-list">
                @foreach ($tags as $tag)
                    <a class="tag" href="{{ route('tags.show', $tag) }}"><x-icon name="tag" :size="12" /> {{ $tag->name }} <small>{{ forum_number($tag->use_count) }}</small></a>
                @endforeach
            </div>
        @endif
    </section>
@endsection
