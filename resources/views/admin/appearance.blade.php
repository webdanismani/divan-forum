@extends('layouts.admin')

@section('title', __('Appearance'))
@section('subtitle', __('Choose the colours of the board. Changes apply to all visitors as soon as you save.'))

@section('content')
    <form method="post" action="{{ route('admin.appearance.update') }}" data-theme-form>
        @csrf @method('PUT')

        <div class="grid-2">
            <div>
                <section class="block">
                    <div class="block-head"><x-icon name="droplet" :size="16" /><h2>{{ __('Colour schemes') }}</h2></div>
                    <div class="block-body">
                        <p class="muted">{{ __('Pick a ready-made scheme as a starting point, then adjust single colours below.') }}</p>
                        <div class="preset-list">
                            @foreach ($presets as $name => $preset)
                                <button type="button" class="preset" data-preset='@json($preset)' title="{{ __($name) }}">
                                    <span class="preset-swatch">
                                        <i style="background: {{ $preset['header'] }}"></i>
                                        <i style="background: {{ $preset['bar'] }}"></i>
                                        <i style="background: {{ $preset['category'] }}"></i>
                                        <i style="background: {{ $preset['accent'] }}"></i>
                                    </span>
                                    {{ __($name) }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                </section>

                <section class="block">
                    <div class="block-head"><x-icon name="sliders" :size="16" /><h2>{{ __('Colours') }}</h2></div>
                    @foreach ($fields as $key => $field)
                        <label class="color-row" for="color-{{ $key }}">
                            <input type="color" id="color-{{ $key }}" name="colors[{{ $key }}]" value="{{ old("colors.$key", $colors[$key]) }}" data-color="{{ $key }}">
                            <span class="color-text">
                                <b>{{ __($field['label']) }}</b>
                                <span class="muted">{{ __($field['hint']) }}</span>
                            </span>
                            <code data-hex="{{ $key }}">{{ old("colors.$key", $colors[$key]) }}</code>
                        </label>
                    @endforeach
                    <div class="block-foot form-actions">
                        <button class="btn btn-primary" type="submit"><x-icon name="check" :size="16" /> {{ __('Save colours') }}</button>
                        @if ($isCustom)
                            <button class="btn" type="submit" form="reset-colors">{{ __('Restore defaults') }}</button>
                        @endif
                    </div>
                </section>
            </div>

            <section class="block">
                <div class="block-head"><x-icon name="eye" :size="16" /><h2>{{ __('Preview') }}</h2></div>
                <div class="block-body">
                    <div class="tp" data-preview aria-hidden="true">
                        <div class="tp-header"><span class="tp-mark"></span><b>{{ setting('board.name') }}</b><span class="tp-search"></span><span class="tp-btn">{{ __('Register') }}</span></div>
                        <div class="tp-nav"><span class="on">{{ __('Forums') }}</span><span>{{ __("What's new") }}</span><span>{{ __('Members') }}</span></div>
                        <div class="tp-page">
                            <div class="tp-bar">{{ __('Forum') }}</div>
                            <div class="tp-cat">{{ __('Category') }}</div>
                            <div class="tp-row"><span class="tp-icon"></span><span><b>{{ __('Forum') }}</b><br><span class="tp-link">{{ __('Thread title') }}</span></span><span class="tp-dot"></span></div>
                            <div class="tp-row"><span class="tp-icon"></span><span><b>{{ __('Forum') }}</b><br><span class="tp-link">{{ __('Thread title') }}</span></span><span class="tp-dot"></span></div>
                            <div class="tp-actions"><span class="tp-btn">{{ __('New thread') }}</span></div>
                        </div>
                        <div class="tp-footer">{{ setting('board.name') }} © {{ date('Y') }}</div>
                    </div>
                    <p class="muted small" style="margin: 12px 0 0">{{ __('Text colours on the bars are chosen automatically so that they stay readable. In the dark theme the accent colour is lightened and the page background is not used.') }}</p>
                </div>
            </section>
        </div>
    </form>

    <form id="reset-colors" method="post" action="{{ route('admin.appearance.reset') }}" data-confirm="{{ __('Restore the default colours?') }}">
        @csrf @method('DELETE')
    </form>

    <form class="block" style="margin-top: 16px" method="post" action="{{ route('admin.appearance.extras') }}" enctype="multipart/form-data">
        @csrf
        <div class="block-head"><x-icon name="image" :size="16" /><h2>{{ __('Logo, extra CSS and code') }}</h2></div>
        <div class="block-body">
            @if (config('forum.safe_mode'))
                <div class="flash info">{{ __('Safe mode is on: custom CSS, custom HTML, edited templates and add-ons are ignored.') }}</div>
            @endif
            <div class="field">
                <label for="logo">{{ __('Logo') }}</label>
                @php $logo = app(\App\Forum\Customizer::class)->logoUrl(); @endphp
                @if ($logo)
                    <div class="row" style="margin-bottom: 8px"><img src="{{ $logo }}" alt="" style="max-height: 44px; background: var(--header); padding: 6px; border-radius: 3px">
                        <label class="check"><input type="checkbox" name="remove_logo" value="1"> {{ __('Remove the logo') }}</label></div>
                @endif
                <input class="input" id="logo" type="file" name="logo" accept="image/png,image/jpeg,image/gif,image/webp">
                <div class="hint">{{ __('Shown in the header instead of the board name, about 40 pixels high. PNG with a transparent background works best.') }}</div>
                @error('logo')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div class="field">
                <label for="custom_css">{{ __('Extra CSS') }}</label>
                <textarea class="input" id="custom_css" name="custom_css" spellcheck="false" style="min-height: 150px; font-family: var(--mono); font-size: 13px">{{ old('custom_css', setting('theme.custom_css')) }}</textarea>
                <div class="hint">{{ __('Loaded after the forum stylesheet on every page of the forum. The admin panel keeps its own look.') }}</div>
            </div>
            <div class="form-grid">
                <div class="field">
                    <label for="head_html">{{ __('HTML before </head>') }}</label>
                    <textarea class="input" id="head_html" name="head_html" spellcheck="false" style="min-height: 110px; font-family: var(--mono); font-size: 13px">{{ old('head_html', setting('theme.head_html')) }}</textarea>
                </div>
                <div class="field">
                    <label for="footer_html">{{ __('HTML before </body>') }}</label>
                    <textarea class="input" id="footer_html" name="footer_html" spellcheck="false" style="min-height: 110px; font-family: var(--mono); font-size: 13px">{{ old('footer_html', setting('theme.footer_html')) }}</textarea>
                </div>
            </div>
            <div class="hint" style="margin-bottom: 12px">{{ __('For analytics and verification codes. It is printed as it is on every forum page, so paste only code you trust.') }}</div>
            <div class="field" style="max-width: 320px">
                <label for="extras_password">{{ __('Your password (only to change the two HTML fields)') }}</label>
                <input class="input" id="extras_password" type="password" name="current_password" autocomplete="current-password">
                @error('current_password')<span class="error">{{ $message }}</span>@enderror
            </div>
            <button class="btn btn-primary" type="submit">{{ __('Save') }}</button>
        </div>
    </form>
@endsection
