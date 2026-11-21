@extends('layouts.admin')

@section('title', __('Nodes'))
@section('subtitle', __('Categories, forums and link forums that make up the board structure.'))
@section('actions')
    <a class="btn btn-primary" href="{{ route('admin.nodes.create') }}"><x-icon name="plus" :size="16" /> {{ __('Add node') }}</a>
@endsection

@section('content')
    <section class="block">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr><th>{{ __('Title') }}</th><th>{{ __('Type') }}</th><th>{{ __('Order') }}</th><th>{{ __('Threads') }}</th><th>{{ __('Posts') }}</th><th></th></tr>
                </thead>
                <tbody>
                    @forelse ($items as $item)
                        @php $node = $item['node']; @endphp
                        <tr>
                            <td>
                                <span class="tree-indent" style="--depth: {{ $item['depth'] }}"></span>
                                <x-icon :name="$node->isLink() ? 'link' : ($node->isCategory() ? 'folder' : 'message')" :size="16" />
                                <a href="{{ route('admin.nodes.edit', $node) }}" style="font-weight: 600">{{ $node->title }}</a>
                                @unless ($node->is_active)<span class="badge red">{{ __('Disabled') }}</span>@endunless
                                @if ($node->require_approval)<span class="badge amber">{{ __('Moderated') }}</span>@endif
                            </td>
                            <td>{{ __(ucfirst($node->type)) }}</td>
                            <td>{{ $node->display_order }}</td>
                            <td>{{ number_format($node->thread_count) }}</td>
                            <td>{{ number_format($node->post_count) }}</td>
                            <td class="actions">
                                <a class="btn btn-sm" href="{{ route('admin.nodes.permissions', $node) }}"><x-icon name="shield" :size="14" /> {{ __('Permissions') }}</a>
                                <a class="btn btn-sm" href="{{ route('admin.nodes.edit', $node) }}">{{ __('Edit') }}</a>
                                @if ($node->isCategory() || $node->isForum())
                                    <a class="btn btn-sm" href="{{ route('admin.nodes.create', ['parent' => $node->id]) }}" title="{{ __('Add child node') }}"><x-icon name="plus" :size="14" /></a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="empty">{{ __('There are no nodes yet.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
