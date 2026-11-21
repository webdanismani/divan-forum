{{-- The foot of every page, in the manner of a classic board: a statistics line, a plain sitemap, the legal line. --}}
@php
    $foot = $site->footer(auth()->user());
    $about = setting('board.footer_text') ?: setting('board.description');
    $homeSections = app(\App\Forum\Home::class);
    $footSocial = $homeSections->enabled('social') ? $homeSections->social() : [];
    $footSignup = $homeSections->enabled('signup') && auth()->guest() && setting('registration.enabled');
    // Solid glyphs (Material Icons, Apache 2.0): they read as familiar symbols at small sizes.
    $glyphs = [
        'threads' => '<path d="M21 6h-2v9H6v2c0 .55.45 1 1 1h11l4 4V7c0-.55-.45-1-1-1zm-4 6V3c0-.55-.45-1-1-1H3c-.55 0-1 .45-1 1v14l4-4h10c.55 0 1-.45 1-1z"/>',
        'posts' => '<path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/>',
        'members' => '<path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/>',
        'online' => '<circle cx="12" cy="12" r="7"/>',
        'newest' => '<path d="M15 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm-9-2V7H4v3H1v2h3v3h2v-3h3v-2H6zm9 4c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>',
        'up' => '<path d="M7.41 15.41 12 10.83l4.59 4.58L18 14l-6-6-6 6z"/>',
    ];
    $glyph = fn (string $name, int $size = 16) => '<svg class="glyph g-'.$name.'" width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">'.$glyphs[$name].'</svg>';
@endphp
<footer class="site-footer">
    <div class="container">
        <div class="footer-stats">
            <span>{!! $glyph('threads') !!} {{ __('Threads') }}: <b>{{ forum_number($foot['threads']) }}</b></span>
            <span>{!! $glyph('posts') !!} {{ __('Posts') }}: <b>{{ forum_number($foot['posts']) }}</b></span>
            <span>{!! $glyph('members') !!} {{ __('Members') }}: <b>{{ forum_number($foot['members']) }}</b></span>
            <span>{!! $glyph('online', 12) !!} {{ __('Online now') }}: <b>{{ forum_number($foot['online']) }}</b></span>
            @if ($foot['newest'])
                <span>{!! $glyph('newest') !!} {{ __('Newest member') }}: <a href="{{ route('members.show', $foot['newest']) }}"><b>{{ $foot['newest']->username }}</b></a></span>
            @endif
        </div>

        <div @class(['footer-main', 'has-signup' => $footSignup])>
            <div class="footer-about">
                <h3><span><b>{{ $nameParts[0] }}</b>@isset($nameParts[1]) {{ $nameParts[1] }}@endisset</span></h3>
                @if ($about)<p>{{ $about }}</p>@endif
                @guest
                    <p class="footer-join">
                        <a href="{{ route('login') }}">{{ __('Log in') }}</a>
                        @if (setting('registration.enabled')) · <a href="{{ route('register') }}">{{ __('Register') }}</a>@endif
                    </p>
                @endguest
                @if ($footSocial)
                    <div class="footer-social">@include('home._social', ['social' => $footSocial])</div>
                @endif
            </div>

            <nav class="footer-col" aria-label="{{ __('Forum') }}">
                <h3>{{ __('Forum') }}</h3>
                <ul>
                    <li><a href="{{ route('forum.index') }}">{{ __('Forums') }}</a></li>
                    <li><a href="{{ route('whats-new') }}">{{ __("What's new") }}</a></li>
                    <li><a href="{{ route('members.index') }}">{{ __('Members') }}</a></li>
                    @if ((int) setting('posting.max_tags') > 0)
                        <li><a href="{{ route('tags.index') }}">{{ __('Tags') }}</a></li>
                    @endif
                    @if (forum_can('search.use'))
                        <li><a href="{{ route('search') }}">{{ __('Search') }}</a></li>
                    @endif
                </ul>
            </nav>

            <nav class="footer-col" aria-label="{{ __('Site') }}">
                <h3>{{ __('Site') }}</h3>
                <ul>
                    @if (setting('ads.enabled'))
                        <li><a href="{{ route('ads.index') }}">{{ __('Advertise') }}</a></li>
                    @endif
                    @foreach ($site->footerPages() as $footerPage)
                        <li><a href="{{ route('pages.show', $footerPage) }}">{{ $footerPage->title }}</a></li>
                    @endforeach
                    @foreach ($site->links('footer') as $link)
                        <li><a href="{{ $link->url }}" @if ($link->new_tab) target="_blank" rel="noopener" @endif>{{ $link->title }}</a></li>
                    @endforeach
                    <li><a href="{{ route('contact') }}">{{ __('Contact') }}</a></li>
                    <li><a href="{{ route('feed') }}">RSS</a></li>
                    @if (($extra = trim(app(\App\Forum\Addons\Hooks::class)->render('layout.footer'))) !== '')
                        <li class="footer-extra">{!! $extra !!}</li>
                    @endif
                </ul>
            </nav>

            @if ($foot['forums']->isNotEmpty())
                <nav class="footer-col" aria-label="{{ __('Busiest forums') }}">
                    <h3>{{ __('Busiest forums') }}</h3>
                    <ul>
                        @foreach ($foot['forums'] as $forum)
                            <li><a href="{{ route('nodes.show', $forum) }}">{{ $forum->title }}</a> <span class="n">({{ forum_number($forum->post_count) }})</span></li>
                        @endforeach
                    </ul>
                </nav>
            @endif

            @if ($footSignup)
                {{-- No mailing list behind this: the address is carried to the registration form. --}}
                <div class="footer-signup">
                    <h3>{{ __('Join the community') }}</h3>
                    <p>{{ __('Leave your e-mail address and finish your free account in a minute.') }}</p>
                    <form method="get" action="{{ route('register') }}">
                        <input class="input" type="email" name="email" placeholder="{{ __('E-mail address') }}" aria-label="{{ __('E-mail address') }}" required>
                        <button class="btn btn-primary" type="submit">{{ __('Register') }}</button>
                    </form>
                </div>
            @endif
        </div>
    </div>

    <x-ad-zone position="links" />

    <div class="footer-legal">
        <div class="container">
            <span>© {{ date('Y') }} {{ $boardName }}</span>
            <span class="credit">
                {{ __('Community platform by') }}
                <a href="{{ config('forum.product.vendor_url') }}" target="_blank" rel="noopener">{{ config('forum.product.vendor') }}</a><sup>®</sup>
                © {{ config('forum.product.copyright_from') }} - {{ max((int) date('Y'), (int) config('forum.product.copyright_from')) }}
                {{ config('forum.product.vendor') }} · {{ __('All rights reserved.') }}
            </span>
            <a class="footer-top" href="#top" data-to-top>{!! $glyph('up', 18) !!} {{ __('Back to the top') }}</a>
        </div>
    </div>
</footer>
