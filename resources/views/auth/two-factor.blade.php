@extends('layouts.app')

@section('title', __('Two-step sign-in'))
@section('crumb', __('Two-step sign-in'))

@section('content')
    <div class="narrow">
        <form class="block" method="post" action="{{ route('two-factor.challenge') }}">
            @csrf
            <div class="block-head"><x-icon name="lock" :size="16" /><h2>{{ __('Two-step sign-in') }}</h2></div>
            <div class="block-body">
                <p class="muted">{{ __('Enter the 6-digit code from your authenticator app. If you cannot reach your phone, use one of your recovery codes.') }}</p>
                <div class="field">
                    <label for="code">{{ __('Code') }}</label>
                    <input class="input" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="40" required autofocus>
                    @error('code')<div class="error">{{ $message }}</div>@enderror
                </div>
                <button class="btn btn-primary btn-block" type="submit">{{ __('Log in') }}</button>
            </div>
            <div class="block-foot"><a href="{{ route('login') }}">{{ __('Start over') }}</a></div>
        </form>
    </div>
@endsection
