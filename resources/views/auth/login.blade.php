@extends('layouts.app')

@section('title', __('Log in'))
@section('crumb', __('Log in'))

@section('content')
    <div class="narrow">
        <form class="block" method="post" action="{{ route('login') }}">
            @csrf
            <div class="block-head"><h2>{{ __('Log in') }}</h2></div>
            <div class="block-body">
                <div class="field">
                    <label for="login">{{ __('Username or e-mail') }}</label>
                    <input class="input" id="login" name="login" value="{{ old('login') }}" autocomplete="username" required autofocus>
                </div>
                <div class="field">
                    <label for="password">{{ __('Password') }}</label>
                    <input class="input" id="password" type="password" name="password" autocomplete="current-password" required>
                </div>
                <div class="field row">
                    <label class="check"><input type="checkbox" name="remember" value="1"> {{ __('Stay signed in') }}</label>
                    <span class="spacer"></span>
                    <a href="{{ route('password.request') }}">{{ __('Forgot your password?') }}</a>
                </div>
                <button class="btn btn-primary btn-block" type="submit">{{ __('Log in') }}</button>
                @include('partials.social-login')
            </div>
            @if (setting('registration.enabled'))
                <div class="block-foot">{{ __("Don't have an account?") }} <a href="{{ route('register') }}">{{ __('Register') }}</a></div>
            @endif
        </form>
    </div>
@endsection
