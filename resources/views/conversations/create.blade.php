@extends('layouts.app')

@section('title', __('New conversation'))
@section('crumb', __('New conversation'))

@section('content')
    <div class="medium">
        <div class="page-head"><div class="titles"><h1>{{ __('New conversation') }}</h1></div></div>

        @if ($full ?? false)
            <div class="flash error">{{ __('Your inbox is full. Leave old conversations to make room before starting a new one.') }} <a href="{{ route('conversations.index') }}">{{ __('Conversations') }}</a></div>
        @endif
        <form class="block" method="post" action="{{ route('conversations.store') }}">
            @csrf
            <div class="block-body">
                <div class="field">
                    <label for="recipients">{{ __('Recipients') }}</label>
                    <input class="input" id="recipients" name="recipients" value="{{ old('recipients', $to) }}" required>
                    <div class="hint">{{ __('Usernames separated by commas.') }}</div>
                    @error('recipients')<div class="error">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label for="title">{{ __('Title') }}</label>
                    <input class="input" id="title" name="title" value="{{ old('title') }}" minlength="3" maxlength="150" required>
                </div>
                <div class="field">
                    <span class="label">{{ __('Message') }}</span>
                    <x-editor name="body" />
                </div>
                <div class="form-actions">
                    <button class="btn btn-primary" type="submit">{{ __('Start conversation') }}</button>
                    <a class="btn" href="{{ route('conversations.index') }}">{{ __('Cancel') }}</a>
                </div>
            </div>
        </form>
    </div>
@endsection
