{{-- Start page of the admin panel: what is still to be set up. Closed once nothing necessary is left. --}}
@php
    $checklist = app(\App\Forum\Setup\Checklist::class);
    $items = $checklist->items();
    $counts = $checklist->counts();
    $open = array_values(array_filter($items, fn ($item) => $item['state'] !== 'done'));
    usort($open, fn ($a, $b) => ($a['state'] === 'todo' ? 0 : 1) <=> ($b['state'] === 'todo' ? 0 : 1));
@endphp
@if ($open)
    <details class="block checklist">
        <summary class="block-head">
            <x-icon name="check" :size="16" /><h2>{{ __('Setup checklist') }}</h2>
            @if ($counts['todo'] > 0)<span class="badge amber">{{ __(':count to do', ['count' => $counts['todo']]) }}</span>@endif
            <span class="muted" style="margin-left: auto">{{ __(':done of :total done', ['done' => $counts['done'], 'total' => $counts['total']]) }}</span>
            <span class="checklist-bar" aria-hidden="true"><i style="width: {{ round($counts['done'] / max(1, $counts['total']) * 100) }}%"></i></span>
            <x-icon name="chevron-down" :size="15" class="checklist-arrow" />
        </summary>
        <ul class="checklist-items">
            @foreach ($open as $item)
                <li class="{{ $item['state'] }}">
                    <span class="mark"><x-icon :name="$item['state'] === 'todo' ? 'alert' : 'plus'" :size="14" /></span>
                    <div>
                        <b>{{ __($item['title']) }}</b>
                        @if ($item['state'] === 'optional')<span class="badge">{{ __('optional') }}</span>@endif
                        <div class="muted small">{{ __($item['text']) }}</div>
                    </div>
                    <a class="btn btn-sm" href="{{ $item['url'] }}">{{ __('Set up') }}</a>
                </li>
            @endforeach
        </ul>
    </details>
@endif
