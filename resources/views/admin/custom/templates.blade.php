@extends('layouts.admin')

@section('title', __('Templates'))
@section('subtitle', __('Edit a copy of any page template. The package files stay untouched, so updates do not overwrite your changes.'))

@section('content')
    @if ($safeMode)
        <div class="flash info">{{ __('Safe mode is on: custom CSS, custom HTML, edited templates and add-ons are ignored.') }}</div>
    @endif

    @if ($overridden)
        <section class="block">
            <div class="block-head"><x-icon name="edit" :size="16" /><h2>{{ __('Templates you changed') }}</h2></div>
            <div class="file-rows">
                @foreach ($overridden as $file)
                    <a href="{{ route('admin.templates.edit', ['file' => $file]) }}">{{ $file }} <span class="badge amber" style="font-family: var(--font)">{{ __('Changed') }}</span></a>
                @endforeach
            </div>
        </section>
    @endif

    <section class="block">
        <div class="block-head"><x-icon name="list" :size="16" /><h2>{{ __('All templates') }}</h2><span class="spacer"></span><span class="muted">{{ count($files) }}</span></div>
        <form class="block-body row" method="get" action="{{ route('admin.templates') }}" style="border-bottom: 1px solid var(--border)">
            <input class="input" style="flex: 1" type="search" name="q" value="{{ $search }}" placeholder="threads/show, layouts/app…">
            <button class="btn" type="submit">{{ __('Search') }}</button>
        </form>
        <div class="file-rows">
            @forelse ($files as $file)
                <a href="{{ route('admin.templates.edit', ['file' => $file]) }}">{{ $file }}@if (in_array($file, $overridden, true)) <span class="badge amber" style="font-family: var(--font)">{{ __('Changed') }}</span>@endif</a>
            @empty
                <div class="empty">{{ __('No results found.') }}</div>
            @endforelse
        </div>
    </section>
@endsection
