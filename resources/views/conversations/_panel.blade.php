{{-- Latest conversations for the pop-over in the header. --}}
@if (($quota ?? 0) > 0 && $percent >= 80)
    <div @class(['pop-quota', 'full' => $percent >= 100])>{{ __('Inbox') }}: <b>{{ forum_number($used) }} / {{ forum_number($quota) }}</b> — {{ $percent >= 100 ? __('full') : __('almost full') }}</div>
@endif
@forelse ($conversations as $conversation)
    @php
        $unread = ! $conversation->last_read_at || $conversation->last_message_at?->gt($conversation->last_read_at);
        $others = $conversation->participants->where('id', '!=', auth()->id());
        $message = $last[$conversation->id] ?? null;
    @endphp
    <a @class(['pop-item', 'unread' => $unread]) href="{{ route('conversations.show', $conversation) }}">
        <x-avatar :user="$others->first() ?? auth()->user()" :link="false" />
        <span class="pop-body">
            <span class="pop-title">{{ $conversation->title }}</span>
            @if ($message)
                <span class="pop-snippet">@if ($message->user_id === auth()->id()){{ __('You') }}: @elseif ($others->count() > 1){{ $conversation->lastMessageUser?->username }}: @endif{{ $message->snippet }}</span>
            @endif
            <span class="pop-time">{{ $others->pluck('username')->take(3)->join(', ') ?: '—' }} · <x-time :at="$conversation->last_message_at" /></span>
        </span>
    </a>
@empty
    <div class="pop-empty"><x-icon name="mail" :size="26" /><div>{{ __('You have no conversations yet.') }}</div></div>
@endforelse
