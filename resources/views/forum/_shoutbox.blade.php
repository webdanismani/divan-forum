{{-- Shoutbox: a short chat on the home page. The script asks for new lines every few seconds while the tab is visible. --}}
@if (setting('shoutbox.enabled'))
    @php $shouts = \App\Models\Shout::with('user.group')->latest('id')->limit(30)->get()->reverse()->values(); @endphp
    <section class="block shoutbox" data-shoutbox="{{ route('shoutbox.index') }}" data-last="{{ $shouts->last()?->id ?? 0 }}">
        <div class="block-head">
            <x-icon name="message" :size="16" /><h2>{{ __('Shoutbox') }}</h2>
            <span class="spacer"></span>
            <button class="cat-toggle" type="button" data-collapse="shoutbox" aria-expanded="true" title="{{ __('Collapse / expand') }}" aria-label="{{ __('Collapse / expand') }}"><x-icon name="chevron-down" :size="16" /></button>
        </div>
        <div data-collapsible="shoutbox">
            <div class="shout-list" data-shout-list aria-live="polite">
                @include('forum._shouts', ['shouts' => $shouts])
                @if ($shouts->isEmpty())<div class="empty" data-shout-empty>{{ __('Nobody has written anything yet. Say hello!') }}</div>@endif
            </div>
            @auth
                @if (forum_can('shoutbox.post'))
                    <form class="shout-form" method="post" action="{{ route('shoutbox.store') }}" data-shout-form>
                        @csrf
                        <input class="input" name="body" maxlength="300" placeholder="{{ __('Write a message…') }}" autocomplete="off" required>
                        <button class="btn btn-primary" type="submit">{{ __('Send') }}</button>
                    </form>
                @endif
            @else
                <div class="block-foot muted small"><a href="{{ route('login') }}">{{ __('Log in to join the conversation.') }}</a></div>
            @endauth
        </div>
    </section>
@endif
