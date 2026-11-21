@extends('layouts.app')

@section('title', __('Reset password'))
@section('crumb', __('Reset password'))

@section('content')
    <div class="narrow">
        <form class="block" method="post" action="{{ route('password.update') }}">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <div class="block-head"><h2>{{ __('Choose a new password') }}</h2></div>
            <div class="block-body">
                <div class="field">
                    <label for="email">{{ __('E-mail address') }}</label>
                    <input class="input" id="email" type="email" name="email" value="{{ old('email', $email) }}" required>
                </div>
                <div class="field">
                    <label for="password">{{ __('New password') }}</label>
                    <input class="input" id="password" type="password" name="password" autocomplete="new-password" minlength="8" required autofocus>
                </div>
                <div class="field">
                    <label for="password_confirmation">{{ __('Confirm password') }}</label>
                    <input class="input" id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" required>
                </div>
                <button class="btn btn-primary btn-block" type="submit">{{ __('Save new password') }}</button>
            </div>
        </form>
    </div>
@endsection
