@foreach ($social as $network => $url)
    @php [$name, $color, $glyph] = \App\Forum\Home::NETWORKS[$network]; @endphp
    <a class="social s-{{ $network }}" href="{{ $url }}" target="_blank" rel="noopener nofollow" title="{{ $name }}" aria-label="{{ $name }}" style="--brand: {{ $color }}"><svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="{{ $glyph }}"/></svg></a>
@endforeach
