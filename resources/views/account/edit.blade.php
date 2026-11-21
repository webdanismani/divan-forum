@extends('layouts.app')

@section('title', __('Account settings'))
@section('crumb', __('Account settings'))

@section('content')
    <div class="medium stack">
        <div class="page-head" style="margin-bottom: 0"><div class="titles"><h1>{{ __('Account settings') }}</h1></div></div>

        <section class="block">
            <div class="block-head"><h2>{{ __('Avatar') }}</h2></div>
            <div class="block-body row">
                <x-avatar :user="$user" size="lg" :link="false" />
                <form class="row" method="post" action="{{ route('account.avatar') }}" enctype="multipart/form-data" style="flex: 1">
                    @csrf
                    <input class="input" style="flex: 1; min-width: 200px" type="file" name="avatar" accept="image/png,image/jpeg,image/gif,image/webp" required>
                    <button class="btn btn-primary" type="submit">{{ __('Upload') }}</button>
                </form>
                @if ($user->avatar_path)
                    <form method="post" action="{{ route('account.avatar.remove') }}">@csrf @method('DELETE')
                        <button class="btn btn-danger" type="submit">{{ __('Remove') }}</button>
                    </form>
                @endif
            </div>
            <div class="block-foot muted small">{{ __('JPG, PNG, GIF or WebP, at most 2 MB.') }}</div>
        </section>

        <section class="block" id="cover">
            <div class="block-head"><x-icon name="image" :size="16" /><h2>{{ __('Cover photo') }}</h2></div>
            @if ($user->coverUrl())
                <img class="cover-preview" src="{{ $user->coverUrl() }}" alt="">
            @endif
            <div class="block-body row">
                <form class="row" method="post" action="{{ route('account.cover') }}" enctype="multipart/form-data" style="flex: 1">
                    @csrf
                    <input class="input" style="flex: 1; min-width: 200px" type="file" name="cover" accept="image/png,image/jpeg,image/webp" required>
                    <button class="btn btn-primary" type="submit">{{ __('Upload') }}</button>
                </form>
                @if ($user->cover_path)
                    <form method="post" action="{{ route('account.cover.remove') }}">@csrf @method('DELETE')
                        <button class="btn btn-danger" type="submit">{{ __('Remove') }}</button>
                    </form>
                @endif
            </div>
            @error('cover')<div class="block-body" style="padding-top: 0"><div class="error">{{ $message }}</div></div>@enderror
            <div class="block-foot muted small">{{ __('Shown across the top of your profile. A wide picture works best (for example 1500×400); JPG, PNG or WebP, at most 4 MB.') }}</div>
        </section>

        <form class="block" method="post" action="{{ route('account.profile') }}">
            @csrf @method('PUT')
            <div class="block-head"><h2>{{ __('Profile') }}</h2></div>
            <div class="block-body">
                <div class="form-grid">
                    <div class="field">
                        <label for="location">{{ __('Location') }}</label>
                        <input class="input" id="location" name="location" value="{{ old('location', $user->location) }}" maxlength="80">
                        @error('location')<div class="error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="website">{{ __('Website') }}</label>
                        <input class="input" id="website" type="url" name="website" value="{{ old('website', $user->website) }}" placeholder="https://">
                    </div>
                </div>
                <div class="field">
                    <label for="about">{{ __('About') }}</label>
                    <textarea class="input" id="about" name="about" maxlength="2000">{{ old('about', $user->about) }}</textarea>
                    @unless (forum_can('profile.links'))<div class="hint">{{ __('Site addresses cannot be written here.') }}</div>@endunless
                    @error('about')<div class="error">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label for="signature">{{ __('Signature') }}</label>
                    @if ($signatureLimit > 0)
                        <x-editor name="signature" id="signature" :value="$user->signature" :rows="4" :required="false" :maxlength="$signatureLimit" :note="__('Shown under your posts. BBCode is allowed.')" />
                        <div class="hint">{{ __('At most :count characters.', ['count' => $signatureLimit]) }} {{ forum_can('signature.links') ? __('Links are allowed.') : __('Links are not allowed for your member group.') }} {{ forum_can('signature.images') ? __('Images are allowed.') : __('Images are not allowed for your member group.') }}</div>
                        @error('signature')<div class="error">{{ $message }}</div>@enderror
                    @else
                        <div class="hint">{{ __('Your member group cannot use a signature.') }}</div>
                    @endif
                </div>
                @foreach ($fields as $field)
                    <div class="field">
                        <label for="field-{{ $field->id }}">{{ $field->label }}@unless ($field->is_required) <span class="muted">({{ __('optional') }})</span>@endunless</label>
                        @if ($field->type === 'select')
                            <select class="input" id="field-{{ $field->id }}" name="fields[{{ $field->id }}]" @required($field->is_required)>
                                <option value="">—</option>
                                @foreach ($field->options ?? [] as $option)
                                    <option value="{{ $option }}" @selected(old('fields.'.$field->id, $fieldValues[$field->id] ?? '') === $option)>{{ $option }}</option>
                                @endforeach
                            </select>
                        @else
                            <input class="input" id="field-{{ $field->id }}" type="{{ $field->type === 'url' ? 'url' : 'text' }}" name="fields[{{ $field->id }}]" value="{{ old('fields.'.$field->id, $fieldValues[$field->id] ?? '') }}" maxlength="255" @required($field->is_required)>
                        @endif
                        @error('fields.'.$field->id)<div class="error">{{ $message }}</div>@enderror
                    </div>
                @endforeach
                <button class="btn btn-primary" type="submit">{{ __('Save profile') }}</button>
            </div>
        </form>

        <form class="block" method="post" action="{{ route('account.preferences') }}">
            @csrf @method('PUT')
            <div class="block-head"><h2>{{ __('Preferences') }}</h2></div>
            <div class="block-body">
                <div class="form-grid">
                    <div class="field">
                        <label for="theme">{{ __('Theme') }}</label>
                        <select class="input" id="theme" name="theme">
                            @foreach (['auto' => __('Automatic'), 'light' => __('Light'), 'dark' => __('Dark')] as $key => $label)
                                <option value="{{ $key }}" @selected(($user->theme ?: 'auto') === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="locale">{{ __('Language') }}</label>
                        <select class="input" id="locale" name="locale">
                            @foreach (['tr' => 'Türkçe', 'en' => 'English'] as $key => $label)
                                <option value="{{ $key }}" @selected(app()->getLocale() === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="field">
                    <span class="label">{{ __('E-mail notifications') }}</span>
                    <input type="hidden" name="keep_mail" value="1">
                    <span class="hint" style="display: block; margin: 0 0 6px">{{ __('Which alerts are also sent by e-mail is chosen under "Alert preferences" below.') }}</span>
                    <label class="check" style="margin-bottom: 6px" hidden><input type="checkbox" name="email_on_reply" value="1" @checked($user->email_on_reply) disabled> {{ __('New replies in threads and new threads in forums I watch') }}</label>
                    <label class="check" hidden><input type="checkbox" name="email_on_message" value="1" @checked($user->email_on_message) disabled> {{ __('Private messages and mentions') }}</label>
                    <label class="check" style="margin-top: 6px"><input type="checkbox" name="email_announcements" value="1" @checked($user->email_announcements)> {{ __('Announcements from the administrators') }}</label>
                </div>
                <div class="field" style="max-width: 320px">
                    <label for="email_digest">{{ __('Summary of active threads') }}</label>
                    <select class="input" id="email_digest" name="email_digest">
                        @foreach (['none' => __('Do not send'), 'daily' => __('Daily'), 'weekly' => __('Weekly')] as $key => $label)
                            <option value="{{ $key }}" @selected($user->email_digest === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="btn btn-primary" type="submit">{{ __('Save preferences') }}</button>
            </div>
        </form>

        <form class="block" method="post" action="{{ route('account.security') }}">
            @csrf @method('PUT')
            <div class="block-head"><h2>{{ __('E-mail and password') }}</h2></div>
            <div class="block-body">
                <div class="field">
                    <label for="email">{{ __('E-mail address') }}</label>
                    <input class="input" id="email" type="email" name="email" value="{{ old('email', $user->email) }}" required>
                </div>
                <div class="form-grid">
                    <div class="field">
                        <label for="password">{{ __('New password') }}</label>
                        <input class="input" id="password" type="password" name="password" autocomplete="new-password">
                        <div class="hint">{{ __('Leave empty to keep your current password.') }}</div>
                    </div>
                    <div class="field">
                        <label for="password_confirmation">{{ __('Confirm password') }}</label>
                        <input class="input" id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password">
                    </div>
                </div>
                <div class="field">
                    <label for="current_password">{{ __('Current password') }}</label>
                    <input class="input" id="current_password" type="password" name="current_password" autocomplete="current-password" required>
                    <div class="hint">{{ __('Required to confirm these changes.') }}</div>
                </div>
                <button class="btn btn-primary" type="submit">{{ __('Save changes') }}</button>
            </div>
        </form>
        <section class="block">
            <div class="block-head"><x-icon name="lock" :size="16" /><h2>{{ __('Two-step sign-in') }}</h2><span class="spacer"></span>@if ($twoFactor)<span class="badge green">{{ __('On') }}</span>@else<span class="badge">{{ __('Off') }}</span>@endif</div>
            <div class="block-body row">
                <span class="muted" style="flex: 1; min-width: 220px">{{ __('Protect your account with a code from an authenticator app in addition to your password.') }}</span>
                <a class="btn" href="{{ route('two-factor.show') }}">{{ $twoFactor ? __('Manage') : __('Switch on') }}</a>
            </div>
        </section>

        @include('account._sessions')

        @include('account._comfort')

        @if ($privacyChoices)
            @php
                $levels = \App\Forum\Privacy::LEVELS;
                $privacyLabels = ['profile' => __('Who can see my profile'), 'wall' => __('Who can write on my profile'), 'messages' => __('Who can start a conversation with me')];
            @endphp
            <form class="block" id="privacy" method="post" action="{{ route('account.privacy') }}">
                @csrf @method('PUT')
                <div class="block-head"><x-icon name="lock" :size="16" /><h2>{{ __('Privacy') }}</h2><span class="spacer"></span><a href="{{ route('friends.index') }}">{{ __('Friends') }}</a></div>
                <div class="block-body">
                    <div class="form-grid">
                        @foreach ($privacyLabels as $key => $label)
                            @if (array_key_exists($key, $privacyChoices))
                                <div class="field">
                                    <label for="privacy-{{ $key }}">{{ $label }}</label>
                                    <select class="input" id="privacy-{{ $key }}" name="privacy[{{ $key }}]">
                                        @foreach ($levels as $value => $text)
                                            @continue($key !== 'profile' && $value === 'everyone')
                                            <option value="{{ $value }}" @selected($privacyChoices[$key] === $value)>{{ __($text) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif
                        @endforeach
                    </div>
                    @if (array_key_exists('online', $privacyChoices))
                        <label class="check" style="margin-bottom: 8px"><input type="checkbox" name="privacy[online]" value="1" @checked($privacyChoices['online'])> {{ __('Show others when I am online') }}</label>
                    @endif
                    @if (array_key_exists('requests', $privacyChoices))
                        <label class="check" style="margin-bottom: 12px"><input type="checkbox" name="privacy[requests]" value="1" @checked($privacyChoices['requests'])> {{ __('Accept friend requests') }}</label>
                    @endif
                    <button class="btn btn-primary" type="submit">{{ __('Save') }}</button>
                </div>
                <div class="block-foot muted small">{{ __('The forum staff can always see profiles. Which of these choices you have depends on your member group.') }}</div>
            </form>
        @endif

        @if ($smsEnabled || $user->phone_verified_at)
            <section class="block" id="phone">
                <div class="block-head"><x-icon name="check" :size="16" /><h2>{{ __('Phone verification') }}</h2><span class="spacer"></span>@if ($user->phone_verified_at)<span class="badge green">{{ __('Verified member') }}</span>@else<span class="badge">{{ __('Not verified') }}</span>@endif</div>
                @if ($user->phone_verified_at)
                    <div class="block-body row">
                        <span style="flex: 1">{{ \App\Forum\Sms::mask($user->phone) }} <span class="muted">· {{ __('verified on :date', ['date' => $user->phone_verified_at->translatedFormat('j M Y')]) }}</span></span>
                        <form method="post" action="{{ route('account.phone.remove') }}" data-confirm="{{ __('Remove your phone number? You will lose the verified badge.') }}">@csrf @method('DELETE')
                            <button class="btn btn-danger" type="submit">{{ __('Remove') }}</button>
                        </form>
                    </div>
                @else
                    <div class="block-body">
                        <p class="muted" style="margin-top: 0">{{ __('Verify your mobile number to get the "verified member" badge next to your posts. The number itself is never shown to other members.') }}</p>
                        <form class="row" method="post" action="{{ route('account.phone.send') }}">@csrf
                            <input class="input" style="flex: 1; min-width: 200px" type="tel" name="phone" value="{{ old('phone') }}" maxlength="25" placeholder="05xx xxx xx xx" autocomplete="tel" required>
                            <button class="btn @unless ($phonePending) btn-primary @endunless" type="submit">{{ $phonePending ? __('Send a new code') : __('Send the code') }}</button>
                        </form>
                        @error('phone')<div class="error" style="margin-top: 6px">{{ $message }}</div>@enderror
                        @if ($phonePending)
                            <form class="row" method="post" action="{{ route('account.phone.confirm') }}" style="margin-top: 12px">@csrf @method('PUT')
                                <span class="muted">{{ __('Code sent to :phone', ['phone' => \App\Forum\Sms::mask($phonePending->phone)]) }}</span>
                                <input class="input" style="width: 140px" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="000000" required>
                                <button class="btn btn-primary" type="submit">{{ __('Verify') }}</button>
                            </form>
                            @error('code')<div class="error" style="margin-top: 6px">{{ $message }}</div>@enderror
                        @endif
                    </div>
                @endif
            </section>
        @endif

        @if ($pushKey)
            <section class="block">
                <div class="block-head"><x-icon name="bell" :size="16" /><h2>{{ __('Browser notifications') }}</h2><span class="spacer"></span><span class="badge" data-push-state data-on="{{ __('On') }}" data-off="{{ __('Off') }}">{{ __('Off') }}</span></div>
                <div class="block-body row">
                    <span class="muted" style="flex: 1; min-width: 220px" data-push-text data-unsupported="{{ __('This browser does not support notifications. On an iPhone or iPad, add the forum to the home screen first.') }}" data-denied="{{ __('Notifications from this site are blocked in your browser settings.') }}">{{ __('Get your alerts (replies, mentions, private messages) as notifications on this device, even when the forum is not open.') }}</span>
                    <button class="btn" type="button" data-push="{{ $pushKey }}" data-url="{{ route('push.subscribe') }}" data-worker="{{ route('pwa.sw', [], false) }}" data-label-on="{{ __('Switch on') }}" data-label-off="{{ __('Switch off') }}" disabled>{{ __('Switch on') }}</button>
                </div>
            </section>
        @endif

        @if ($apiKeys !== null)
            <section class="block">
                <div class="block-head"><x-icon name="key" :size="16" /><h2>{{ __('API keys') }}</h2></div>
                @if (session('api_key'))
                    <div class="block-body">
                        <div class="field" style="margin: 0">
                            <label>{{ __('Your new key') }}</label>
                            <input class="input" value="{{ session('api_key') }}" readonly onfocus="this.select()">
                            <div class="hint">{{ __('Keep it secret: whoever has this key can act on the forum as you.') }}</div>
                        </div>
                    </div>
                @endif
                @if ($apiKeys->isNotEmpty())
                    <ul class="side-list">
                        @foreach ($apiKeys as $apiKey)
                            <li>
                                <div class="meta" style="flex: 1"><strong>{{ $apiKey->name }}</strong>
                                    <div class="muted small">{{ __('Created') }}: {{ $apiKey->created_at?->format('d.m.Y') }} · {{ __('Last used') }}: {{ $apiKey->last_used_at?->diffForHumans() ?? '—' }}</div>
                                </div>
                                <form method="post" action="{{ route('account.api-keys.destroy', $apiKey->id) }}" data-confirm="{{ __('Delete this API key?') }}">@csrf @method('DELETE')
                                    <button class="btn btn-sm btn-danger" type="submit">{{ __('Delete') }}</button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <form class="block-foot row" method="post" action="{{ route('account.api-keys.store') }}">@csrf
                    <input class="input" style="flex: 1; min-width: 200px" name="name" maxlength="60" placeholder="{{ __('What is this key for?') }}" required>
                    <input class="input" style="flex: 1; min-width: 160px" type="password" name="current_password" autocomplete="current-password" placeholder="{{ __('Current password') }}" required>
                    <button class="btn btn-primary" type="submit">{{ __('Create a key') }}</button>
                </form>
            </section>
        @endif

        @if ($ignored->isNotEmpty())
            <section class="block">
                <div class="block-head"><h2>{{ __('Members you ignore') }}</h2></div>
                <ul class="side-list">
                    @foreach ($ignored as $member)
                        <li>
                            <x-avatar :user="$member" size="sm" />
                            <div class="meta" style="flex: 1"><x-username :user="$member" /></div>
                            <form method="post" action="{{ route('members.ignore', $member) }}">@csrf
                                <button class="btn btn-sm" type="submit">{{ __('Stop ignoring') }}</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
@endsection
