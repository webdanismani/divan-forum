@foreach ($rows as $row)
    @php $node = $row['node']; @endphp
    <div class="node-row forum-grid">
        <div class="node-icon-cell">
            @if ($node->iconUrl())
                <span class="node-icon has-image"><img src="{{ $node->iconUrl() }}" alt="" loading="lazy"></span>
            @else
                <span @class(['node-icon', 'is-link' => $node->isLink(), 'is-category' => $node->isCategory(), 'has-color' => (bool) $node->color]) @if ($node->color) style="--node-color: {{ $node->color }}" @endif>
                    <x-icon :name="$node->icon ?: ($node->isLink() ? 'link' : ($node->isCategory() ? 'folder' : 'message'))" :size="20" />
                </span>
            @endif
        </div>
        <div>
            <a class="node-title" href="{{ route('nodes.show', $node) }}">{{ $node->title }}</a>
            @if ($node->description)<div class="node-desc">{{ $node->description }}</div>@endif
            @if ($row['children']->isNotEmpty())
                <div class="node-subs">
                    @foreach ($row['children'] as $child)
                        <a href="{{ route('nodes.show', $child) }}"><x-icon name="folder" :size="13" /> {{ $child->title }}</a>
                    @endforeach
                </div>
            @endif
        </div>
        <div class="node-last">
            @if ($node->isLink())
                <span class="muted">{{ __('External link') }}</span>
            @elseif ($row['lastThread'])
                {{-- The avatar is for themes that show one here; the classic look keeps it hidden (and so never loads the picture). --}}
                <x-avatar :user="$row['lastUser']" size="sm" :link="false" class="last-avatar" />
                <a class="title truncate" data-preview href="{{ route(auth()->check() ? 'threads.unread' : 'threads.show', $row['lastThread']) }}">{{ $row['lastThread']->title }}</a>
                <div class="by"><x-username :user="$row['lastUser']" class="truncate" /><x-time :at="$row['latest']->last_post_at" class="nowrap" /></div>
            @else
                <span class="muted">{{ __('No posts yet') }}</span>
            @endif
        </div>
        <div class="node-counts">
            @unless ($node->isLink())
                <span data-label="{{ __('Threads') }}" data-short="{{ \App\Forum\Home::short($row['threads']) }}">{{ forum_number($row['threads']) }}</span><i class="dot"></i><span data-label="{{ __('Posts') }}" data-short="{{ \App\Forum\Home::short($row['posts']) }}">{{ forum_number($row['posts']) }}</span>
            @endunless
        </div>
    </div>
@endforeach
