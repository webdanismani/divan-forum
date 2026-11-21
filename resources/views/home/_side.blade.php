{{-- Side boxes of the home page sections: members online, promotion boxes, popular threads, social media. --}}
@php
    $promos = $home->enabled('promos') ? $home->promos() : [];
    $social = $home->enabled('social') ? $home->social() : [];
    $promoBox = function (array $promo) use ($home) {
        return view('home._promo', ['promo' => $promo, 'home' => $home])->render();
    };
@endphp

@if ($home->enabled('online'))
    <section class="block side-online">
        <div class="block-head"><i class="live-dot"></i><h2>{{ __('Members online') }} ({{ forum_number($sidebar['online']->count()) }})</h2></div>
        @if ($sidebar['online']->isEmpty())
            <div class="block-body muted">{{ __('Nobody is online right now.') }}</div>
        @else
            <div class="online-faces">
                @foreach ($sidebar['online']->take(4) as $member)
                    <x-avatar :user="$member" />
                @endforeach
                @if ($sidebar['online']->count() > 4)
                    <a class="more" href="{{ route('members.index') }}">+{{ $sidebar['online']->count() - 4 }}</a>
                @endif
            </div>
        @endif
    </section>
@endif

@isset($promos[0]){!! $promoBox($promos[0]) !!}@endisset

@if ($home->enabled('popular') && $sidebar['popular']->isNotEmpty())
    <section class="block side-popular">
        <div class="block-head"><x-icon name="flame" :size="16" /><h2>{{ __('Popular threads') }}</h2><span class="spacer"></span><a class="more" href="{{ route('whats-new') }}">{{ __('See all') }} <x-icon name="arrow-right" :size="13" /></a></div>
        <ol class="rank-list">
            @foreach ($home->withPictures($sidebar['popular']->take(5)->loadMissing('user')) as $thread)
                <li>
                    <span class="rank">{{ $loop->iteration }}</span>
                    @if ($thread->home_picture)
                        <span class="thumb"><img src="{{ $thread->home_picture }}" alt="" loading="lazy"></span>
                    @else
                        <x-avatar :user="$thread->user" size="sm" :link="false" class="thumb" />
                    @endif
                    <div class="meta">
                        <a class="title truncate" href="{{ route('threads.show', $thread) }}">{{ $thread->title }}</a>
                        <div class="truncate">{{ trans_choice(':count view|:count views', $thread->view_count, ['count' => \App\Forum\Home::short($thread->view_count)]) }} • {{ trans_choice(':count reply|:count replies', $thread->reply_count, ['count' => \App\Forum\Home::short($thread->reply_count)]) }}</div>
                    </div>
                </li>
            @endforeach
        </ol>
    </section>
@endif

@if ($social)
    <section class="block side-social">
        <div class="block-head"><x-icon name="megaphone" :size="16" /><h2>{{ __('Social media') }}</h2></div>
        <div class="social-row">@include('home._social', ['social' => $social])</div>
    </section>
@endif

@foreach (array_slice($promos, 1) as $promo){!! $promoBox($promo) !!}@endforeach
