@extends('layouts.app')

@section('title', __('Account banned'))
@section('crumb', __('Account banned'))

@section('content')
    <div class="narrow">
        <div class="block">
            <div class="block-head"><h2>{{ __('Account banned') }}</h2></div>
            <div class="block-body">
                <p>{{ __('Your account has been banned from this forum.') }}</p>
                @if ($user->ban_reason)
                    <p><strong>{{ __('Reason') }}:</strong> {{ $user->ban_reason }}</p>
                @endif
                <p class="muted">
                    @if ($user->banned_until)
                        {{ __('The ban ends on :date.', ['date' => $user->banned_until->translatedFormat('j F Y H:i')]) }}
                    @else
                        {{ __('The ban is permanent.') }}
                    @endif
                </p>
                <form method="post" action="{{ route('logout') }}">@csrf
                    <button class="btn" type="submit">{{ __('Log out') }}</button>
                </form>
            </div>
        </div>
    </div>
@endsection
