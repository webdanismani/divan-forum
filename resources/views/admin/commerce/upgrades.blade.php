@extends('layouts.admin')

@section('title', __('Membership packages'))
@section('subtitle', __('A package puts the buyer in a user group for a period. Give that group its privileges under User groups.'))

@section('content')
    @if ($groups->isEmpty())
        <div class="flash info">{{ __('Create a user group first (for example "VIP"): packages can only grant groups you have made yourself.') }} <a href="{{ route('admin.groups.create') }}">{{ __('Add group') }}</a></div>
    @else
        @foreach ($upgrades->concat([null]) as $upgrade)
            <form class="block" method="post" action="{{ route('admin.upgrades.save') }}">
                @csrf
                @if ($upgrade)<input type="hidden" name="id" value="{{ $upgrade->id }}">@endif
                <div class="block-head">
                    <h2>{{ $upgrade ? $upgrade->title : __('New package') }}</h2>
                    @if ($upgrade)<span class="spacer"></span><span class="muted">{{ __(':count active members', ['count' => $active[$upgrade->id] ?? 0]) }}</span>@endif
                </div>
                <div class="block-body">
                    <div class="form-grid" style="grid-template-columns: 2fr 1fr 1fr 1fr">
                        <div class="field">
                            <label>{{ __('Title') }}</label>
                            <input class="input" name="title" value="{{ $upgrade?->title }}" maxlength="100" required>
                        </div>
                        <div class="field">
                            <label>{{ __('Price') }} ({{ config('forum.ads.currency_symbol') }})</label>
                            <input class="input" type="number" step="0.01" name="price" value="{{ $upgrade?->price }}" min="0" required>
                        </div>
                        <div class="field">
                            <label>{{ __('Period (days)') }}</label>
                            <input class="input" type="number" name="days" value="{{ $upgrade?->days ?? 30 }}" min="0" max="3650" required>
                            <div class="hint">{{ __('0 = permanent') }}</div>
                        </div>
                        <div class="field">
                            <label>{{ __('User group') }}</label>
                            <select class="input" name="user_group_id" required>
                                @foreach ($groups as $group)
                                    <option value="{{ $group->id }}" @selected($upgrade?->user_group_id === $group->id)>{{ $group->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="field">
                        <label>{{ __('Description') }}</label>
                        <textarea class="input" name="description" maxlength="1000" style="min-height: 60px">{{ $upgrade?->description }}</textarea>
                    </div>
                    <div class="row">
                        <label class="check"><input type="checkbox" name="is_active" value="1" @checked($upgrade?->is_active ?? true)> {{ __('On sale') }}</label>
                        <span class="spacer"></span>
                        <label class="muted">{{ __('Order') }} <input class="input" style="width: 80px; display: inline-block" type="number" name="display_order" value="{{ $upgrade?->display_order ?? 0 }}" min="0"></label>
                        <button class="btn btn-primary" type="submit">{{ $upgrade ? __('Save') : __('Add') }}</button>
                        @if ($upgrade)
                            <button class="btn btn-danger" type="submit" form="delete-upgrade-{{ $upgrade->id }}" title="{{ __('Delete') }}"><x-icon name="trash" :size="15" /></button>
                        @endif
                    </div>
                </div>
            </form>
            @if ($upgrade)
                <form id="delete-upgrade-{{ $upgrade->id }}" method="post" action="{{ route('admin.upgrades.delete', $upgrade) }}" data-confirm="{{ __('Delete this package? Members who bought it keep their group until it is removed by hand.') }}">@csrf @method('DELETE')</form>
            @endif
        @endforeach
    @endif

    <section class="block">
        <div class="block-head"><h2>{{ __('Recent purchases') }}</h2></div>
        @if ($purchases->isEmpty())
            <div class="empty">{{ __('Nothing to show yet.') }}</div>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>{{ __('Member') }}</th><th>{{ __('Membership package') }}</th><th>{{ __('Price') }}</th><th>{{ __('Starts') }}</th><th>{{ __('Ends') }}</th><th>{{ __('Status') }}</th></tr></thead>
                    <tbody>
                        @foreach ($purchases as $row)
                            <tr>
                                <td><x-username :user="$row->user" /></td>
                                <td>{{ $row->upgrade?->title ?? '—' }}</td>
                                <td class="nowrap">{{ forum_money($row->price) }}</td>
                                <td class="nowrap">{{ $row->starts_at->translatedFormat('j M Y') }}</td>
                                <td class="nowrap">{{ $row->ends_at?->translatedFormat('j M Y') ?? __('permanent') }}</td>
                                <td><span class="badge {{ $row->status === 'active' ? 'green' : '' }}">{{ $row->status === 'active' ? __('Active') : __('Expired') }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
