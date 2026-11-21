@extends('layouts.app')

@section('title', __('Alerts'))
@section('crumb', __('Alerts'))

@section('content')
    <div class="page-head">
        <div class="titles"><h1>{{ __('Alerts') }}</h1></div>
        @if ($alerts->contains(fn ($alert) => $alert->read_at === null))
            <form method="post" action="{{ route('notifications.read-all') }}">@csrf
                <button class="btn" type="submit"><x-icon name="check" :size="16" /> {{ __('Mark all as read') }}</button>
            </form>
        @endif
    </div>

    <section class="block">
        @forelse ($alerts as $alert)
            @php
                $data = $alert->data;
                $text = \App\Forum\AlertText::text($data);
                $icon = \App\Forum\AlertText::icon($data);
            @endphp
            <a @class(['item', 'unread' => $alert->read_at === null]) href="{{ route('notifications.open', $alert->id) }}" style="color: inherit; text-decoration: none">
                <span class="node-icon" style="width: 36px; height: 36px"><x-icon :name="$icon" :size="17" /></span>
                <div class="body">
                    <div>{{ $text }}</div>
                    <div class="thread-meta"><x-time :at="$alert->created_at" /></div>
                </div>
            </a>
        @empty
            <div class="empty">{{ __('You have no alerts.') }}</div>
        @endforelse
    </section>

    {{ $alerts->links() }}
@endsection
