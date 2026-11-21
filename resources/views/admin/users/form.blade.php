@extends('layouts.admin')

@section('title', __('Edit member'))
@section('subtitle', $user->username)
@section('actions')
    <a class="btn" href="{{ route('members.show', $user) }}" target="_blank">{{ __('View profile') }}</a>
@endsection

@php $self = $user->is(auth()->user()); @endphp

@section('content')
    <form method="post" action="{{ route('admin.users.update', $user) }}">
        @csrf @method('PUT')

        <div class="grid-2">
            <section class="block">
                <div class="block-head"><h2>{{ __('Account') }}</h2></div>
                <div class="block-body">
                    <div class="field">
                        <label for="username">{{ __('Username') }}</label>
                        <input class="input" id="username" name="username" value="{{ old('username', $user->username) }}" required>
                    </div>
                    <div class="field">
                        <label for="email">{{ __('E-mail address') }}</label>
                        <input class="input" id="email" type="email" name="email" value="{{ old('email', $user->email) }}" required>
                        <label class="check" style="margin-top: 6px"><input type="checkbox" name="email_verified" value="1" @checked(old('email_verified', $user->isVerified()))> {{ __('E-mail address confirmed') }}</label>
                    </div>
                    <div class="field">
                        <label for="custom_title">{{ __('Custom title') }}</label>
                        <input class="input" id="custom_title" name="custom_title" value="{{ old('custom_title', $user->custom_title) }}" maxlength="60">
                    </div>
                    <div class="field">
                        <label for="password">{{ __('New password') }}</label>
                        <input class="input" id="password" type="password" name="password" autocomplete="new-password">
                        <div class="hint">{{ __('Leave empty to keep the current password.') }}</div>
                    </div>
                    @if ($twoFactor)
                        <div class="field">
                            <label class="check"><input type="checkbox" name="reset_two_factor" value="1"> {{ __('Switch two-step sign-in off for this member (lost phone)') }}</label>
                        </div>
                    @endif
                    <dl class="kv small">
                        <div><dt>{{ __('Joined') }}</dt><dd>{{ $user->created_at->translatedFormat('j F Y H:i') }}</dd></div>
                        <div><dt>{{ __('Last IP address') }}</dt><dd>{{ $user->last_ip ?: '—' }}</dd></div>
                        <div><dt>{{ __('Registration IP address') }}</dt><dd>{{ $user->registration_ip ?: '—' }}</dd></div>
                        <div><dt>{{ __('Warning points') }}</dt><dd>{{ $user->warning_points }}</dd></div>
                        <div><dt>{{ __('Balance') }}</dt><dd>{{ forum_money($user->balance) }}</dd></div>
                    </dl>
                </div>
            </section>

            <div>
                <section class="block">
                    <div class="block-head"><h2>{{ __('User groups') }}</h2></div>
                    <div class="block-body">
                        @if ($self)
                            <div class="flash info">{{ __('You cannot change your own groups.') }}</div>
                        @endif
                        <div class="field">
                            <label for="user_group_id">{{ __('Primary group') }}</label>
                            <select class="input" id="user_group_id" name="user_group_id" @disabled($self)>
                                @foreach ($groups as $group)
                                    <option value="{{ $group->id }}" @selected((int) old('user_group_id', $user->user_group_id) === $group->id)>{{ $group->name }}</option>
                                @endforeach
                            </select>
                            @if ($self)<input type="hidden" name="user_group_id" value="{{ $user->user_group_id }}">@endif
                        </div>
                        <span class="label">{{ __('Secondary groups') }}</span>
                        @foreach ($groups as $group)
                            <label class="check" style="margin-bottom: 6px">
                                <input type="checkbox" name="secondary[]" value="{{ $group->id }}" @checked($user->groups->contains($group->id)) @disabled($self)> {{ $group->name }}
                            </label>
                        @endforeach
                    </div>
                </section>

                <section class="block" style="margin-top: 16px">
                    <div class="block-head"><h2>{{ __('Ban') }}</h2></div>
                    <div class="block-body">
                        <div class="field">
                            <label class="check"><input type="checkbox" name="is_banned" value="1" @checked(old('is_banned', $user->is_banned)) @disabled($self)> {{ __('This member is banned') }}</label>
                        </div>
                        <div class="field">
                            <label for="ban_reason">{{ __('Reason') }}</label>
                            <input class="input" id="ban_reason" name="ban_reason" value="{{ old('ban_reason', $user->ban_reason) }}" maxlength="255">
                        </div>
                        <div class="field">
                            <label for="banned_until">{{ __('Ban ends') }}</label>
                            <input class="input" id="banned_until" type="datetime-local" name="banned_until" value="{{ old('banned_until', $user->banned_until?->format('Y-m-d\TH:i')) }}">
                            <div class="hint">{{ __('Leave empty for a permanent ban.') }}</div>
                        </div>
                    </div>
                </section>
            </div>
        </div>

        <div class="form-actions" style="margin-top: 16px">
            <button class="btn btn-primary" type="submit">{{ __('Save') }}</button>
            <a class="btn" href="{{ route('admin.users.index') }}">{{ __('Cancel') }}</a>
        </div>
    </form>

    @if ($linked ?? [])
        <section class="block" style="margin-top: 16px" id="linked">
            <div class="block-head"><x-icon name="users" :size="16" /><h2>{{ __('Accounts linked to this one') }}</h2><span class="spacer"></span><a class="muted" href="{{ route('admin.duplicates') }}">{{ __('All shared accounts') }}</a></div>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>{{ __('Member') }}</th><th>{{ __('Sign') }}</th><th>{{ __('Group') }}</th><th>{{ __('Posts') }}</th><th>{{ __('Joined') }}</th></tr></thead>
                    <tbody>
                        @foreach ($linked as $link)
                            <tr>
                                <td><a href="{{ route('admin.users.edit', $link['user']) }}"><b>{{ $link['user']->username }}</b></a>@if ($link['user']->is_banned) <span class="badge red">{{ __('Banned') }}</span>@endif</td>
                                <td>
                                    @if ($link['device'])<span class="badge red">{{ __('Same browser') }}</span>@endif
                                    @if ($link['ips'])<span class="badge amber">{{ __(':count shared IP addresses', ['count' => $link['ips']]) }}</span>@endif
                                </td>
                                <td>{{ $link['user']->group?->name }}</td>
                                <td>{{ forum_number($link['user']->post_count) }}</td>
                                <td class="nowrap">{{ $link['user']->created_at?->translatedFormat('j M Y') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <div class="grid-2" style="margin-top: 16px">
        <section class="block">
            <div class="block-head"><h2>{{ __('IP addresses') }}</h2></div>
            @if ($ipLog->isEmpty())
                <div class="empty">{{ __('Nothing recorded yet.') }}</div>
            @else
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>{{ __('IP address') }}</th><th>{{ __('Action') }}</th><th>{{ __('Date') }}</th></tr></thead>
                        <tbody>
                            @foreach ($ipLog as $row)
                                <tr>
                                    <td><a href="{{ route('admin.bans', ['ip' => $row->ip]) }}">{{ $row->ip }}</a></td>
                                    <td>{{ ['register' => __('Registration'), 'login' => __('Sign-in'), 'post' => __('Post')][$row->action] ?? $row->action }}</td>
                                    <td class="nowrap">{{ \Illuminate\Support\Carbon::parse($row->created_at)->translatedFormat('j M Y H:i') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="block">
            <div class="block-head"><x-icon name="star" :size="16" /><h2>{{ __('Badges') }}</h2></div>
            @if ($badges->isEmpty())
                <div class="empty">{{ __('Nothing to show yet.') }}</div>
            @else
                <ul class="side-list">
                    @foreach ($badges as $badge)
                        <li>
                            <span class="badge-icon">{{ $badge->icon }}</span>
                            <div class="meta" style="flex: 1"><span class="title">{{ $badge->title }}</span>{{ $badge->description }}</div>
                            <form method="post" action="{{ route('admin.users.badge', $user) }}">@csrf
                                <input type="hidden" name="badge_id" value="{{ $badge->id }}">
                                <button @class(['btn', 'btn-sm', 'btn-danger' => in_array($badge->id, $ownBadges)]) type="submit">{{ in_array($badge->id, $ownBadges) ? __('Take back') : __('Give') }}</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    @if (setting('wallet.enabled'))
        <form class="block medium" style="margin: 16px 0 0" method="post" action="{{ route('admin.users.balance', $user) }}">
            @csrf
            <div class="block-head"><x-icon name="wallet" :size="16" /><h2>{{ __('Balance') }}: {{ forum_money($user->balance) }}</h2></div>
            <div class="block-body">
                <div class="row" style="align-items: flex-end">
                    <div class="field" style="margin: 0; width: 160px">
                        <label for="balance-amount">{{ __('Amount') }} (+ / −)</label>
                        <input class="input" id="balance-amount" type="number" step="0.01" name="amount" placeholder="100" required>
                    </div>
                    <div class="field" style="margin: 0; flex: 1; min-width: 200px">
                        <label for="balance-note">{{ __('Description') }}</label>
                        <input class="input" id="balance-note" name="note" maxlength="200" required>
                    </div>
                    <button class="btn" type="submit">{{ __('Update balance') }}</button>
                </div>
                @error('balance')<div class="field" style="margin: 8px 0 0"><div class="error">{{ $message }}</div></div>@enderror
            </div>
        </form>
    @endif

    @unless ($self)
        <form style="margin-top: 16px" method="post" action="{{ route('admin.users.destroy', $user) }}" data-confirm="{{ __('Delete this member? Their threads and posts are kept without an author.') }}">
            @csrf @method('DELETE')
            <button class="btn btn-danger" type="submit"><x-icon name="trash" :size="16" /> {{ __('Delete member') }}</button>
        </form>
    @endunless
@endsection
