@extends('layouts.admin')

@section('title', __('Edit template'))
@section('subtitle', $file)
@section('actions')
    <a class="btn" href="{{ route('admin.templates') }}">{{ __('All templates') }}</a>
@endsection

@section('content')
    <div class="flash info">{{ __('Templates are Blade files: a mistake here can break a page for every visitor. If that happens, add FORUM_SAFE_MODE=true to the .env file to switch all changes off, then fix or reset the template.') }}</div>

    <form method="post" action="{{ route('admin.templates.save') }}">
        @csrf
        <input type="hidden" name="file" value="{{ $file }}">
        <section class="block">
            <div class="block-head"><h2>{{ $file }}</h2>@if ($overridden)<span class="badge amber">{{ __('Changed') }}</span>@endif</div>
            <textarea class="code-area" name="content" spellcheck="false" wrap="off" required>{{ $content }}</textarea>
        </section>

        <div class="row" style="margin-top: 14px; align-items: flex-end">
            <div class="field" style="margin: 0; width: 260px">
                <label for="current_password">{{ __('Your password') }}</label>
                <input class="input" id="current_password" type="password" name="current_password" autocomplete="current-password" required>
                @error('current_password')<div class="error">{{ $message }}</div>@enderror
            </div>
            <button class="btn btn-primary" type="submit">{{ __('Save template') }}</button>
            @if ($overridden)
                <button class="btn btn-danger" type="submit" name="revert" value="1" data-confirm="{{ __('Throw your changes away and use the original template?') }}">{{ __('Use the original again') }}</button>
            @endif
        </div>
    </form>

    @if ($overridden)
        <details class="block" style="margin-top: 16px">
            <summary class="block-head" style="cursor: pointer"><h2>{{ __('Original template') }}</h2></summary>
            <pre class="history-text" style="border-top: 0; max-height: 480px; overflow: auto">{{ $original }}</pre>
        </details>
    @endif
@endsection
