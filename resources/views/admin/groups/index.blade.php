@extends('layouts.admin')

@section('title', __('User groups'))
@section('subtitle', __('Groups decide what members may do. A member has one primary group and any number of secondary groups.'))
@section('actions')
    <a class="btn btn-primary" href="{{ route('admin.groups.create') }}"><x-icon name="plus" :size="16" /> {{ __('Add group') }}</a>
@endsection

@section('content')
    <section class="block">
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('Group') }}</th><th>{{ __('Badge') }}</th><th>{{ __('Members') }}</th><th>{{ __('Priority') }}</th><th></th></tr></thead>
                <tbody>
                    @foreach ($groups as $group)
                        <tr>
                            <td>
                                <a href="{{ route('admin.groups.edit', $group) }}" style="font-weight: 650; {{ $group->color ? 'color: '.$group->color : '' }}">{{ $group->name }}</a>
                                @if ($group->isSystem())<span class="badge">{{ __('System') }}</span>@endif
                                @if ($group->is_staff)<span class="badge blue">{{ __('Staff') }}</span>@endif
                            </td>
                            <td>{{ $group->badge ?: '—' }}</td>
                            <td>{{ $group->system_key === 'guest' ? '—' : number_format($group->users_count) }}</td>
                            <td>{{ $group->priority }}</td>
                            <td class="actions"><a class="btn btn-sm" href="{{ route('admin.groups.edit', $group) }}">{{ __('Edit') }}</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endsection
