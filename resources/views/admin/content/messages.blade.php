@extends('layouts.admin')

@section('title', __('Contact messages'))
@section('subtitle', __('What visitors sent through the contact form.'))

@section('content')
    <section class="block">
        @forelse ($messages as $message)
            <div class="item">
                <div class="body">
                    <div class="title">{{ $message->subject }}</div>
                    <div class="thread-meta">
                        <b>{{ $message->name }}</b> <span>·</span> <a href="mailto:{{ $message->email }}">{{ $message->email }}</a>
                        <span>·</span> {{ $message->created_at->translatedFormat('j M Y H:i') }}
                        @if ($message->ip)<span>·</span> <a href="{{ route('admin.bans', ['ip' => $message->ip]) }}">{{ $message->ip }}</a>@endif
                    </div>
                    <div class="snippet" style="white-space: pre-line; margin-top: 6px">{{ $message->body }}</div>
                </div>
                <form method="post" action="{{ route('admin.messages.delete', $message) }}" data-confirm="{{ __('Delete this message?') }}">@csrf @method('DELETE')
                    <button class="btn btn-sm btn-ghost btn-danger" type="submit" title="{{ __('Delete') }}"><x-icon name="trash" :size="14" /></button>
                </form>
            </div>
        @empty
            <div class="empty">{{ __('No messages yet.') }}</div>
        @endforelse
    </section>

    {{ $messages->links() }}
@endsection
