@extends('layouts.app')

@section('title', __('Two-step sign-in'))
@section('crumb', __('Two-step sign-in'))

@section('content')
    <div class="medium stack">
        <div class="page-head" style="margin-bottom: 0">
            <div class="titles">
                <h1>{{ __('Two-step sign-in') }} @if ($enabled)<span class="badge green" style="vertical-align: 3px">{{ __('On') }}</span>@else<span class="badge" style="vertical-align: 3px">{{ __('Off') }}</span>@endif</h1>
                <div class="desc">{{ __('After your password, sign-in also asks for a code from an authenticator app on your phone (Google Authenticator, Microsoft Authenticator, Authy…).') }}</div>
            </div>
            <a class="btn" href="{{ route('account.edit') }}">{{ __('Account settings') }}</a>
        </div>

        @if ($codes)
            <section class="block">
                <div class="block-head"><x-icon name="key" :size="16" /><h2>{{ __('Recovery codes') }}</h2></div>
                <div class="block-body">
                    <div class="flash info">{{ __('Write these codes down now: they are shown only once. Each one signs you in a single time if you lose your phone.') }}</div>
                    <pre class="log-box" style="font-size: 15px; letter-spacing: .05em">{{ implode("\n", $codes) }}</pre>
                </div>
            </section>
        @endif

        @if ($enabled)
            <form class="block" method="post" action="{{ route('two-factor.destroy') }}" data-confirm="{{ __('Switch two-step sign-in off?') }}">
                @csrf @method('DELETE')
                <div class="block-head"><h2>{{ __('Switch off') }}</h2></div>
                <div class="block-body">
                    <p class="muted">{{ __('Recovery codes left: :count', ['count' => $codesLeft]) }}</p>
                    <div class="field" style="max-width: 320px">
                        <label for="current_password">{{ __('Current password') }}</label>
                        <input class="input" id="current_password" type="password" name="current_password" autocomplete="current-password" required>
                        @error('current_password')<div class="error">{{ $message }}</div>@enderror
                    </div>
                    <button class="btn btn-danger" type="submit">{{ __('Switch two-step sign-in off') }}</button>
                </div>
            </form>
        @elseif ($secret)
            <form class="block" method="post" action="{{ route('two-factor.confirm') }}">
                @csrf @method('PUT')
                <div class="block-head"><h2>{{ __('Scan and confirm') }}</h2></div>
                <div class="block-body">
                    <div class="row" style="align-items: flex-start; gap: 20px">
                        <div class="qr-box">{!! $qr !!}</div>
                        <div style="flex: 1; min-width: 240px">
                            <p>{{ __('1. Scan the code with your authenticator app, or type this key into it:') }}</p>
                            <p><code style="font-size: 15px; letter-spacing: .08em; overflow-wrap: anywhere">{{ trim(chunk_split($secret, 4, ' ')) }}</code></p>
                            <p>{{ __('2. Enter the 6-digit code the app shows:') }}</p>
                            <div class="field" style="max-width: 200px">
                                <input class="input" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="10" required autofocus aria-label="{{ __('Code') }}">
                                @error('code')<div class="error">{{ $message }}</div>@enderror
                            </div>
                            <button class="btn btn-primary" type="submit">{{ __('Switch two-step sign-in on') }}</button>
                        </div>
                    </div>
                </div>
            </form>
        @else
            <form class="block" method="post" action="{{ route('two-factor.start') }}">
                @csrf
                <div class="block-head"><h2>{{ __('Switch on') }}</h2></div>
                <div class="block-body">
                    <div class="field" style="max-width: 320px">
                        <label for="current_password">{{ __('Current password') }}</label>
                        <input class="input" id="current_password" type="password" name="current_password" autocomplete="current-password" required>
                        @error('current_password')<div class="error">{{ $message }}</div>@enderror
                    </div>
                    <button class="btn btn-primary" type="submit">{{ __('Continue') }}</button>
                </div>
            </form>
        @endif
    </div>
@endsection
