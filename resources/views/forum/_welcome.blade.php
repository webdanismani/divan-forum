{{-- Welcome area for visitors who are not signed in: what the forum is for and how to join. Members never see it. --}}
@guest
    @if (setting('welcome.enabled'))
        @php
            $board = (string) setting('board.name');
            $title = filled(setting('welcome.title')) ? setting('welcome.title') : __('Welcome to :board', ['board' => $board]);
            $text = filled(setting('welcome.text')) ? setting('welcome.text') : setting('board.description');
            $totals = \Illuminate\Support\Facades\Cache::remember('forum.welcome.totals', 300, fn () => [
                'members' => \App\Models\User::count(),
                'threads' => \App\Models\Thread::where('state', 'visible')->count(),
                'posts' => \App\Models\Post::where('state', 'visible')->count(),
            ]);
            $features = array_filter([
                ['message', __('Start threads and join the discussions')],
                ['check', __('Ask your questions and mark the answer that solved them')],
                ['mail', __('Private conversations, alerts and following members')],
                setting('market.enabled') && app(\App\Forum\NodeTree::class)->all()->contains('is_marketplace', true) ? ['tag', __('Post listings and build a trader score')] : null,
                ['bell', __('Watch forums and threads; get told about new replies')],
            ]);
        @endphp
        <section class="welcome" data-welcome>
            <button class="welcome-close" type="button" data-welcome-close title="{{ __('Hide') }}" aria-label="{{ __('Hide') }}"><x-icon name="x" :size="16" /></button>
            <div class="welcome-main">
                <h2>{{ $title }}</h2>
                @if (filled($text))<p>{{ $text }}</p>@endif
                <ul class="welcome-features">
                    @foreach (array_slice($features, 0, 4) as [$icon, $label])
                        <li><x-icon :name="$icon" :size="16" /> {{ $label }}</li>
                    @endforeach
                </ul>
            </div>
            <div class="welcome-side">
                <div class="welcome-stats">
                    <div><b>{{ forum_number($totals['members']) }}</b><span>{{ __('Members') }}</span></div>
                    <div><b>{{ forum_number($totals['threads']) }}</b><span>{{ __('Threads') }}</span></div>
                    <div><b>{{ forum_number($totals['posts']) }}</b><span>{{ __('Posts') }}</span></div>
                </div>
                <div class="welcome-actions">
                    @if (setting('registration.enabled'))
                        <a class="btn btn-primary" href="{{ route('register') }}">{{ __('Join for free') }}</a>
                    @endif
                    <a class="btn" href="{{ route('login') }}">{{ __('Log in') }}</a>
                </div>
            </div>
        </section>
    @endif
@endguest
