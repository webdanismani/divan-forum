@extends('layouts.app')

@section('title', __('Friends'))
@section('crumb', __('Friends'))

@section('content')
    <div class="medium stack">
        <div class="page-head" style="margin-bottom: 0"><div class="titles"><h1>{{ __('Friends') }}</h1></div></div>

        @if ($incoming->isNotEmpty())
            <section class="block">
                <div class="block-head"><x-icon name="bell" :size="16" /><h2>{{ __('Friend requests') }}</h2><span class="badge amber">{{ $incoming->count() }}</span></div>
                <ul class="side-list">
                    @foreach ($incoming as $member)
                        <li>
                            <x-avatar :user="$member" size="sm" />
                            <div class="meta" style="flex: 1"><x-username :user="$member" /><div class="muted small">{{ $member->displayTitle() }}</div></div>
                            <form method="post" action="{{ route('members.friend', $member) }}">@csrf <input type="hidden" name="action" value="add">
                                <button class="btn btn-sm btn-primary" type="submit">{{ __('Accept') }}</button>
                            </form>
                            <form method="post" action="{{ route('members.friend', $member) }}">@csrf <input type="hidden" name="action" value="remove">
                                <button class="btn btn-sm" type="submit">{{ __('Decline') }}</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <section class="block">
            <div class="block-head"><x-icon name="users" :size="16" /><h2>{{ __('My friends') }}</h2><span class="muted">{{ $friends->count() }}</span></div>
            @if ($friends->isEmpty())
                <div class="empty">{{ __('You have no friends here yet. Open a member\'s profile and press "Add friend".') }}</div>
            @else
                <ul class="side-list">
                    @foreach ($friends as $member)
                        <li>
                            <x-avatar :user="$member" size="sm" />
                            <div class="meta" style="flex: 1"><x-username :user="$member" /><div class="muted small">{{ $member->displayTitle() }}</div></div>
                            <form method="post" action="{{ route('members.friend', $member) }}" data-confirm="{{ __('Remove this member from your friends?') }}">@csrf <input type="hidden" name="action" value="remove">
                                <button class="btn btn-sm btn-ghost" type="submit">{{ __('Remove') }}</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        @if ($outgoing->isNotEmpty())
            <section class="block">
                <div class="block-head"><x-icon name="clock" :size="16" /><h2>{{ __('Requests you sent') }}</h2></div>
                <ul class="side-list">
                    @foreach ($outgoing as $member)
                        <li>
                            <x-avatar :user="$member" size="sm" />
                            <div class="meta" style="flex: 1"><x-username :user="$member" /></div>
                            <form method="post" action="{{ route('members.friend', $member) }}">@csrf <input type="hidden" name="action" value="remove">
                                <button class="btn btn-sm" type="submit">{{ __('Withdraw') }}</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
@endsection
