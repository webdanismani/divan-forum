@extends('layouts.app')

@section('title', $page->title)
@section('crumb', $page->title)
@section('description', app(\App\Forum\BbCode\Renderer::class)->plain($page->body, 160))

@section('content')
    <div class="medium">
        <div class="page-head">
            <div class="titles"><h1>{{ $page->title }} @unless ($page->is_published)<span class="badge amber" style="vertical-align: 3px">{{ __('Draft') }}</span>@endunless</h1></div>
            @can('admin')
                <a class="btn" href="{{ route('admin.pages.edit', $page) }}"><x-icon name="edit" :size="16" /> {{ __('Edit') }}</a>
            @endcan
        </div>
        <section class="block">
            <div class="page-body bb">{!! $page->body_html ?? app(\App\Forum\BbCode\Renderer::class)->render($page->body) !!}</div>
        </section>
    </div>
@endsection
