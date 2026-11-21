@extends('layouts.app')

@section('title', __('Promote thread'))
@section('crumb', __('Promote thread'))

@section('content')
    @php
        $types = \App\Models\ThreadPromotion::TYPES;
        $help = [
            'sticky' => __('The thread stays at the top of its forum for the period you choose.'),
            'highlight' => __('The title is shown bold and coloured in every list.'),
            'bump' => __('The thread moves to the top of the list right now, as if it had just been answered.'),
            'sponsored' => __('The thread is listed in the "sponsored threads" box on the home page for the period you choose.'),
            'showcase' => __('The listing is shown in the showcase at the top of the marketplace for the period you choose.'),
        ];
        $icons = ['sticky' => 'pin', 'highlight' => 'star', 'bump' => 'bolt', 'sponsored' => 'megaphone', 'showcase' => 'tag'];
    @endphp

    <div class="page-head">
        <div class="titles">
            <h1>{{ __('Promote thread') }}</h1>
            <div class="desc"><a href="{{ route('threads.show', $thread) }}">{{ $thread->title }}</a></div>
        </div>
        <a class="btn" href="{{ route('wallet.index') }}"><x-icon name="wallet" :size="16" /> {{ __('My balance') }}: {{ forum_money($balance) }}</a>
    </div>

    @error('balance')<div class="flash error">{{ $message }} <a href="{{ route('wallet.index') }}">{{ __('Top up') }}</a></div>@enderror

    <div class="layout">
        <div>
            @forelse ($options as $type => $list)
                <section class="block">
                    <div class="block-head"><x-icon :name="$icons[$type] ?? 'star'" :size="16" /><h2>{{ __($types[$type] ?? $type) }}</h2></div>
                    <div class="block-body">
                        <p class="muted">{{ $help[$type] ?? '' }}</p>
                        <div class="promo-list">
                            @foreach ($list as $option)
                                <form method="post" action="{{ route('threads.promote.buy', $thread) }}" data-confirm="{{ __(':price will be taken from your balance. Continue?', ['price' => forum_money($option->price)]) }}">
                                    @csrf
                                    <input type="hidden" name="option_id" value="{{ $option->id }}">
                                    <button class="period" type="submit" style="width: 100%; text-align: left; font: inherit; color: inherit">
                                        <span class="days">{{ $option->days ? __(':days days', ['days' => $option->days]) : __('Once') }}</span>
                                        <span class="amount">{{ forum_money($option->price) }}</span>
                                    </button>
                                </form>
                            @endforeach
                        </div>
                    </div>
                </section>
            @empty
                <div class="block"><div class="empty">{{ __('Thread promotion is not on sale at the moment.') }}</div></div>
            @endforelse
        </div>

        <aside>
            <section class="block">
                <div class="block-head"><h2>{{ __('This thread') }}</h2></div>
                <div class="block-body">
                    <dl class="kv">
                        <div><dt>{{ __('Pinned until') }}</dt><dd>{{ $thread->promoted_until?->isFuture() ? $thread->promoted_until->translatedFormat('j M Y H:i') : '—' }}</dd></div>
                        <div><dt>{{ __('Highlighted until') }}</dt><dd>{{ $thread->highlight_until?->isFuture() ? $thread->highlight_until->translatedFormat('j M Y H:i') : '—' }}</dd></div>
                    </dl>
                </div>
                @if ($history->isNotEmpty())
                    <ul class="side-list" style="border-top: 1px solid var(--border)">
                        @foreach ($history as $row)
                            <li>
                                <div class="meta"><span class="title">{{ __($types[$row->type] ?? $row->type) }}</span><x-time :at="$row->created_at" /></div>
                                <span class="num">{{ forum_money($row->price) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </aside>
    </div>
@endsection
