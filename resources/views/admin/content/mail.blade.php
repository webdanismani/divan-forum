@extends('layouts.admin')

@section('title', __('E-mail to members'))
@section('subtitle', __('An announcement by e-mail. It goes to members with a confirmed address who have not switched announcements off.'))

@section('content')
    @unless ($enabled)
        <div class="flash error">{{ __('Outgoing mail is switched off. Choose SMTP and save the settings first.') }} <a href="{{ route('admin.settings.edit') }}">{{ __('Settings') }}</a></div>
    @endunless

    <form class="medium" style="margin-left: 0" method="post" action="{{ route('admin.mail.send') }}">
        @csrf
        <section class="block">
            <div class="block-body">
                <div class="field">
                    <label for="group_id">{{ __('Recipients') }}</label>
                    <select class="input" id="group_id" name="group_id" style="max-width: 360px">
                        <option value="">{{ __('All members') }} ({{ forum_number($recipients) }})</option>
                        @foreach ($groups as $group)
                            <option value="{{ $group->id }}" @selected((int) old('group_id') === $group->id)>{{ $group->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="subject">{{ __('Subject') }}</label>
                    <input class="input" id="subject" name="subject" value="{{ old('subject') }}" maxlength="150" required>
                    @error('subject')<div class="error">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label for="body">{{ __('Message') }}</label>
                    <textarea class="input" id="body" name="body" maxlength="10000" style="min-height: 220px" required>{{ old('body') }}</textarea>
                    <div class="hint">{{ __('Plain text; leave an empty line between paragraphs. Every message starts with "Hello name," and ends with a button to the forum.') }}</div>
                    @error('body')<div class="error">{{ $message }}</div>@enderror
                </div>
                <div class="form-actions">
                    <button class="btn" type="submit" name="test" value="1" @disabled(! $enabled)>{{ __('Send a test copy to me') }}</button>
                    <button class="btn btn-primary" type="submit" @disabled(! $enabled) data-confirm="{{ __('Send this message to all chosen members?') }}">{{ __('Send') }}</button>
                </div>
            </div>
        </section>
    </form>
@endsection
