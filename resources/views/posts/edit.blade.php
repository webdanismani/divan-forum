@extends('layouts.app')

@section('title', __('Edit post'))
@section('crumb', __('Edit post'))

@section('content')
    <div class="medium">
        <div class="page-head"><div class="titles"><h1>{{ __('Edit post') }}</h1><div class="desc">{{ $thread->title }}</div></div></div>

        <form class="block" method="post" action="{{ route('posts.update', $post) }}">
            @csrf @method('PUT')
            <div class="block-body">
                @if ($post->isFirstPost())
                    <div class="row" style="align-items: flex-start; flex-wrap: nowrap">
                        @if ($prefixes->isNotEmpty())
                            <div class="field" style="width: 170px; flex: none">
                                <label for="prefix_id">{{ __('Prefix') }}</label>
                                <select class="input" id="prefix_id" name="prefix_id">
                                    <option value="">{{ __('(none)') }}</option>
                                    @foreach ($prefixes as $prefix)
                                        <option value="{{ $prefix->id }}" @selected(old('prefix_id', $thread->prefix_id) == $prefix->id)>{{ $prefix->title }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                        <div class="field" style="flex: 1">
                            <label for="title">{{ __('Title') }}</label>
                            <input class="input" id="title" name="title" value="{{ old('title', $thread->title) }}" minlength="3" maxlength="150" required @if (setting('posting.title_case')) data-title-case @endif>
                        </div>
                    </div>
                    @if (app(\App\Forum\Tags::class)->enabled())
                        <div class="field">
                            <label for="tags">{{ __('Tags') }}</label>
                            <input class="input" id="tags" name="tags" value="{{ old('tags', $thread->tags->pluck('name')->implode(', ')) }}" maxlength="300">
                            <div class="hint">{{ __('Separate tags with commas, :count at most.', ['count' => app(\App\Forum\Tags::class)->limit()]) }}</div>
                        </div>
                    @endif
                @endif

                <div class="field">
                    <span class="label">{{ __('Message') }}</span>
                    <x-editor name="body" :value="$post->body" :rows="12" :node="$node" :post="$post" />
                </div>

                <div class="form-actions">
                    <button class="btn btn-primary" type="submit">{{ __('Save changes') }}</button>
                    <a class="btn" href="{{ route('posts.show', $post->id) }}">{{ __('Cancel') }}</a>
                </div>
            </div>
        </form>
    </div>
@endsection
