@extends('layouts.admin')

@section('title', __('Add-ons'))
@section('subtitle', __('Extra features packaged as add-ons. An add-on that is switched off does not run any code.'))

@section('content')
    @if ($safeMode)
        <div class="flash info">{{ __('Safe mode is on: custom CSS, custom HTML, edited templates and add-ons are ignored.') }}</div>
    @endif

    <section class="block">
        @forelse ($addons as $id => $addon)
            @php
                $on = in_array($id, $enabled, true);
                $error = $manager->error($id);
                $product = $manager->licenseProduct($id);
                $licensed = $manager->licensed($id);
                $held = in_array($id, $locked, true);
            @endphp
            <div class="item" style="align-items: center">
                <span class="node-icon" style="width: 40px; height: 40px"><x-icon name="grid" :size="18" /></span>
                <div class="body">
                    <div class="title">{{ $manager->text($id, 'title') }} <span class="muted" style="font-weight: 400; font-size: 13px">{{ $addon['version'] }}</span>
                        @if ($on)<span class="badge green">{{ __('On') }}</span>@elseif ($held)<span class="badge red">{{ __('Stopped: no licence') }}</span>@else<span class="badge">{{ __('Off') }}</span>@endif
                        @if ($product && $licensed)<span class="badge blue">{{ __('Licensed') }}</span>@elseif ($product)<span class="badge amber">{{ __('Licence code needed') }}</span>@endif
                    </div>
                    <div class="snippet">{{ $manager->text($id, 'description') }}</div>
                    <div class="thread-meta"><code>{{ $id }}</code>@if (! empty($addon['author'])) <span>·</span> {{ $addon['author'] }}@endif</div>
                    @if ($error)<div class="flash error" style="margin: 8px 0 0">{{ $error }}</div>@endif
                    @if ($product && ! $licensed)
                        <form class="row" method="post" action="{{ route('admin.license.activate') }}" style="margin-top: 8px; flex-wrap: nowrap; max-width: 480px">
                            @csrf
                            <input type="hidden" name="product" value="{{ $product }}">
                            <input class="input" name="license_key" maxlength="64" placeholder="{{ __('Licence code of this module') }}" aria-label="{{ __('Licence code of this module') }}" autocomplete="off" spellcheck="false" required>
                            <button class="btn btn-sm btn-primary" type="submit">{{ __('Activate licence') }}</button>
                        </form>
                        <div class="hint">{{ __('This is a sold module: it runs only with its own licence code. You find the code in the market, next to your purchase.') }}</div>
                    @endif
                </div>
                <form class="row" method="post" action="{{ route('admin.addons.toggle') }}">
                    @csrf
                    <input type="hidden" name="id" value="{{ $id }}">
                    @if ($held)
                        <button class="btn btn-sm" name="action" value="disable">{{ __('Switch off') }}</button>
                    @elseif ($on)
                        @if ($manager->settingsOf($id))<a class="btn btn-sm" href="{{ route('admin.addons.settings', ['id' => $id]) }}"><x-icon name="sliders" :size="14" /> {{ __('Settings') }}</a>@endif
                        <button class="btn btn-sm" name="action" value="disable">{{ __('Switch off') }}</button>
                    @else
                        <button class="btn btn-sm btn-primary" name="action" value="enable" @disabled(! $licensed) @if (! $licensed) title="{{ __('Enter the licence code of this module first.') }}" @endif>{{ __('Switch on') }}</button>
                        <button class="btn btn-sm btn-danger" name="action" value="delete" data-confirm="{{ __('Delete the files of this add-on?') }}" title="{{ __('Delete') }}"><x-icon name="trash" :size="14" /></button>
                    @endif
                </form>
            </div>
        @empty
            <div class="empty">{{ __('No add-ons are installed.') }}</div>
        @endforelse
    </section>

    <form class="block medium" style="margin: 16px 0 0" method="post" action="{{ route('admin.addons.upload') }}" enctype="multipart/form-data">
        @csrf
        <div class="block-head"><x-icon name="plus" :size="16" /><h2>{{ __('Upload an add-on') }}</h2></div>
        <div class="block-body">
            <p class="muted">{{ __('Add-ons are program code with full access to the forum and its database. Only install add-ons from developers you trust.') }}</p>
            @unless ($writable)<div class="flash error">{{ __('The addons folder is not writable on this server.') }}</div>@endunless
            <div class="form-grid">
                <div class="field">
                    <label for="package">{{ __('Add-on package (.zip)') }}</label>
                    <input class="input" id="package" type="file" name="package" accept=".zip" required>
                    @error('package')<div class="error">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label for="current_password">{{ __('Your password') }}</label>
                    <input class="input" id="current_password" type="password" name="current_password" autocomplete="current-password" required>
                    @error('current_password')<div class="error">{{ $message }}</div>@enderror
                </div>
            </div>
            <button class="btn btn-primary" type="submit">{{ __('Upload') }}</button>
        </div>
        <div class="block-foot muted small">{{ __('Developers: see addons/README.md in the package for the structure of an add-on.') }}</div>
    </form>
@endsection
