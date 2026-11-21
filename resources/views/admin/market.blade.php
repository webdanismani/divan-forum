@extends('layouts.admin')

@section('title', __('Market'))
@section('subtitle', __('Extra modules and themes for your forum. The forum itself is free; these are optional.'))

@section('content')
    @php
        $symbol = $store['currency_symbol'] ?? config('forum.ads.currency_symbol');
        $price = fn ($value) => rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',').' '.$symbol;
        $typeLabel = ['module' => __('Module'), 'theme' => __('Theme'), 'bundle' => __('Bundle')];
        $date = fn (?string $value) => $value ? \Illuminate\Support\Carbon::parse($value)->translatedFormat('j M Y') : '';
    @endphp

    @if ($safeMode)
        <div class="flash info">{{ __('Safe mode is on: custom CSS, custom HTML, edited templates and add-ons are ignored.') }}</div>
    @endif
    @if (session('update_log'))
        <pre class="bb-code" style="margin-bottom: 12px; max-height: 220px; overflow: auto">{{ session('update_log') }}</pre>
    @endif

    @if (! $configured)
        <div class="block"><div class="empty">{{ __('The market is not set up in this copy of the software yet.') }}</div></div>
    @elseif (! $reachable)
        <div class="block">
            <div class="empty">{{ __('The market could not be reached. Please try again later.') }}
                <div style="margin-top: 10px"><a class="btn" href="{{ route('admin.market', ['refresh' => 1]) }}"><x-icon name="refresh" :size="15" /> {{ __('Try again') }}</a></div>
            </div>
        </div>
    @else
        @if ($core)
            <section class="block">
                <div class="block-head"><x-icon name="refresh" :size="16" /><h2>{{ __('Forum software') }}</h2>
                    <span class="spacer"></span>
                    @if ($core['update'])<span class="badge amber">{{ __('Update: :version', ['version' => $core['version']]) }}</span>@else<span class="badge green">{{ __('Up to date') }}</span>@endif
                </div>
                <div class="block-body row">
                    <span style="flex: 1; min-width: 220px">{{ __('Installed version: :version', ['version' => config('forum.product.version')]) }}
                        @if ($core['update'])<span class="muted"> · {{ $core['summary'] }}</span>@endif
                    </span>
                    @if ($core['update'])
                        <button class="btn btn-primary" type="button" data-dialog="core-update">{{ __('Update to :version', ['version' => $core['version']]) }}</button>
                    @endif
                </div>
                @if ($core['update'] && filled($core['description']))
                    <div class="block-foot" style="white-space: pre-line">{{ $core['description'] }}</div>
                @endif
            </section>
            @if ($core['update'])
                <dialog class="dialog" id="core-update">
                    <form method="post" action="{{ route('admin.market.core') }}" data-busy="{{ __('Updating…') }}">
                        @csrf
                        <div class="block-head"><h2>{{ __('Update to :version', ['version' => $core['version']]) }}</h2></div>
                        <div class="block-body">
                            <p class="muted" style="margin-top: 0">{{ __('The update is downloaded from the market, its signature is checked, the files of the forum are replaced and the database is updated. Take a backup first. Your content, settings, add-ons and uploads are not touched.') }}</p>
                            <div class="field">
                                <label>{{ __('Current password') }}</label>
                                <input class="input" type="password" name="current_password" autocomplete="current-password" required>
                            </div>
                            <div class="form-actions">
                                <button class="btn btn-primary" type="submit">{{ __('Update') }}</button>
                                <button class="btn" type="button" data-dialog-close>{{ __('Cancel') }}</button>
                            </div>
                        </div>
                    </form>
                </dialog>
            @endif
        @endif

        <div class="row" style="margin-bottom: 12px">
            <span class="muted">{{ $store['name'] ?? '' }} · {{ trans_choice(':count item|:count items', $items->count(), ['count' => $items->count()]) }}</span>
            <span class="spacer"></span>
            <a class="btn btn-sm" href="{{ route('admin.market', ['refresh' => 1]) }}"><x-icon name="refresh" :size="14" /> {{ __('Refresh') }}</a>
        </div>

        @if ($items->isEmpty())
            <div class="block"><div class="empty">{{ __('There is nothing in the market yet.') }}</div></div>
        @endif

        <div class="market-cards">
            @foreach ($items as $item)
                @php
                    $installable = in_array($item['type'], ['module', 'theme'], true);
                    $expired = ! empty($item['expired']);
                    $renewPrice = (float) ($item['renew_price'] ?? 0) > 0 ? $item['renew_price'] : $item['price'];
                @endphp
                <section class="block market-card">
                    @if ($item['screenshot'])
                        <img class="market-shot" src="{{ $item['screenshot'] }}" alt="" loading="lazy" referrerpolicy="no-referrer">
                    @else
                        <div class="market-shot market-shot-empty"><x-icon :name="$item['type'] === 'theme' ? 'droplet' : 'grid'" :size="34" /></div>
                    @endif
                    <div class="block-body">
                        <div class="row" style="gap: 6px">
                            <span class="badge blue">{{ $typeLabel[$item['type']] ?? $item['type'] }}</span>
                            @if ($item['installed_version'])
                                <span class="badge green">{{ __('Installed') }} {{ $item['installed_version'] }}</span>
                            @elseif ($item['owned'] && $item['price'] > 0)
                                <span class="badge green">{{ __('Purchased') }}</span>
                            @endif
                            @if ($item['update'])<span class="badge amber">{{ __('Update: :version', ['version' => $item['version']]) }}</span>@endif
                            @if ($expired)<span class="badge red">{{ __('Update period ended') }}</span>@endif
                        </div>
                        <h3 class="market-title">{{ $item['title'] }} @if ($installable)<span class="muted">{{ $item['version'] }}</span>@endif</h3>
                        <p class="muted" style="margin: 0 0 8px">{{ $item['summary'] }}</p>
                        @if (! empty($item['includes']))
                            <p style="margin: 0 0 8px"><b>{{ __('Contains:') }}</b> {{ implode(', ', (array) $item['includes']) }}</p>
                        @endif
                        @if (filled($item['description']))
                            <details class="market-more"><summary>{{ __('Details') }}</summary><div style="white-space: pre-line; margin-top: 6px">{{ $item['description'] }}</div></details>
                        @endif
                        @if (! empty($item['expires_at']))
                            <div class="hint" @if ($expired) style="color: var(--danger)" @endif>{{ $expired
                                ? __('Updates and downloads ended on :date. The installed copy keeps working; renew to get new versions.', ['date' => $date($item['expires_at'])])
                                : __('Updates and downloads until :date.', ['date' => $date($item['expires_at'])]) }}</div>
                        @elseif (! $item['owned'] && (int) ($item['term_days'] ?? 0) > 0)
                            <div class="hint">{{ __('Includes updates for :days days; after that the installed copy keeps working.', ['days' => $item['term_days']]) }}</div>
                        @endif
                        @if (filled($item['license_key'] ?? null))
                            <div class="market-code"><span class="muted small">{{ __('Licence code') }}</span> <code>{{ $item['license_key'] }}</code></div>
                        @elseif ($item['owned'] && ! empty($item['licensed']))
                            <div class="hint">{{ __('The licence code of this purchase is being prepared. Press Refresh in a moment.') }}</div>
                        @endif
                        @unless ($item['compatible'])
                            <div class="hint" style="color: var(--danger)">{{ __('This item needs forum version :version or newer.', ['version' => $item['min_core']]) }}</div>
                        @endunless
                    </div>
                    <div class="block-foot row">
                        <strong class="market-price">{{ $item['price'] > 0 ? $price($item['price']) : __('Free') }}</strong>
                        <span class="spacer"></span>
                        @if ($item['order'] && $item['order']['status'] === 'review')
                            <span class="badge amber">{{ __('Awaiting approval') }}</span>
                            <a class="btn btn-sm" href="{{ $item['order']['pay_url'] }}" target="_blank" rel="noopener">{{ __('Order') }}</a>
                        @elseif ($item['owned'] && ! $expired)
                            @if (! empty($item['renewable']))
                                <form method="post" action="{{ route('admin.market.buy') }}" target="_blank">@csrf
                                    <input type="hidden" name="slug" value="{{ $item['slug'] }}">
                                    <button class="btn btn-sm" type="submit" title="{{ __('Extend the update period') }}">{{ __('Renew') }} · {{ $price($renewPrice) }}</button>
                                </form>
                            @endif
                            @if (! $installable)
                                <span class="muted small">{{ __('Install the items it contains below.') }}</span>
                            @elseif (! $item['installed_version'] || $item['update'])
                                <button class="btn btn-sm btn-primary" type="button" data-dialog="install-{{ $loop->index }}" @disabled(! $item['compatible'])>{{ $item['update'] ? __('Update') : __('Install') }}</button>
                            @else
                                <a class="btn btn-sm" href="{{ $item['type'] === 'theme' ? '#themes' : route('admin.addons') }}">{{ __('Manage') }}</a>
                                <button class="btn btn-sm" type="button" data-dialog="install-{{ $loop->index }}" title="{{ __('Download and install again') }}"><x-icon name="refresh" :size="14" /></button>
                            @endif
                        @else
                            <form method="post" action="{{ route('admin.market.buy') }}" target="_blank">@csrf
                                <input type="hidden" name="slug" value="{{ $item['slug'] }}">
                                <button class="btn btn-sm btn-primary" type="submit"><x-icon name="cart" :size="14" />
                                    {{ $item['order'] ? __('Continue to payment') : ($expired ? __('Renew').' · '.$price($renewPrice) : __('Buy')) }}</button>
                            </form>
                        @endif
                    </div>
                </section>

                @if ($item['owned'] && ! $expired && $installable)
                    <dialog class="dialog" id="install-{{ $loop->index }}">
                        <form method="post" action="{{ route('admin.market.install') }}">
                            @csrf
                            <input type="hidden" name="slug" value="{{ $item['slug'] }}">
                            <div class="block-head"><h2>{{ $item['title'] }} {{ $item['version'] }}</h2></div>
                            <div class="block-body">
                                <p class="muted" style="margin-top: 0">{{ $item['type'] === 'theme'
                                    ? __('The theme is downloaded from the market, its signature is checked and it is added to your themes.')
                                    : __('The module is downloaded from the market, its signature is checked and it is added to your add-ons. Modules are code that runs on your server, so your password is asked again.') }}</p>
                                <div class="field">
                                    <label>{{ __('Current password') }}</label>
                                    <input class="input" type="password" name="current_password" autocomplete="current-password" required>
                                </div>
                                <div class="form-actions">
                                    <button class="btn btn-primary" type="submit">{{ $item['update'] ? __('Update') : __('Install') }}</button>
                                    <button class="btn" type="button" data-dialog-close>{{ __('Cancel') }}</button>
                                </div>
                            </div>
                        </form>
                    </dialog>
                @endif
            @endforeach
        </div>
        <p class="muted small">{{ __('After paying, come back to this page and press Refresh: the item changes to "Install". A purchase belongs to this forum installation.') }}</p>
    @endif

    @error('current_password')<div class="flash error">{{ $message }}</div>@enderror

    <section class="block" id="themes" style="margin-top: 16px">
        <div class="block-head"><x-icon name="droplet" :size="16" /><h2>{{ __('Installed themes') }}</h2></div>
        @forelse ($themes as $id => $theme)
            @php
                $on = $activeTheme === $id;
                $product = $themeManager->licenseProduct($id);
                $licensed = $themeManager->licensed($id);
            @endphp
            <div class="item" style="align-items: center">
                <span class="node-icon" style="width: 40px; height: 40px; background: {{ \App\Forum\Theme::isHex($theme['colors']['header'] ?? null) ? $theme['colors']['header'] : 'var(--surface-3)' }}; color: #fff"><x-icon name="droplet" :size="18" /></span>
                <div class="body">
                    <div class="title">{{ $theme['title'] }} <span class="muted" style="font-weight: 400; font-size: 13px">{{ $theme['version'] }}</span>
                        @if ($on)<span class="badge green">{{ __('In use') }}</span>@endif
                        @if ($product && $licensed)<span class="badge blue">{{ __('Licensed') }}</span>@elseif ($product)<span class="badge amber">{{ __('Licence code needed') }}</span>@endif
                    </div>
                    <div class="snippet">{{ $theme['description'] ?? '' }}</div>
                    <div class="thread-meta"><code>{{ $id }}</code>@if (! empty($theme['author'])) <span>·</span> {{ $theme['author'] }}@endif</div>
                    @if ($product && ! $licensed)
                        <form class="row" method="post" action="{{ route('admin.license.activate') }}" style="margin-top: 8px; flex-wrap: nowrap; max-width: 480px">
                            @csrf
                            <input type="hidden" name="product" value="{{ $product }}">
                            <input class="input" name="license_key" maxlength="64" placeholder="{{ __('Licence code of this theme') }}" aria-label="{{ __('Licence code of this theme') }}" autocomplete="off" spellcheck="false" required>
                            <button class="btn btn-sm btn-primary" type="submit">{{ __('Activate licence') }}</button>
                        </form>
                    @endif
                </div>
                <form class="row" method="post" action="{{ route('admin.market.theme') }}">
                    @csrf
                    <input type="hidden" name="id" value="{{ $id }}">
                    @if ($on)
                        <button class="btn btn-sm" name="action" value="deactivate">{{ __('Switch off') }}</button>
                    @else
                        <button class="btn btn-sm btn-primary" name="action" value="activate" @disabled(! $licensed)>{{ __('Use this theme') }}</button>
                        <button class="btn btn-sm btn-danger" name="action" value="delete" data-confirm="{{ __('Delete this theme?') }}" title="{{ __('Delete') }}"><x-icon name="trash" :size="14" /></button>
                    @endif
                </form>
            </div>
        @empty
            <div class="empty">{{ __('No themes are installed.') }}</div>
        @endforelse
        <form class="block-foot row" method="post" action="{{ route('admin.market.theme.upload') }}" enctype="multipart/form-data">
            @csrf
            <span class="muted small">{{ __('Have a theme as a file?') }}</span>
            <input class="input" style="flex: 1; min-width: 200px" type="file" name="package" accept=".zip" required>
            <input class="input" style="width: 160px" type="password" name="current_password" autocomplete="current-password" placeholder="{{ __('Your password') }}" required>
            <button class="btn btn-sm" type="submit">{{ __('Upload theme') }}</button>
        </form>
        @error('package')<div class="block-foot"><span class="field"><span class="error">{{ $message }}</span></span></div>@enderror
    </section>
@endsection
