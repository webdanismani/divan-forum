<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('Installation') }} · {{ config('forum.product.name') }}</title>
    <link rel="stylesheet" href="{{ asset('assets/forum.css') }}">
</head>
<body>
<main class="install-page">
    <h1><span class="brand-mark"><x-icon name="message" :size="20" /></span> {{ config('forum.product.name') }} {{ config('forum.product.version') }} · {{ __('Installation') }}</h1>

    @if ($messages)
        <div class="flash error" role="alert"><ul>@foreach ($messages as $message)<li>{{ $message }}</li>@endforeach</ul></div>
    @endif

    <section class="block">
        <div class="block-head"><h2>1. {{ __('Requirements') }}</h2></div>
        <div class="block-body">
            <ul class="req-list">
                @foreach ($checks as [$label, $ok, $detail])
                    <li class="{{ $ok ? 'ok' : 'bad' }}"><x-icon :name="$ok ? 'check' : 'x'" :size="15" /> <span>{{ $label }}@if ($detail) <span class="muted">· {{ $detail }}</span>@endif</span></li>
                @endforeach
            </ul>
            @unless ($passes)
                <div class="flash error" style="margin: 12px 0 0">{{ __('Please fix the requirements marked in red first.') }}</div>
            @endunless
        </div>
    </section>

    <form method="post" action="{{ url('install') }}">
        <section class="block">
            <div class="block-head"><h2>2. {{ __('Licence') }}</h2></div>
            <div class="block-body">
                @unless ($licenseReady)
                    <div class="flash error" style="margin: 0 0 12px">{{ __('The licence server is not set up in this copy of the software, so it cannot be installed.') }}</div>
                @endunless
                <div class="field" style="margin-bottom: 0">
                    <label for="license_key">{{ __('Licence code') }}</label>
                    <input class="input" id="license_key" name="license_key" value="{{ $input['license_key'] ?? '' }}" maxlength="64" autocomplete="off" spellcheck="false" required>
                    <div class="hint">{{ __('The installation does not continue without a valid licence code. The code is checked with the licence server and tied to the forum address you enter below; you get it from :vendor.', ['vendor' => config('forum.product.vendor')]) }}
                        @if ($getUrl = config('forum.license.get_url'))<a href="{{ $getUrl }}" target="_blank" rel="noopener">{{ __('Get a free licence code') }}</a>@endif
                    </div>
                </div>
            </div>
        </section>

        <section class="block">
            <div class="block-head"><h2>3. {{ __('Forum') }}</h2></div>
            <div class="block-body">
                <div class="form-grid">
                    <div class="field">
                        <label for="board_name">{{ __('Board name') }}</label>
                        <input class="input" id="board_name" name="board_name" value="{{ $input['board_name'] }}" maxlength="80" required>
                    </div>
                    <div class="field">
                        <label for="locale">{{ __('Default language') }}</label>
                        <select class="input" id="locale" name="locale">
                            <option value="tr" @selected($input['locale'] === 'tr')>Türkçe</option>
                            <option value="en" @selected($input['locale'] === 'en')>English</option>
                        </select>
                    </div>
                </div>
                <div class="field" style="margin-bottom: 0">
                    <label for="url">{{ __('Forum address') }}</label>
                    <input class="input" id="url" type="url" name="url" value="{{ $input['url'] }}" required>
                </div>
            </div>
        </section>

        <section class="block">
            <div class="block-head"><h2>4. {{ __('Database') }}</h2></div>
            <div class="block-body">
                <div class="field">
                    <label for="db_driver">{{ __('Database type') }}</label>
                    <select class="input" id="db_driver" name="db_driver">
                        <option value="mysql" @selected($input['db_driver'] === 'mysql')>MySQL / MariaDB</option>
                        <option value="sqlite" @selected($input['db_driver'] === 'sqlite')>SQLite ({{ __('a file on this server, for small boards') }})</option>
                    </select>
                </div>
                <div class="form-grid">
                    <div class="field">
                        <label for="db_host">{{ __('Server') }}</label>
                        <input class="input" id="db_host" name="db_host" value="{{ $input['db_host'] }}">
                    </div>
                    <div class="field">
                        <label for="db_port">{{ __('Port') }}</label>
                        <input class="input" id="db_port" type="number" name="db_port" value="{{ $input['db_port'] }}">
                    </div>
                    <div class="field">
                        <label for="db_name">{{ __('Database name') }}</label>
                        <input class="input" id="db_name" name="db_name" value="{{ $input['db_name'] ?? '' }}">
                    </div>
                    <div class="field">
                        <label for="db_user">{{ __('Database user') }}</label>
                        <input class="input" id="db_user" name="db_user" value="{{ $input['db_user'] ?? '' }}" autocomplete="off">
                    </div>
                </div>
                <div class="field" style="margin-bottom: 0">
                    <label for="db_password">{{ __('Database password') }}</label>
                    <input class="input" id="db_password" type="password" name="db_password" autocomplete="new-password">
                    <div class="hint">{{ __('The database must exist already; the installer creates the tables.') }}</div>
                </div>
            </div>
        </section>

        <section class="block">
            <div class="block-head"><h2>5. {{ __('Administrator account') }}</h2></div>
            <div class="block-body">
                <div class="form-grid">
                    <div class="field">
                        <label for="admin_username">{{ __('Username') }}</label>
                        <input class="input" id="admin_username" name="admin_username" value="{{ $input['admin_username'] ?? '' }}" minlength="3" maxlength="30" required>
                    </div>
                    <div class="field">
                        <label for="admin_email">{{ __('E-mail address') }}</label>
                        <input class="input" id="admin_email" type="email" name="admin_email" value="{{ $input['admin_email'] ?? '' }}" required>
                    </div>
                    <div class="field">
                        <label for="admin_password">{{ __('Password') }}</label>
                        <input class="input" id="admin_password" type="password" name="admin_password" minlength="8" autocomplete="new-password" required>
                    </div>
                    <div class="field">
                        <label for="admin_password_confirmation">{{ __('Confirm password') }}</label>
                        <input class="input" id="admin_password_confirmation" type="password" name="admin_password_confirmation" autocomplete="new-password" required>
                    </div>
                </div>
            </div>
        </section>

        <div class="form-actions" style="margin-top: 16px">
            <button class="btn btn-primary" style="height: 42px; padding: 0 22px" type="submit" @disabled(! $passes || ! $licenseReady)>{{ __('Install') }}</button>
        </div>
    </form>
</main>
</body>
</html>
