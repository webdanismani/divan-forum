{{-- Hero and quick tiles: printed across the full width, between the menu and the page. --}}
@php
    $home = app(\App\Forum\Home::class);
    $slides = $home->enabled('hero') ? $home->slides() : [];
    $tiles = $home->enabled('tiles') ? $home->tiles() : [];
@endphp
@if ($slides)
    <section class="hero" data-hero aria-label="{{ setting('board.name') }}">
        @foreach ($slides as $i => $slide)
            @php $picture = $home->image($slide['image']); @endphp
            <div @class(['hero-slide', 'active' => $i === 0, 'has-picture' => (bool) $picture]) data-hero-slide @if ($picture) style="--hero-image: url('{{ $picture }}')" @endif @if ($i > 0) hidden @endif>
                <div class="container hero-inner">
                    @if ($slide['badge'] !== '')<span class="hero-badge"><x-icon name="flame" :size="15" /> {{ $slide['badge'] }}</span>@endif
                    <h2 class="hero-title">{!! \App\Forum\Home::headline($slide['title'], $slide['mark']) !!}</h2>
                    @if ($slide['text'] !== '')<p class="hero-text">{{ $slide['text'] }}</p>@endif
                    @if (($first = $home->link($slide['b1_link'])) || $home->link($slide['b2_link']))
                        <div class="hero-actions">
                            @if ($first && $slide['b1_label'] !== '')<a class="hero-btn primary" href="{{ $first }}"><x-icon name="message" :size="16" /> {{ $slide['b1_label'] }} <x-icon name="arrow-right" :size="15" /></a>@endif
                            @if (($second = $home->link($slide['b2_link'])) && $slide['b2_label'] !== '')<a class="hero-btn" href="{{ $second }}"><x-icon name="book" :size="16" /> {{ $slide['b2_label'] }}</a>@endif
                        </div>
                    @endif
                </div>
            </div>
        @endforeach
        @if (count($slides) > 1)
            <div class="container hero-nav">
                <div class="hero-dots">
                    @foreach ($slides as $i => $slide)
                        <button type="button" data-hero-dot="{{ $i }}" @class(['active' => $i === 0]) aria-label="{{ __('Slide') }} {{ $i + 1 }}"></button>
                    @endforeach
                </div>
                <button class="hero-arrow" type="button" data-hero-step="-1" aria-label="{{ __('Previous') }}"><x-icon name="chevron-left" :size="17" /></button>
                <button class="hero-arrow" type="button" data-hero-step="1" aria-label="{{ __('Next') }}"><x-icon name="chevron-right" :size="17" /></button>
            </div>
        @endif
    </section>
@endif
@if ($tiles)
    <section class="quick-tiles">
        <div class="container quick-tiles-inner">
            @foreach ($tiles as $i => $tile)
                @php $href = $home->link($tile['link']); @endphp
                <{{ $href ? 'a' : 'div' }} class="quick-tile qt-{{ $i + 1 }}" @if ($href) href="{{ $href }}" @endif>
                    <span class="qt-icon"><x-icon :name="$tile['icon'] ?: 'star'" :size="24" /></span>
                    <span class="qt-text"><b>{{ $tile['title'] }}</b>@if ($tile['text'] !== '')<small>{{ $tile['text'] }}</small>@endif</span>
                </{{ $href ? 'a' : 'div' }}>
            @endforeach
        </div>
    </section>
@endif
