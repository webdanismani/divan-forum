{{-- Account settings: which alerts and how, changing the username, asking for the account to be deleted. --}}
<form class="block" id="alerts" method="post" action="{{ route('account.alerts') }}" data-check-group>
    @csrf @method('PUT')
    <div class="block-head"><x-icon name="bell" :size="16" /><h2>{{ __('Alert preferences') }}</h2></div>
    <div class="check-tools" style="margin: 0; padding: 8px 14px; border-bottom: 1px solid var(--border)">
        <button class="btn btn-sm" type="button" data-check="all"><x-icon name="check" :size="13" /> {{ __('Select all') }}</button>
        <button class="btn btn-sm" type="button" data-check="none"><x-icon name="x" :size="13" /> {{ __('Clear all') }}</button>
    </div>
    <div class="table-wrap">
        <table class="table prefs-table">
            <thead>
                <tr>
                    <th>{{ __('Tell me about') }}</th>
                    @foreach (\App\Forum\AlertPrefs::CHANNELS as $channel => $label)
                        <th class="center">{{ __($label) }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach (\App\Forum\AlertPrefs::GROUPS as $group => [$label])
                    <tr>
                        <td>{{ __($label) }}</td>
                        @foreach (array_keys(\App\Forum\AlertPrefs::CHANNELS) as $channel)
                            @php $fixed = $channel === 'site' && in_array($group, \App\Forum\AlertPrefs::ALWAYS_ON_SITE, true); @endphp
                            <td class="center">
                                <input type="checkbox" name="alerts[{{ $group }}][{{ $channel }}]" value="1" aria-label="{{ __($label) }} — {{ __(\App\Forum\AlertPrefs::CHANNELS[$channel]) }}"
                                    @checked($alertPrefs[$group][$channel]) @disabled($fixed)>
                                @if ($fixed)<input type="hidden" name="alerts[{{ $group }}][{{ $channel }}]" value="1">@endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="block-foot row">
        <button class="btn btn-primary" type="submit">{{ __('Save') }}</button>
        <span class="muted small">{{ __('A browser notification belongs to an alert on the site, so it needs that box too. Browser notifications are switched on per device further down.') }}</span>
    </div>
</form>

@if ($canRename)
    <form class="block" id="username" method="post" action="{{ route('account.rename') }}">
        @csrf @method('PUT')
        <div class="block-head"><x-icon name="user" :size="16" /><h2>{{ __('Username') }}</h2></div>
        <div class="block-body">
            @if ($renameWait)
                <p class="muted" style="margin: 0">{{ __('You changed your username recently. You can change it again on :date.', ['date' => $renameWait->translatedFormat('j F Y')]) }}</p>
            @else
                <div class="form-grid">
                    <div class="field">
                        <label for="new-username">{{ __('New username') }}</label>
                        <input class="input" id="new-username" name="username" value="{{ old('username', $user->username) }}" minlength="3" maxlength="30" required>
                        @error('username')<div class="error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="rename-password">{{ __('Current password') }}</label>
                        <input class="input" id="rename-password" type="password" name="current_password" autocomplete="current-password" required>
                        @error('current_password')<div class="error">{{ $message }}</div>@enderror
                    </div>
                </div>
                <button class="btn btn-primary" type="submit">{{ __('Change username') }}</button>
                <div class="hint" style="margin-top: 8px">{{ __('Links to your profile keep working.') }} @if ($renameDays > 0){{ __('After a change you wait :count days before the next one.', ['count' => $renameDays]) }}@endif</div>
            @endif
        </div>
    </form>
@endif

@if ($canDelete)
    <section class="block" id="delete-account">
        <div class="block-head"><x-icon name="trash" :size="16" /><h2>{{ __('Delete my account') }}</h2></div>
        @if ($user->deletion_requested_at)
            <div class="block-body">
                <div class="flash info" style="margin: 0 0 10px">{{ __('You asked for your account to be deleted on :date. An administrator will carry it out.', ['date' => $user->deletion_requested_at->translatedFormat('j F Y')]) }}</div>
                <form method="post" action="{{ route('account.delete.cancel') }}">@csrf @method('DELETE')
                    <button class="btn" type="submit">{{ __('I changed my mind: keep my account') }}</button>
                </form>
            </div>
        @else
            <form class="block-body" method="post" action="{{ route('account.delete') }}" data-confirm="{{ __('Ask for your account to be deleted?') }}">
                @csrf
                <p class="muted" style="margin: 0 0 10px">{{ __('Your name, e-mail address, profile and settings are removed and the account is closed. Your threads and posts stay on the forum without your name. An administrator carries out the request; until then you can take it back.') }}</p>
                <div class="form-grid">
                    <div class="field">
                        <label for="delete-reason">{{ __('Reason') }} <span class="muted">({{ __('optional') }})</span></label>
                        <input class="input" id="delete-reason" name="reason" maxlength="300" value="{{ old('reason') }}">
                    </div>
                    <div class="field">
                        <label for="delete-password">{{ __('Current password') }}</label>
                        <input class="input" id="delete-password" type="password" name="current_password" autocomplete="current-password" required>
                        @error('current_password')<div class="error">{{ $message }}</div>@enderror
                    </div>
                </div>
                <button class="btn btn-danger" type="submit">{{ __('Ask for deletion') }}</button>
            </form>
        @endif
    </section>
@endif
