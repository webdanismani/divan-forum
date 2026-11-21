@extends('layouts.app')

@section('title', __('Confirm your e-mail address'))
@section('crumb', __('Confirm your e-mail address'))

@section('content')
    <div class="narrow">
        <section class="block">
            <div class="block-head"><x-icon name="mail" :size="16" /><h2>{{ __('Confirm your e-mail address') }}</h2></div>
            <div class="block-body">
                <p>{{ __('We have sent a confirmation link to :email.', ['email' => $user->email]) }}</p>
                <p class="muted">{{ __('Open the message and follow the link to start posting. It can take a few minutes to arrive; check your spam folder as well.') }}</p>
                <form method="post" action="{{ route('verification.send') }}">@csrf
                    <button class="btn btn-primary btn-block" type="submit">{{ __('Send the link again') }}</button>
                </form>
            </div>
            <div class="block-foot">{{ __('Wrong address?') }} <a href="{{ route('account.edit') }}">{{ __('Change it in your account settings') }}</a></div>
        </section>
    </div>
@endsection
