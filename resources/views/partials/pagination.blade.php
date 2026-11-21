@if ($paginator->hasPages())
    <nav class="pagination" aria-label="{{ __('Pages') }}">
        @if ($paginator->onFirstPage())
            <span class="disabled">‹</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="{{ __('Previous') }}">‹</a>
        @endif

        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="gap">{{ $element }}</span>
            @endif
            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span class="current" aria-current="page">{{ $page }}</span>
                    @else
                        <a href="{{ $url }}">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="{{ __('Next') }}">›</a>
        @else
            <span class="disabled">›</span>
        @endif
    </nav>
@endif
