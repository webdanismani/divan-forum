@extends('layouts.app')

@section('title', __('Trader feedback for :name', ['name' => $member->username]))
@section('crumb', __('Trader feedback'))

@section('content')
    @php $ratings = \App\Models\TradeFeedback::RATINGS; $staff = auth()->user()?->group?->is_staff || auth()->user()?->isAdmin(); @endphp
    <div class="medium stack">
        <div class="page-head" style="margin-bottom: 0">
            <div class="titles">
                <h1>{{ __('Trader feedback for :name', ['name' => $member->username]) }}</h1>
                <div class="desc"><a href="{{ route('members.show', $member) }}">{{ __('Back to the profile') }}</a></div>
            </div>
        </div>

        <section class="block">
            <div class="mini-stats" style="border-top: 0">
                <div><span class="stat-num">{{ $score !== null ? (app()->getLocale() === 'tr' ? '%'.$score : $score.'%') : '—' }}</span><span class="stat-label">{{ __('Trader score') }}</span></div>
                <div><span class="stat-num text-green">{{ forum_number($member->trade_positive) }}</span><span class="stat-label">{{ __('Positive') }}</span></div>
                <div><span class="stat-num">{{ forum_number($member->trade_neutral) }}</span><span class="stat-label">{{ __('Neutral') }}</span></div>
                <div><span class="stat-num text-red">{{ forum_number($member->trade_negative) }}</span><span class="stat-label">{{ __('Negative') }}</span></div>
            </div>
        </section>

        <section class="block">
            <div class="block-head"><x-icon name="star" :size="16" /><h2>{{ __('Feedback') }}</h2></div>
            @forelse ($feedback as $item)
                <div class="feedback-row">
                    <span class="badge {{ $ratings[$item->rating][1] ?? '' }}">{{ __($ratings[$item->rating][0] ?? 'Neutral') }}</span>
                    <div style="flex: 1; min-width: 0">
                        <div>{{ $item->comment }}</div>
                        <div class="muted small">
                            <x-username :user="$item->author" /> · {{ $item->role === 'seller' ? __('as the seller') : __('as the buyer') }} · <x-time :at="$item->created_at" />
                            @if ($item->thread && $item->thread->state === 'visible' && in_array($item->thread->node_id, $openNodes ?? [])) · <a href="{{ route('threads.show', $item->thread) }}">{{ $item->thread->title }}</a>@endif
                        </div>
                    </div>
                    @if ($staff)
                        <form method="post" action="{{ route('feedback.destroy', $item) }}" data-confirm="{{ __('Delete this feedback?') }}">@csrf @method('DELETE')
                            <button class="btn btn-sm btn-danger" type="submit"><x-icon name="trash" :size="14" /></button>
                        </form>
                    @endif
                </div>
            @empty
                <div class="empty">{{ __('No trader feedback yet') }}</div>
            @endforelse
        </section>

        {{ $feedback->links() }}
    </div>
@endsection
