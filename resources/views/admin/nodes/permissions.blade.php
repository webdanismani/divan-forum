@extends('layouts.admin')

@section('title', __('Node permissions'))
@section('subtitle', $node->title)
@section('actions')
    <a class="btn" href="{{ route('admin.nodes.index') }}">{{ __('Back to nodes') }}</a>
@endsection

@section('content')
    <section class="block" style="margin-bottom: 14px">
        <div class="block-head"><x-icon name="shield" :size="16" /><h2>{{ __('Moderators of this forum') }}</h2></div>
        @if ($moderators->isNotEmpty())
            <ul class="side-list">
                @foreach ($moderators as $moderator)
                    <li>
                        <x-avatar :user="$moderator" size="sm" />
                        <div class="meta" style="flex: 1"><x-username :user="$moderator" /></div>
                        <form method="post" action="{{ route('admin.nodes.moderators.remove', [$node, $moderator]) }}">@csrf @method('DELETE')
                            <button class="btn btn-sm btn-danger" type="submit" title="{{ __('Remove') }}"><x-icon name="trash" :size="14" /></button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif
        <form class="block-foot row" method="post" action="{{ route('admin.nodes.moderators.add', $node) }}">
            @csrf
            <input class="input" name="username" maxlength="30" placeholder="{{ __('Username') }}" style="max-width: 260px" required>
            <button class="btn btn-primary" type="submit">{{ __('Add moderator') }}</button>
            <span class="muted small" style="flex: 1; min-width: 220px">{{ __('A forum moderator can edit, delete, approve, lock, pin, move and merge here and in the sub-forums, whatever group they are in.') }}</span>
        </form>
    </section>
    <div class="flash info">
        {{ __('"Inherit" uses the value of the parent node or, failing that, of the user group. "Never" always wins over "Allow". Administrators are not listed because they can do everything.') }}
    </div>

    <form method="post" action="{{ route('admin.nodes.permissions.update', $node) }}">
        @csrf @method('PUT')
        <section class="block">
            <div class="table-wrap">
                <table class="table perm-table">
                    <thead>
                        <tr>
                            <th>{{ __('Permission') }}</th>
                            @foreach ($groups as $group)
                                <th>{{ $group->name }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($catalogue as $section => $permissions)
                            <tr class="group-row"><td colspan="{{ $groups->count() + 1 }}">{{ __($section) }}</td></tr>
                            @foreach ($permissions as $key => $permission)
                                <tr>
                                    <td>{{ __($permission['label']) }}</td>
                                    @foreach ($groups as $group)
                                        <td>
                                            @include('admin.partials.permission-select', [
                                                'name' => "perms[{$group->id}][{$key}]",
                                                'value' => $entries->get($group->id)?->permissions[$key] ?? null,
                                                'unsetLabel' => __('Inherit'),
                                            ])
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="block-foot"><button class="btn btn-primary" type="submit">{{ __('Save permissions') }}</button></div>
        </section>
    </form>
@endsection
