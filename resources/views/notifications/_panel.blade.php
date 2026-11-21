{{-- Newest alerts for the pop-over in the header. --}}
@forelse ($alerts as $alert)
    @php
        $data = $alert->data;
        $actor = $actors[$data['actor_id'] ?? 0] ?? null;
    @endphp
    <a @class(['pop-item', 'unread' => $alert->read_at === null]) href="{{ route('notifications.open', $alert->id) }}">
        @if ($actor)
            <x-avatar :user="$actor" :link="false" />
        @else
            <span class="pop-icon"><x-icon :name="\App\Forum\AlertText::icon($data)" :size="16" /></span>
        @endif
        <span class="pop-body">
            <span class="pop-text">{{ \App\Forum\AlertText::text($data) }}</span>
            <span class="pop-time"><x-icon :name="\App\Forum\AlertText::icon($data)" :size="12" /> <x-time :at="$alert->created_at" /></span>
        </span>
    </a>
@empty
    <div class="pop-empty"><x-icon name="bell" :size="26" /><div>{{ __('You have no alerts.') }}</div></div>
@endforelse
