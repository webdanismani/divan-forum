@extends('layouts.app')

@section('title', __('Contact'))
@section('crumb', __('Contact'))

@section('content')
    <div class="medium" style="max-width: 640px">
        <div class="page-head"><div class="titles"><h1>{{ __('Contact') }}</h1><div class="desc">{{ __('Write to the administrators of the forum.') }}</div></div></div>

        <form class="block" method="post" action="{{ route('contact') }}">
            @csrf
            <div class="block-body">
                @guest
                    <div class="form-grid">
                        <div class="field">
                            <label for="name">{{ __('Your name') }}</label>
                            <input class="input" id="name" name="name" value="{{ old('name') }}" maxlength="80" required>
                            @error('name')<div class="error">{{ $message }}</div>@enderror
                        </div>
                        <div class="field">
                            <label for="email">{{ __('E-mail address') }}</label>
                            <input class="input" id="email" type="email" name="email" value="{{ old('email') }}" maxlength="255" required>
                            @error('email')<div class="error">{{ $message }}</div>@enderror
                        </div>
                    </div>
                @endguest
                <div class="field">
                    <label for="subject">{{ __('Subject') }}</label>
                    <input class="input" id="subject" name="subject" value="{{ old('subject') }}" maxlength="150" required>
                    @error('subject')<div class="error">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label for="body">{{ __('Message') }}</label>
                    <textarea class="input" id="body" name="body" maxlength="5000" style="min-height: 160px" required>{{ old('body') }}</textarea>
                    @error('body')<div class="error">{{ $message }}</div>@enderror
                </div>
                <x-captcha />
                <button class="btn btn-primary" type="submit">{{ __('Send message') }}</button>
            </div>
        </form>
    </div>
@endsection
