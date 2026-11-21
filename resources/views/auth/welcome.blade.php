@extends('layouts.app')

@section('title', __('Welcome'))
@section('crumb', __('Welcome'))

@php
    $order = array_keys(\App\Http\Controllers\WelcomeController::STEPS);
    $at = array_search($step, $order, true);
    $percentText = fn (int $value) => app()->getLocale() === 'tr' ? '%'.$value : $value.'%';
@endphp

@section('content')
    <div class="medium stack">
        <div class="page-head" style="margin-bottom: 0">
            <div class="titles">
                <h1>{{ __('Welcome, :name!', ['name' => auth()->user()->username]) }}</h1>
                <div class="desc">{{ __('Three short steps and the forum knows what you care about. You can skip any of them and come back later.') }}</div>
            </div>
        </div>

        <ol class="wizard-steps" aria-label="{{ __('Steps') }}">
            @foreach (\App\Http\Controllers\WelcomeController::STEPS as $name => $label)
                @php $index = $loop->index; @endphp
                <li @class(['current' => $index === $at, 'done' => $index < $at]) @if ($index === $at) aria-current="step" @endif>
                    @if ($index === $at)
                        <span><b>{{ $index + 1 }}</b> {{ __($label) }}</span>
                    @else
                        <a href="{{ route($name) }}"><b>@if ($index < $at)<x-icon name="check" :size="13" />@else{{ $index + 1 }}@endif</b> {{ __($label) }}</a>
                    @endif
                </li>
            @endforeach
        </ol>

        @if ($step === 'welcome')
            {{-- ---- Step 1: forums to follow ---- --}}
            <form class="block" method="post" action="{{ route('welcome.save') }}" data-check-group>
                @csrf
                <div class="block-head"><x-icon name="eye" :size="16" /><h2>{{ __('Which forums do you want to follow?') }}</h2></div>
                <div class="block-body">
                    <p class="muted" style="margin: 0 0 10px">{{ __('You get an alert when a new thread is started in a forum you follow.') }}</p>
                    @if ($groups)
                        <div class="check-tools">
                            <button class="btn btn-sm" type="button" data-check="all"><x-icon name="check" :size="13" /> {{ __('Select all') }}</button>
                            <button class="btn btn-sm" type="button" data-check="none"><x-icon name="x" :size="13" /> {{ __('Clear all') }}</button>
                            <button class="btn btn-sm" type="button" data-check="invert">{{ __('Invert selection') }}</button>
                            <span class="muted small" data-check-count data-text="{{ __(':checked of :total forums selected') }}"></span>
                        </div>
                        @foreach ($groups as $group)
                            <div class="welcome-group" data-check-section>
                                @if ($group['title'] !== null)
                                    <label class="check welcome-cat" title="{{ __('Select or clear every forum in this category') }}">
                                        <input type="checkbox" data-check-toggle> {{ $group['title'] }}
                                    </label>
                                @endif
                                <div class="welcome-forums">
                                    @foreach ($group['forums'] as $forum)
                                        <label class="check"><input type="checkbox" name="forums[]" value="{{ $forum->id }}" @checked(in_array($forum->id, $watching, true))> {{ $forum->title }}</label>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    @else
                        <p class="muted" style="margin: 0">{{ __('There are no forums to follow yet.') }}</p>
                    @endif
                </div>
                <div class="block-foot row">
                    <button class="btn btn-primary" type="submit">{{ __('Save and continue') }} <x-icon name="chevron-right" :size="15" /></button>
                    <button class="btn btn-ghost" type="submit" name="skip" value="1" formnovalidate>{{ __('Skip this step') }}</button>
                </div>
            </form>
        @elseif ($step === 'welcome.profile')
            {{-- ---- Step 2: the profile ---- --}}
            <form class="block" method="post" action="{{ route('welcome.profile.save') }}" enctype="multipart/form-data">
                @csrf
                <div class="block-head"><x-icon name="user" :size="16" /><h2>{{ __('Introduce yourself') }}</h2></div>
                <div class="block-body stack">
                    <p class="muted" style="margin: 0">{{ __('Members answer more readily when they see who is writing. All of it is optional.') }}</p>
                    <div class="field">
                        <label for="avatar">{{ __('Avatar') }}</label>
                        <div class="row">
                            <x-avatar :user="$user" size="lg" :link="false" />
                            <input class="input" style="flex: 1; min-width: 200px" id="avatar" type="file" name="avatar" accept="image/png,image/jpeg,image/gif,image/webp">
                        </div>
                        <div class="hint">{{ __('JPG, PNG, GIF or WebP, at most 2 MB.') }}</div>
                        @error('avatar')<div class="error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="about">{{ __('About') }}</label>
                        <textarea class="input" id="about" name="about" rows="4" maxlength="2000" placeholder="{{ __('What do you do, what are you interested in?') }}">{{ old('about', $user->about) }}</textarea>
                        @unless (forum_can('profile.links'))<div class="hint">{{ __('Site addresses cannot be written here.') }}</div>@endunless
                        @error('about')<div class="error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="location">{{ __('Location') }}</label>
                        <input class="input" id="location" name="location" value="{{ old('location', $user->location) }}" maxlength="80" style="max-width: 360px">
                        @error('location')<div class="error">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="block-foot row">
                    <a class="btn" href="{{ route('welcome') }}"><x-icon name="chevron-left" :size="15" /> {{ __('Back') }}</a>
                    <button class="btn btn-primary" type="submit">{{ __('Save and continue') }} <x-icon name="chevron-right" :size="15" /></button>
                    <button class="btn btn-ghost" type="submit" name="skip" value="1" formnovalidate>{{ __('Skip this step') }}</button>
                </div>
            </form>
        @else
            {{-- ---- Step 3: what is set and what is left ---- --}}
            <section class="block">
                <div class="block-head"><x-icon name="check" :size="16" /><h2>{{ __('You are ready') }}</h2><span class="muted" style="margin-left: auto">{{ __('Profile') }}: {{ $percentText($percent) }}</span></div>
                <div class="block-body">
                    <p style="margin: 0 0 12px">
                        @if ($following)
                            {{ trans_choice('You follow :count forum and hear about new threads there.|You follow :count forums and hear about new threads there.', $following, ['count' => $following]) }}
                        @else
                            {{ __('You do not follow any forum yet. You can follow one at any time from its page.') }}
                        @endif
                        <a href="{{ route('welcome') }}">{{ __('Change') }}</a>
                    </p>
                    <div class="quota-bar" style="margin: 0 0 12px"><i style="width: {{ $percent }}%"></i></div>
                    <ul class="welcome-steps">
                        @foreach ($steps as [$label, $done, $url])
                            <li @class(['done' => $done])>
                                <x-icon :name="$done ? 'check' : 'chevron-right'" :size="15" />
                                @if ($done)<span>{{ __($label) }}</span>@else<a href="{{ $url }}">{{ __($label) }}</a>@endif
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="block-foot row">
                    <a class="btn" href="{{ route('welcome.profile') }}"><x-icon name="chevron-left" :size="15" /> {{ __('Back') }}</a>
                    <a class="btn btn-primary" href="{{ route('forum.index') }}">{{ __('Go to the forum') }} <x-icon name="chevron-right" :size="15" /></a>
                </div>
            </section>
        @endif
    </div>
@endsection
