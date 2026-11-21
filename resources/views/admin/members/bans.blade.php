@extends('layouts.admin')

@section('title', __('Bans and IP addresses'))
@section('subtitle', __('Keep a network or an e-mail address out, and see which accounts used an address.'))

@section('content')
    <div class="grid-2">
        <div>
            <form class="block" method="post" action="{{ route('admin.bans.save') }}">
                @csrf
                <div class="block-head"><h2>{{ __('Add a ban') }}</h2></div>
                <div class="block-body">
                    <div class="form-grid">
                        <div class="field">
                            <label for="type">{{ __('Type') }}</label>
                            <select class="input" id="type" name="type">
                                <option value="ip">{{ __('IP address') }}</option>
                                <option value="email">{{ __('E-mail address') }}</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="value">{{ __('Value') }}</label>
                            <input class="input" id="value" name="value" value="{{ old('value') }}" maxlength="190" placeholder="203.0.113.* · *@example.com" required>
                            @error('value')<div class="error">{{ $message }}</div>@enderror
                        </div>
                        <div class="field">
                            <label for="reason">{{ __('Reason') }}</label>
                            <input class="input" id="reason" name="reason" value="{{ old('reason') }}" maxlength="255">
                        </div>
                        <div class="field">
                            <label for="days">{{ __('Days') }}</label>
                            <input class="input" id="days" type="number" name="days" min="1" max="3650" placeholder="{{ __('permanent') }}">
                        </div>
                    </div>
                    <div class="hint" style="margin-bottom: 12px">{{ __('"*" stands for any characters. A banned network cannot open the forum; a banned e-mail address cannot register.') }}</div>
                    <button class="btn btn-primary" type="submit">{{ __('Add ban') }}</button>
                </div>
            </form>

            <section class="block">
                <div class="block-head"><h2>{{ __('Active rules') }}</h2></div>
                @if ($bans->isEmpty())
                    <div class="empty">{{ __('There are no bans.') }}</div>
                @else
                    <div class="table-wrap">
                        <table class="table">
                            <thead><tr><th>{{ __('Type') }}</th><th>{{ __('Value') }}</th><th>{{ __('Reason') }}</th><th>{{ __('Ends') }}</th><th></th></tr></thead>
                            <tbody>
                                @foreach ($bans as $ban)
                                    <tr>
                                        <td>{{ $ban->type === 'ip' ? __('IP address') : __('E-mail address') }}</td>
                                        <td><code>{{ $ban->value }}</code></td>
                                        <td>{{ $ban->reason }}</td>
                                        <td class="nowrap">{{ $ban->expires_at ? $ban->expires_at->translatedFormat('j M Y') : __('permanent') }}</td>
                                        <td class="actions">
                                            <form method="post" action="{{ route('admin.bans.delete', $ban) }}" data-confirm="{{ __('Remove this ban?') }}">@csrf @method('DELETE')
                                                <button class="btn btn-sm btn-danger" type="submit"><x-icon name="trash" :size="14" /></button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        </div>

        <section class="block">
            <div class="block-head"><x-icon name="search" :size="16" /><h2>{{ __('Who used this IP address?') }}</h2></div>
            <form class="block-body row" method="get" action="{{ route('admin.bans') }}" style="flex-wrap: nowrap">
                <input class="input" name="ip" value="{{ $ip }}" placeholder="203.0.113.7 · 203.0.113.*" style="flex: 1" required>
                <button class="btn btn-primary" type="submit">{{ __('Search') }}</button>
            </form>
            @if ($ip !== '')
                @if ($matches->isEmpty())
                    <div class="empty">{{ __('No account has used this address.') }}</div>
                @else
                    <ul class="side-list" style="border-top: 1px solid var(--border)">
                        @foreach ($matches as $member)
                            <li>
                                <x-avatar :user="$member" size="sm" />
                                <div class="meta" style="flex: 1"><x-username :user="$member" /><div>{{ $member->last_ip }} · {{ $member->created_at->translatedFormat('j M Y') }}@if ($member->isBanned()) · <span class="badge red">{{ __('Banned') }}</span>@endif</div></div>
                                <a class="btn btn-sm" href="{{ route('admin.users.edit', $member) }}"><x-icon name="edit" :size="14" /></a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            @endif
        </section>
    </div>
@endsection
