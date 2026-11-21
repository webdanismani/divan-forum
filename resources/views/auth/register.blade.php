@extends('layouts.app')

@section('title', __('Register'))
@section('crumb', __('Register'))

@section('content')
    <div class="narrow">
        <form class="block" method="post" action="{{ route('register') }}">
            @csrf
            <div class="block-head"><h2>{{ __('Create an account') }}</h2></div>
            <div class="block-body">
                <div class="field">
                    <label for="username">{{ __('Username') }}</label>
                    <input class="input" id="username" name="username" value="{{ old('username') }}" minlength="3" maxlength="30" autocomplete="username" required autofocus>
                    <div class="hint">{{ __('3-30 characters: letters, numbers, dashes and underscores.') }}</div>
                </div>
                <div class="field">
                    <label for="email">{{ __('E-mail address') }}</label>
                    <input class="input" id="email" type="email" name="email" value="{{ old('email', request()->query('email')) }}" autocomplete="email" required>
                </div>
                <div class="field">
                    <label for="password">{{ __('Password') }}</label>
                    <input class="input" id="password" type="password" name="password" autocomplete="new-password" minlength="8" required>
                </div>
                <div class="field">
                    <label for="password_confirmation">{{ __('Confirm password') }}</label>
                    <input class="input" id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" required>
                </div>
                <div class="field">
                    <label class="check"><input type="checkbox" name="terms" value="1" required> {{ __('I agree to follow the forum rules.') }}</label>
                </div>
                <x-captcha />
                <button class="btn btn-primary btn-block" type="submit">{{ __('Register') }}</button>
                @include('partials.social-login')
            </div>
            <div class="block-foot">{{ __('Already have an account?') }} <a href="{{ route('login') }}">{{ __('Log in') }}</a></div>
        </form>
    </div>
@endsection
