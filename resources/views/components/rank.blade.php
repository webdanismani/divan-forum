{{-- The title a member earned by posting, with the step reached on the ladder. A title of their own keeps its text. --}}
@props(['user' => null, 'inline' => false, 'posts' => null])
@php
    // With "posts" instead of a member: what that step of the ladder looks like (admin panel).
    $rank = app(\App\Forum\Rewards::class)->rank((int) ($posts ?? $user->post_count));
    $text = $user?->custom_title ?: ($rank['title'] ?? ($user?->group?->name ?? ''));
    $own = $rank && preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($rank['color'] ?? '')) ? $rank['color'] : null;
    $mark = $rank && in_array($rank['icon'] ?? null, \App\Models\UserGroup::BANNER_ICONS, true) ? $rank['icon'] : null;
    $pips = $rank ? min($rank['total'], 6) : 0;
    $filled = $rank ? max(1, (int) round($rank['level'] / $rank['total'] * $pips)) : 0;
    $tip = $rank
        ? ($rank['next']
            ? __('Next title: :title at :count posts', ['title' => $rank['next']['title'], 'count' => forum_number($rank['next']['posts'])])
            : __('The highest title'))
        : null;
@endphp
@if ($text !== '')
    <span {{ $attributes->class(['rank', 'inline' => $inline, 'r'.$filled => $filled > 0]) }} @if ($tip) title="{{ $tip }}" @endif @if ($own) style="--rank: {{ $own }}" @endif>
        <span class="rank-title">@if ($mark)<x-icon :name="$mark" :size="12" /> @endif{{ $text }}</span>
        @if ($pips > 1)
            <span class="rank-pips" aria-hidden="true">@for ($i = 1; $i <= $pips; $i++)<i @class(['on' => $i <= $filled])></i>@endfor</span>
        @endif
    </span>
@endif
