@extends('layouts.app')

@section('title', __('Conversations'))
@section('crumb', __('Conversations'))

@section('content')
    <div class="page-head">
        <div class="titles"><h1>{{ __('Conversations') }}</h1><div class="desc">{{ __('Private messages between you and other members.') }}</div></div>
        <form class="row" method="get" action="{{ route('conversations.index') }}" style="gap: 6px; flex-wrap: nowrap">
            <input class="input" type="search" name="q" value="{{ $search }}" placeholder="{{ __('Search conversations…') }}" aria-label="{{ __('Search conversations…') }}" style="width: 220px">
            <button class="btn" type="submit" aria-label="{{ __('Search') }}"><x-icon name="search" :size="16" /></button>
        </form>
        @if (forum_can('conversation.start'))
            <a class="btn btn-primary" href="{{ route('conversations.create') }}"><x-icon name="plus" :size="16" /> {{ __('New conversation') }}</a>
        @endif
    </div>

    @if ($quota > 0)
        <div @class(['block', 'quota', 'warn' => $percent >= 80, 'full' => $percent >= 100])>
            <div class="quota-text">
                <span><x-icon name="mail" :size="15" /> {{ __('Inbox') }}: <b>{{ forum_number($used) }} / {{ forum_number($quota) }}</b> {{ __('conversations') }}</span>
                <span>{{ app()->getLocale() === 'tr' ? '%'.$percent : $percent.'%' }}</span>
            </div>
            <div class="quota-bar"><i style="width: {{ $percent }}%"></i></div>
            @if ($percent >= 100)
                <div class="quota-note">{{ __('Your inbox is full: you cannot start conversations and nobody can start one with you. Open an old conversation and leave it to make room.') }}</div>
            @elseif ($percent >= 80)
                <div class="quota-note">{{ __('Your inbox is almost full. Leave conversations you no longer need.') }}</div>
            @endif
        </div>
    @endif

    <form id="conversation-bulk" method="post" action="{{ route('conversations.bulk') }}">@csrf</form>
    <section class="block">
        @if ($conversations->isNotEmpty())
            <div class="bulk-bar">
                <label class="check"><input type="checkbox" data-select-all="conversation-bulk"> {{ __('Select all') }}</label>
                <span class="spacer"></span>
                <select class="input" name="action" form="conversation-bulk" aria-label="{{ __('With the selected') }}">
                    <option value="read">{{ __('Mark as read') }}</option>
                    <option value="unread">{{ __('Mark as unread') }}</option>
                    <option value="leave">{{ __('Leave the conversations') }}</option>
                </select>
                <button class="btn btn-sm" type="submit" form="conversation-bulk" data-confirm="{{ __('Apply this to the selected conversations?') }}">{{ __('Apply') }}</button>
            </div>
        @endif
        @forelse ($conversations as $conversation)
            @php
                $unread = ! $conversation->last_read_at || $conversation->last_message_at?->gt($conversation->last_read_at);
                $others = $conversation->participants->where('id', '!=', auth()->id());
            @endphp
            <div @class(['item', 'unread' => $unread])>
                <input class="select-box" type="checkbox" name="ids[]" value="{{ $conversation->id }}" form="conversation-bulk" aria-label="{{ $conversation->title }}">
                <x-avatar :user="$others->first() ?? auth()->user()" />
                <div class="body">
                    <a class="title" href="{{ route('conversations.show', $conversation) }}">{{ $conversation->title }}</a>
                    @if ($unread)<span class="badge blue">{{ __('New') }}</span>@endif
                    <div class="thread-meta">
                        <span>{{ __('With') }}: {{ $others->pluck('username')->join(', ') ?: '—' }}</span>
                        <span>·</span>
                        <span>{{ trans_choice(':count message|:count messages', $conversation->message_count, ['count' => $conversation->message_count]) }}</span>
                    </div>
                </div>
                <div class="thread-last">
                    <span class="when"><x-time :at="$conversation->last_message_at" /></span>
                    <span class="muted">{{ $conversation->lastMessageUser?->username }}</span>
                </div>
            </div>
        @empty
            <div class="empty">{{ $search !== '' ? __('No conversation matches your search.') : __('You have no conversations yet.') }}</div>
        @endforelse
    </section>

    {{ $conversations->links() }}
@endsection
