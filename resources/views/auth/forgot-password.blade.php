@extends('layouts.app')

@section('title', __('Reset password'))
@section('crumb', __('Reset password'))

@section('content')
    <div class="narrow">
        <form class="block" method="post" action="{{ route('password.email') }}">
            @csrf
            <div class="block-head"><h2>{{ __('Reset password') }}</h2></div>
            <div class="block-body">
                <p class="muted">{{ __('Enter the e-mail address of your account and we will send you a link to choose a new password.') }}</p>
                <div class="field">
                    <label for="email">{{ __('E-mail address') }}</label>
                    <input class="input" id="email" type="email" name="email" value="{{ old('email') }}" required autofocus>
                </div>
                <button class="btn btn-primary btn-block" type="submit">{{ __('Send reset link') }}</button>
            </div>
            <div class="block-foot"><a href="{{ route('login') }}">{{ __('Back to log in') }}</a></div>
        </form>
    </div>
@endsection
