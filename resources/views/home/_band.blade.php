{{-- The board in four figures. --}}
<section class="stat-band">
    @foreach ([
        ['users', $sidebar['memberCount'], __('Total members')],
        ['book', $sidebar['postCount'], __('Total posts')],
        ['message', $sidebar['threadCount'], __('Total threads')],
        ['bolt', $sidebar['online']->count(), __('Members online')],
    ] as [$icon, $value, $label])
        <div class="stat-band-item"><x-icon :name="$icon" :size="30" /><div><b>{{ \App\Forum\Home::short($value) }}</b><span>{{ $label }}</span></div></div>
    @endforeach
</section>
