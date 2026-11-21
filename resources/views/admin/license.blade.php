@extends('layouts.admin')

@section('title', __('Licence'))
@section('subtitle', __('Licence codes of the forum and of the modules and themes bought for it. A sold module or theme only runs while its code is active here.'))

@section('actions')
    @if ($configured && $licenses->isNotEmpty())
        <form method="post" action="{{ route('admin.license.check') }}">@csrf
            <button class="btn" type="submit"><x-icon name="refresh" :size="15" /> {{ __('Check now') }}</button>
        </form>
    @endif
@endsection

@section('content')
    @php $kinds = ['core' => __('Forum software'), 'module' => __('Module'), 'theme' => __('Theme')]; @endphp

    @unless ($configured)
        <div class="flash error">{{ __('The licence server is not set up on this forum.') }}</div>
    @endunless

    <div class="acp-cols">
        <div>
            <form class="block" method="post" action="{{ route('admin.license.activate') }}">
                @csrf
                <div class="block-head"><x-icon name="key" :size="16" /><h2>{{ __('Add a licence code') }}</h2></div>
                <div class="block-body">
                    <div class="field">
                        <label for="license_key">{{ __('Licence code') }}</label>
                        <div class="row" style="flex-wrap: nowrap">
                            <input class="input" id="license_key" name="license_key" value="{{ old('license_key') }}" maxlength="64" autocomplete="off" spellcheck="false" required style="font-family: var(--mono, monospace)">
                            <button class="btn btn-primary" type="submit" @disabled(! $configured)>{{ __('Activate') }}</button>
                        </div>
                        @error('license_key')<div class="error">{{ $message }}</div>@enderror
                        <div class="hint">{{ __('You get a separate code for every module and theme you buy; it is shown in the market next to the purchase. The code itself tells the forum which product it is for.') }}
                            @if ($getUrl = config('forum.license.get_url'))<a href="{{ $getUrl }}" target="_blank" rel="noopener">{{ __('Get a free licence code') }}</a>@endif
                        </div>
                    </div>
                </div>
            </form>

            <section class="block">
                <div class="block-head"><x-icon name="check" :size="16" /><h2>{{ __('Licences on this forum') }}</h2></div>
                @if ($licenses->isEmpty())
                    <div class="empty">{{ __('No licence code has been entered yet.') }}</div>
                @else
                    <div class="table-wrap">
                        <table class="table">
                            <thead><tr><th>{{ __('Product') }}</th><th>{{ __('Licence code') }}</th><th>{{ __('Status') }}</th><th>{{ __('Last check') }}</th><th></th></tr></thead>
                            <tbody>
                                @foreach ($licenses as $product => $license)
                                    @php $item = $products[$product] ?? null; $ok = in_array($product, $valid, true); @endphp
                                    <tr>
                                        <td>
                                            <b>{{ $item['title'] ?? $product }}</b>
                                            <div class="muted small">{{ $item ? $kinds[$item['kind']] : __('Not installed') }} · <code>{{ $product }}</code></div>
                                        </td>
                                        <td class="nowrap"><code>{{ $license->maskedKey() }}</code></td>
                                        <td>
                                            @if ($ok)
                                                <span class="badge green">{{ __('Active') }}</span>
                                                <div class="muted small">{{ $license->license_ends_at ? __('Until :date', ['date' => $license->license_ends_at->translatedFormat('j M Y')]) : __('Unlimited') }}</div>
                                            @else
                                                <span class="badge red">{{ __('Not valid') }}</span>
                                                <div class="muted small">{{ $license->message ?: __('The last answer of the licence server has run out.') }}</div>
                                            @endif
                                        </td>
                                        <td class="nowrap">@if ($license->checked_at)<x-time :at="$license->checked_at" />@endif</td>
                                        <td class="nowrap">
                                            <form method="post" action="{{ route('admin.license.release') }}">@csrf
                                                <input type="hidden" name="product" value="{{ $product }}">
                                                <button class="btn btn-sm" type="submit" data-confirm="{{ __('Release this licence? Its module or theme stops working on this forum until a code is entered again.') }}">{{ __('Release') }}</button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                <div class="block-foot muted small">{{ __('The forum asks the licence server about every code once an hour. If the server cannot be reached, a licence keeps working for a few days on its last answer.') }}</div>
            </section>

            @if ($missing)
                <section class="block">
                    <div class="block-head"><x-icon name="lock" :size="16" /><h2>{{ __('Waiting for a licence code') }}</h2><span class="badge amber">{{ count($missing) }}</span></div>
                    <div class="acp-rows">
                        @foreach ($missing as $product => $item)
                            <a href="{{ $item['kind'] === 'theme' ? route('admin.market').'#themes' : route('admin.addons') }}"><x-icon :name="$item['kind'] === 'theme' ? 'droplet' : 'grid'" :size="16" /> {{ $item['title'] }} <span class="muted small">{{ $kinds[$item['kind']] }}</span><x-icon name="chevron-right" :size="13" class="go" /></a>
                        @endforeach
                    </div>
                    <div class="block-foot muted small">{{ __('These are installed but do not run: enter the code you received for each of them above.') }}</div>
                </section>
            @endif
        </div>

        <div>
            <section class="block">
                <div class="block-head"><x-icon name="shield" :size="16" /><h2>{{ __('This installation') }}</h2></div>
                <div class="block-body">
                    <dl class="kv">
                        <div><dt>{{ __('Product') }}</dt><dd>{{ config('forum.product.name') }} {{ config('forum.product.version') }}</dd></div>
                        <div><dt>{{ __('Forum licence') }}</dt><dd>@if (in_array($coreProduct, $valid, true))<span class="badge green">{{ __('Active') }}</span>@else<span class="badge">{{ __('None') }}</span>@endif</dd></div>
                        <div><dt>{{ __('Address') }}</dt><dd>{{ $domain }}</dd></div>
                    </dl>
                    <p class="muted small" style="margin-bottom: 0">{{ __('A licence is tied to this address. Before moving the forum to another address, release its licences here and enter the codes again after the move.') }}</p>
                </div>
            </section>

            <section class="block">
                <div class="block-head"><x-icon name="sliders" :size="16" /><h2>{{ __('Edition') }}</h2><span class="spacer"></span><span @class(['badge', 'green' => $edition->isPremium(), 'blue' => ! $edition->isPremium()])>{{ $edition->isPremium() ? __('Premium') : __('Free') }}</span></div>
                <ul class="side-list">
                    @foreach ($edition->premiumFeatures() as $key => $description)
                        <li>
                            <x-icon :name="$edition->has($key) ? 'check' : 'lock'" :size="16" />
                            <div class="meta"><span class="title" style="color: var(--text); font-weight: 600; font-size: 13.5px">{{ __($description) }}</span>{{ $edition->has($key) ? __('Available') : __('Planned for the premium package') }}</div>
                        </li>
                    @endforeach
                </ul>
            </section>
        </div>
    </div>
@endsection
