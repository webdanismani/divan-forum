@php
    $style = isset(\App\Forum\Home::PROMO_STYLES[$promo['style']]) ? $promo['style'] : 'gold';
    $picture = $home->image($promo['image']);
    $href = $home->link($promo['link']);
@endphp
<section class="promo promo-{{ $style }} @if ($picture) has-picture @endif" @if ($picture) style="--promo-image: url('{{ $picture }}')" @endif>
    <h3><x-icon :name="$style === 'gold' ? 'crown' : 'star'" :size="18" /> {{ $promo['title'] }}</h3>
    @if ($promo['text'] !== '')<p>{{ $promo['text'] }}</p>@endif
    @if ($href && $promo['button'] !== '')<a class="promo-btn" href="{{ $href }}">{{ $promo['button'] }} <x-icon name="arrow-right" :size="14" /></a>@endif
</section>
