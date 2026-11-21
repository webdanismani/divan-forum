@extends('layouts.app')

@section('title', $member->username)
@section('crumb', $member->username)

@section('content')
    <section class="block medium">
        <div class="profile-head">
            <x-avatar :user="$member" size="xl" :link="false" />
            <div class="info">
                <h1><x-username :user="$member" :link="false" /></h1>
                <div class="muted" style="margin-top: 6px">{{ $member->displayTitle() }}</div>
                <p class="muted" style="margin: 14px 0 0"><x-icon name="lock" :size="15" />
                    {{ $level === 'friends' ? __('This profile is only shown to the member\'s friends.') : ($level === 'members' ? __('Sign in to see this profile.') : __('This member keeps their profile private.')) }}
                </p>
            </div>
            <div class="row">
                @guest
                    <a class="btn btn-primary" href="{{ route('login') }}">{{ __('Log in') }}</a>
                @else
                    @include('members._friend-button')
                @endguest
            </div>
        </div>
    </section>
@endsection
