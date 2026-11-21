{{-- The start of a thread, shown when the pointer rests on its title in a list. --}}
<div class="thread-preview">
    <div class="thread-preview-head">
        @if ($first?->user)<x-avatar :user="$first->user" size="sm" :link="false" />@endif
        <div style="min-width: 0">
            <div class="thread-preview-title">@if ($thread->prefix)<span class="prefix {{ $thread->prefix->color }}">{{ $thread->prefix->title }}</span> @endif{{ $thread->title }}</div>
            <div class="muted small truncate">
                {{ $first?->user?->username ?? __('Guest') }} · <x-time :at="$thread->created_at" /> · {{ $node->title }}
            </div>
        </div>
    </div>
    <div class="thread-preview-body">
        @if ($ignored)
            <span class="muted">{{ __('You are ignoring the member who started this thread.') }}</span>
        @elseif ($excerpt !== '')
            {{ $excerpt }}
        @else
            <span class="muted">{{ __('This thread starts without text (a picture, a file or a poll).') }}</span>
        @endif
    </div>
    <div class="thread-preview-foot">
        <span><b>{{ forum_number($thread->reply_count) }}</b> {{ __('Replies') }}</span>
        <span><b>{{ forum_number($thread->view_count) }}</b> {{ __('Views') }}</span>
        @if ($thread->reply_count > 0 && $thread->lastPostUser)
            <span class="truncate">{{ __('Last reply') }}: <b>{{ $thread->lastPostUser->username }}</b>@if ($thread->last_post_at), {{ $thread->last_post_at->diffForHumans() }}@endif</span>
        @endif
    </div>
</div>
