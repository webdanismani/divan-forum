@extends('layouts.app')

@section('title', __('Saved replies'))
@section('crumb', __('Saved replies'))

@section('content')
    <div class="medium stack">
        <div class="page-head" style="margin-bottom: 0">
            <div class="titles">
                <h1>{{ __('Saved replies') }}</h1>
                <div class="desc">{{ __('Texts you write often. In the editor, the button with the list icon puts one of them where the cursor is.') }}</div>
            </div>
            <span class="muted">{{ $replies->count() }} / {{ $limit }}</span>
        </div>

        @foreach ($replies as $reply)
            <form class="block" method="post" action="{{ route('replies.update', $reply->id) }}">
                @csrf @method('PUT')
                <div class="block-body">
                    <div class="field">
                        <label for="title-{{ $reply->id }}">{{ __('Name') }}</label>
                        <input class="input" id="title-{{ $reply->id }}" name="title" value="{{ $reply->title }}" maxlength="80" required>
                    </div>
                    <div class="field" style="margin-bottom: 0">
                        <label for="body-{{ $reply->id }}">{{ __('Text') }}</label>
                        <textarea class="input" id="body-{{ $reply->id }}" name="body" maxlength="5000" style="min-height: 90px" required>{{ $reply->body }}</textarea>
                    </div>
                </div>
                <div class="block-foot row">
                    <button class="btn btn-sm btn-primary" type="submit">{{ __('Save') }}</button>
                    <span class="spacer"></span>
                    <button class="btn btn-sm btn-danger" type="submit" form="delete-reply-{{ $reply->id }}" data-confirm="{{ __('Delete this saved reply?') }}"><x-icon name="trash" :size="14" /> {{ __('Delete') }}</button>
                </div>
            </form>
            <form id="delete-reply-{{ $reply->id }}" method="post" action="{{ route('replies.destroy', $reply->id) }}" hidden>@csrf @method('DELETE')</form>
        @endforeach

        @if ($replies->count() < $limit)
            <form class="block" method="post" action="{{ route('replies.store') }}">
                @csrf
                <div class="block-head"><x-icon name="plus" :size="16" /><h2>{{ __('Add a saved reply') }}</h2></div>
                <div class="block-body">
                    <div class="field">
                        <label for="new-title">{{ __('Name') }}</label>
                        <input class="input" id="new-title" name="title" value="{{ old('title') }}" maxlength="80" placeholder="{{ __('For example: Terms of sale') }}" required>
                        @error('title')<div class="error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field" style="margin-bottom: 0">
                        <label for="new-body">{{ __('Text') }}</label>
                        <textarea class="input" id="new-body" name="body" maxlength="5000" style="min-height: 110px" required>{{ old('body') }}</textarea>
                        <div class="hint">{{ __('BBCode can be used.') }}</div>
                        @error('body')<div class="error">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="block-foot"><button class="btn btn-primary" type="submit">{{ __('Add') }}</button></div>
            </form>
        @else
            <div class="flash info" style="margin: 0">{{ __('You can keep at most :count saved replies. Delete one to add another.', ['count' => $limit]) }}</div>
        @endif
    </div>
@endsection
