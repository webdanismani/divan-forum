@php
    $me = auth()->user();
    $home = app(\App\Forum\Home::class);
@endphp
<aside class="stack">
    @if (! $home->enabled('account'))
    @elseif ($me)
        <section class="block">
            <div class="me-card">
                <x-avatar :user="$me" />
                <div style="min-width: 0">
                    <x-username :user="$me" />
                    <div class="muted small truncate">{{ $me->displayTitle() }}</div>
                </div>
            </div>
            <div class="mini-stats">
                <div><span class="stat-num">{{ forum_number($me->post_count) }}</span><span class="stat-label"><x-icon name="message" :size="13" /> {{ __('Posts') }}</span></div>
                <div><span class="stat-num">{{ forum_number($me->thread_count) }}</span><span class="stat-label"><x-icon name="list" :size="13" /> {{ __('Threads') }}</span></div>
                <div><span class="stat-num">{{ forum_number($me->reaction_score) }}</span><span class="stat-label"><x-icon name="smile" :size="13" /> {{ __('Reaction score') }}</span></div>
            </div>
            @php $profilePercent = \App\Http\Controllers\WelcomeController::percent($me); @endphp
            @if ($profilePercent < 100)
                <a class="profile-meter" href="{{ route('welcome') }}" title="{{ __('Complete your profile') }}">
                    <span>{{ __('Profile') }}: <b>{{ app()->getLocale() === 'tr' ? '%'.$profilePercent : $profilePercent.'%' }}</b></span>
                    <span class="quota-bar"><i style="width: {{ $profilePercent }}%"></i></span>
                </a>
            @endif
            <div class="side-links">
                <a href="{{ route('members.show', $me) }}"><x-icon name="user" :size="14" /> {{ __('My profile') }}</a>
                <a href="{{ route('my') }}"><x-icon name="list" :size="14" /> {{ __('Mine') }}</a>
                <a href="{{ route('account.edit') }}"><x-icon name="sliders" :size="14" /> {{ __('Settings') }}</a>
            </div>
        </section>
    @else
        <section class="block">
            <div class="block-head"><x-icon name="users" :size="16" /><h2>{{ __('Join the community') }}</h2></div>
            <div class="block-body">
                <p class="muted" style="margin-bottom: 12px">{{ __('Sign in or create a free account to start threads, reply and follow the topics you care about.') }}</p>
                <div class="row" style="flex-wrap: nowrap">
                    @if (setting('registration.enabled'))
                        <a class="btn btn-primary" style="flex: 1" href="{{ route('register') }}">{{ __('Register') }}</a>
                    @endif
                    <a class="btn" style="flex: 1" href="{{ route('login') }}">{{ __('Log in') }}</a>
                </div>
            </div>
        </section>
    @endif

    @include('home._side')

    <x-ad-zone position="sidebar" />
    @hook('index.sidebar')

    @php $sponsored = setting('wallet.enabled') ? app(\App\Forum\Promotions::class)->sponsored($me) : collect(); @endphp
    @if ($sponsored->isNotEmpty())
        <section class="block">
            <div class="block-head"><x-icon name="star" :size="16" /><h2>{{ __('Sponsored threads') }}</h2></div>
            <ul class="side-list">
                @foreach ($sponsored as $thread)
                    <li>
                        <div class="meta" style="flex: 1; min-width: 0">
                            <a class="thread-title" href="{{ route('threads.show', $thread) }}">{{ $thread->title }}</a>
                            <div class="muted small truncate">{{ $thread->user?->username }} · {{ $thread->node?->title }}</div>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($home->enabled('lists'))
    <section class="block">
        <div class="block-head"><x-icon name="clock" :size="16" /><h2>{{ __('Today on the forum') }}</h2></div>
        <div class="mini-stats" style="border-top: 0">
            <div><span class="stat-num">{{ forum_number($sidebar['today']['threads']) }}</span><span class="stat-label"><x-icon name="edit" :size="13" /> {{ __('New threads') }}</span></div>
            <div><span class="stat-num">{{ forum_number($sidebar['today']['posts']) }}</span><span class="stat-label"><x-icon name="message" :size="13" /> {{ __('New posts') }}</span></div>
            <div><span class="stat-num">{{ forum_number($sidebar['today']['members']) }}</span><span class="stat-label"><x-icon name="users" :size="13" /> {{ __('New members') }}</span></div>
        </div>
    </section>

    @if ($sidebar['topPosters']->isNotEmpty())
        <section class="block" data-tabs>
            <div class="bar tabbar even" role="tablist">
                <button type="button" role="tab" data-tab="posters" class="active">{{ __('Most posts') }}</button>
                <button type="button" role="tab" data-tab="reacted">{{ __('Most liked') }}</button>
            </div>
            @foreach (['posters' => ['topPosters', 'post_count'], 'reacted' => ['topReacted', 'reaction_score']] as $pane => [$key, $column])
                <div data-pane="{{ $pane }}" @class(['hidden' => ! $loop->first])>
                    @if ($sidebar[$key]->isEmpty())
                        <div class="empty">{{ __('Nothing to show yet.') }}</div>
                    @else
                        <ul class="side-list">
                            @foreach ($sidebar[$key] as $member)
                                <li>
                                    <span class="rank">{{ $loop->iteration }}</span>
                                    <x-avatar :user="$member" size="sm" />
                                    <div class="meta"><x-username :user="$member" /><div class="truncate">{{ $member->displayTitle() }}</div></div>
                                    <span class="num">{{ forum_number($member->{$column}) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endforeach
        </section>
    @endif

    <section class="block">
        <div class="block-head"><x-icon name="user" :size="16" /><h2>{{ __('Newest members') }}</h2></div>
        <ul class="side-list">
            @foreach ($sidebar['newMembers'] as $member)
                <li>
                    <x-avatar :user="$member" size="sm" />
                    <div class="meta"><x-username :user="$member" /><div><x-time :at="$member->created_at" /></div></div>
                </li>
            @endforeach
        </ul>
        <div class="side-links"><a href="{{ route('members.index') }}">{{ __('All members') }}</a></div>
    </section>
    @endif
</aside>
