@extends('layouts.app')

@section('title', $conversation->title)
@section('crumb', $conversation->title)

@section('content')
    <div class="page-head">
        <div class="titles">
            <h1>{{ $conversation->title }}</h1>
            <div class="thread-meta" style="margin-top: 6px">
                <x-icon name="users" :size="14" />
                @foreach ($conversation->participants as $participant)
                    <x-username :user="$participant" />@if (! $loop->last),@endif
                @endforeach
            </div>
        </div>
        <div class="row">
            <a class="btn" href="{{ route('conversations.index') }}">{{ __('All conversations') }}</a>
            <form method="post" action="{{ route('conversations.unread', $conversation) }}">@csrf
                <button class="btn" type="submit">{{ __('Mark as unread') }}</button>
            </form>
            <form method="post" action="{{ route('conversations.leave', $conversation) }}" data-confirm="{{ __('Leave this conversation? You will no longer receive its messages.') }}">
                @csrf @method('DELETE')
                <button class="btn btn-danger" type="submit">{{ __('Leave') }}</button>
            </form>
        </div>
    </div>

    {{ $messages->links() }}

    @foreach ($messages as $message)
        <article class="block post" id="message-{{ $message->id }}">
            <header class="post-bar"><x-icon name="clock" :size="13" /> <x-time :at="$message->created_at" /></header>
            <div class="post-cols">
            <aside class="post-user">
                <x-avatar :user="$message->user" size="lg" />
                <div>
                    <x-username :user="$message->user" />
                    @if ($message->user)<div class="title">{{ $message->user->displayTitle() }}</div>@endif
                </div>
            </aside>
            <div class="post-main">
                <div class="post-body bb">{!! $message->body_html !!}</div>
                @if (($seenAfter ?? null) === $message->id && $seenBy->isNotEmpty())
                    <div class="seen-line"><x-icon name="check" :size="13" /> {{ __('Seen') }}: {{ $seenBy->map(fn ($p) => $p->username)->join(', ') }}</div>
                @endif
            </div>
            </div>
        </article>
    @endforeach

    {{ $messages->links() }}

    <section class="block" id="reply" style="margin-top: 16px">
        <div class="block-head"><h2>{{ __('Write a reply') }}</h2></div>
        <form class="block-body" method="post" action="{{ route('conversations.reply', $conversation) }}">
            @csrf
            <x-editor name="body" />
            <div class="form-actions" style="margin-top: 12px">
                <button class="btn btn-primary" type="submit">{{ __('Send reply') }}</button>
            </div>
        </form>
    </section>
@endsection
