@extends('layouts.admin')

@section('title', __('Members'))
@section('actions')
    <form class="row" method="get" action="{{ route('admin.users.index') }}">
        <input class="input" style="width: 240px" type="search" name="q" value="{{ $search }}" placeholder="{{ __('Username or e-mail') }}">
        <button class="btn" type="submit"><x-icon name="search" :size="16" /></button>
    </form>
@endsection

@section('content')
    <nav class="tabs">
        <a href="{{ route('admin.users.index') }}" @class(['active' => request('filter') !== 'banned'])>{{ __('All members') }}</a>
        <a href="{{ route('admin.users.index', ['filter' => 'banned']) }}" @class(['active' => request('filter') === 'banned'])>{{ __('Banned') }}</a>
    </nav>

    <section class="block">
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('Member') }}</th><th>{{ __('E-mail address') }}</th><th>{{ __('Group') }}</th><th>{{ __('Posts') }}</th><th>{{ __('Joined') }}</th><th>{{ __('Last seen') }}</th><th></th></tr></thead>
                <tbody>
                    @forelse ($users as $user)
                        <tr>
                            <td>
                                <div class="row" style="flex-wrap: nowrap">
                                    <x-avatar :user="$user" size="sm" :link="false" />
                                    <a href="{{ route('admin.users.edit', $user) }}" style="font-weight: 650">{{ $user->username }}</a>
                                    @if ($user->isBanned())<span class="badge red">{{ __('Banned') }}</span>@endif
                                </div>
                            </td>
                            <td>{{ $user->email }}</td>
                            <td>{{ $user->group?->name }}</td>
                            <td>{{ number_format($user->post_count) }}</td>
                            <td class="nowrap">{{ $user->created_at->translatedFormat('j M Y') }}</td>
                            <td class="nowrap">@if ($user->last_seen_at)<x-time :at="$user->last_seen_at" />@else — @endif</td>
                            <td class="actions"><a class="btn btn-sm" href="{{ route('admin.users.edit', $user) }}">{{ __('Edit') }}</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="empty">{{ __('No members found.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{ $users->links() }}
@endsection
