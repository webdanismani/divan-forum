@php
    $showNode = $showNode ?? false;
    $selectable = $selectable ?? null; // id of the moderation form the checkboxes belong to
    if (($node ?? null)?->is_marketplace && method_exists($threads, 'loadMissing')) {
        $threads->loadMissing('listing');
    }
    if (($node ?? null)?->is_marketplace) {
        ($threads instanceof \Illuminate\Contracts\Pagination\Paginator ? $threads->getCollection() : $threads)->loadMissing('listing');
    }
@endphp
<div class="thead thread-grid thread-head">
    <span>@if ($selectable)<input class="select-box" type="checkbox" data-select-all="{{ $selectable }}" aria-label="{{ __('Select all') }}" style="vertical-align: -3px; margin-right: 8px">@endif{{ __('Thread / Started by') }}</span>
    <span class="center">{{ __('Replies') }}</span>
    <span class="center">{{ __('Views') }}</span>
    <span>{{ __('Last post') }}</span>
</div>
@php
    // Sticky and promoted threads come first (the query orders them so); each part gets a heading.
    $pinnedCount = ($showNode || ! ($sections ?? true)) ? 0 : collect($threads instanceof \Illuminate\Contracts\Pagination\Paginator ? $threads->items() : $threads)->filter(fn ($thread) => $thread->isPinned())->count();
    $section = null;
@endphp
@foreach ($threads as $thread)
    @if ($pinnedCount > 0)
        @php $now = $thread->isPinned() ? 'sticky' : 'normal'; @endphp
        @if ($now !== $section)
            @php $section = $now; @endphp
            <div class="thread-section {{ $now }}">
                <x-icon :name="$now === 'sticky' ? 'pin' : 'message'" :size="14" />
                {{ $now === 'sticky' ? __('Sticky threads') : __('Normal threads') }}
                @if ($now === 'sticky')<span class="badge amber">{{ $pinnedCount }}</span>@endif
            </div>
        @endif
    @endif
    <div @class([
        'thread-row',
        'thread-grid',
        'sticky' => $thread->isPinned() && ! $showNode,
        'hl' => $thread->isHighlighted(),
        'unread' => $thread->isUnreadFor(auth()->user()),
        'dim' => ! $thread->isVisible(),
    ])>
        <div><x-avatar :user="$thread->user" /></div>
        <div>
            <div>
                @if ($selectable)<input class="select-box" type="checkbox" name="threads[]" value="{{ $thread->id }}" form="{{ $selectable }}" aria-label="{{ __('Select') }}" style="vertical-align: -3px; margin-right: 6px">@endif
                @if ($thread->is_sticky && ! $showNode)<span class="badge amber"><x-icon name="pin" :size="11" /> {{ __('Sticky') }}</span>
                @elseif ($thread->isPinned() && ! $showNode)<span class="badge amber"><x-icon name="star" :size="11" /> {{ __('Featured') }}</span>@endif
                @if ($thread->prefix)<span class="prefix {{ $thread->prefix->color }}">{{ $thread->prefix->title }}</span>@endif
                <a class="thread-title" href="{{ route(auth()->check() ? 'threads.unread' : 'threads.show', $thread) }}">{{ $thread->title }}</a>
                @if ($thread->isSolved())<span class="badge green"><x-icon name="check" :size="11" /> {{ __('Solved') }}</span>@endif
                @hook('thread.row', ['thread' => $thread])
                @if ($thread->relationLoaded('listing') && $thread->listing)
                    @if ($thread->listing->status !== 'open')<span class="badge {{ \App\Models\Listing::STATES[$thread->listing->status][1] ?? '' }}">{{ __(\App\Models\Listing::STATES[$thread->listing->status][0] ?? 'Closed') }}</span>@endif
                    @if ($thread->listing->price !== null)<span class="listing-price sm">{{ forum_money($thread->listing->price) }}</span>@endif
                @endif
                @if ($thread->is_locked)<span class="thread-flags" title="{{ __('Locked') }}"><x-icon name="lock" :size="13" /></span>@endif
                @if ($thread->state === 'moderated')<span class="badge amber">{{ __('Awaiting approval') }}</span>@endif
                @if ($thread->state === 'deleted')<span class="badge red">{{ __('Deleted') }}</span>@endif
            </div>
            <div class="thread-meta">
                <x-username :user="$thread->user" />
                <span>·</span>
                <x-time :at="$thread->created_at" />
                <span class="m-only">· {{ trans_choice(':count reply|:count replies', $thread->reply_count, ['count' => forum_number($thread->reply_count)]) }}</span>
                @if ($showNode && $thread->node)
                    <span>·</span>
                    <a href="{{ route('nodes.show', $thread->node) }}">{{ $thread->node->title }}</a>
                @endif
            </div>
        </div>
        <div class="num" data-label="{{ __('Replies') }}">{{ forum_number($thread->reply_count) }}</div>
        <div class="num" data-label="{{ __('Views') }}">{{ forum_number($thread->view_count) }}</div>
        <div class="thread-last">
            <x-username :user="$thread->lastPostUser" class="truncate" />
            <a class="when" href="{{ route('posts.show', $thread->last_post_id ?? $thread->first_post_id ?? 0) }}"><x-time :at="$thread->last_post_at" /></a>
        </div>
    </div>
@endforeach
