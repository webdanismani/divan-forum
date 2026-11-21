@extends('layouts.app')

@section('title', __('Members'))
@section('crumb', __('Members'))

@section('content')
    <div class="page-head">
        <div class="titles"><h1>{{ __('Members') }}</h1></div>
        <form class="row" action="{{ route('members.index') }}" method="get">
            <input type="hidden" name="sort" value="{{ $sort }}">
            <input class="input" style="width: 220px" type="search" name="q" value="{{ $search }}" placeholder="{{ __('Find a member…') }}">
            <button class="btn" type="submit"><x-icon name="search" :size="16" /></button>
        </form>
    </div>

    <nav class="tabs">
        @foreach (['posts' => __('Most posts'), 'reactions' => __('Highest reaction score'), 'newest' => __('Newest members'), 'online' => __('Recently active')] as $key => $label)
            <a href="{{ route('members.index', ['sort' => $key] + ($search !== '' ? ['q' => $search] : [])) }}" @class(['active' => $sort === $key])>{{ $label }}</a>
        @endforeach
    </nav>

    @if ($members->isEmpty())
        <div class="block"><div class="empty">{{ __('No members found.') }}</div></div>
    @else
        <div class="member-grid">
            @foreach ($members as $member)
                <div class="block member-card" style="margin: 0">
                    <x-avatar :user="$member" />
                    <div class="meta">
                        <x-username :user="$member" class="truncate" />
                        @if ($member->isOnline() && app(\App\Forum\Privacy::class)->showsOnline(auth()->user(), $member))<x-online :user="$member" compact />@endif
                        <div class="truncate">{{ $member->displayTitle() }}</div>
                        <div>{{ __('Posts') }}: <b>{{ number_format($member->post_count) }}</b> · {{ __('Score') }}: <b>{{ number_format($member->reaction_score) }}</b></div>
                    </div>
                </div>
            @endforeach
        </div>
        {{ $members->links() }}
    @endif
@endsection
