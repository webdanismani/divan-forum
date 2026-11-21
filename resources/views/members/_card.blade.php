{{-- Small profile card shown when the pointer rests on a member's name. --}}
<div class="hovercard">
    @if ($visible && $member->coverUrl())
        <div class="hovercard-cover" style="background-image: url('{{ $member->coverUrl() }}')"></div>
    @endif
    <div class="hovercard-head">
        <x-avatar :user="$member" size="lg" />
        <div style="min-width: 0">
            <x-username :user="$member" />
            <div class="muted small truncate">{{ $member->displayTitle() }}</div>
            <div class="hovercard-badges">
                @if ($member->group && filled($member->group->badge))<x-group-banner :group="$member->group" />@endif
                @if ($showSeen)<x-presence :user="$member" :online="$online" />@endif
                @if ($member->phone_verified_at)<span class="badge green">{{ __('Verified member') }}</span>@endif
            </div>
        </div>
    </div>
    @if ($visible)
        <div class="mini-stats">
            <div><span class="stat-num">{{ forum_number($member->post_count) }}</span><span class="stat-label">{{ __('Posts') }}</span></div>
            <div><span class="stat-num">{{ forum_number($member->thread_count) }}</span><span class="stat-label">{{ __('Threads') }}</span></div>
            <div><span class="stat-num">{{ forum_number($member->reaction_score) }}</span><span class="stat-label">{{ __('Reaction score') }}</span></div>
        </div>
        <div class="hovercard-meta">
            <span>{{ __('Joined') }}: <b>{{ $member->created_at?->translatedFormat('j M Y') }}</b></span>
            @if ($online || $showSeen)<span>{{ __('Last seen') }}: <b>{{ $online ? __('now') : $member->last_seen_at?->diffForHumans() }}</b></span>@endif
            @hook('member.card', ['member' => $member])
        </div>
    @else
        <div class="hovercard-meta"><span class="muted">{{ __('This member limits who can see their profile.') }}</span></div>
    @endif
    <div class="hovercard-actions">
        <a class="btn btn-sm" href="{{ route('members.show', $member) }}">{{ __('Profile') }}</a>
        @auth
            @if (auth()->id() !== $member->id && $canMessage && forum_can('conversation.start'))
                <a class="btn btn-sm" href="{{ route('conversations.create', ['to' => $member->username]) }}">{{ __('Send message') }}</a>
            @endif
        @endauth
    </div>
</div>
