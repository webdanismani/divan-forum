@extends('layouts.admin')

@section('title', __('Dashboard'))
@section('subtitle', __('What is happening on the forum, and what is waiting for you.'))

@section('content')
    @php
        $waiting = $acpPending->waiting();
        $shortcuts = [
            [route('admin.nodes.create'), 'plus', __('Add a forum')],
            [route('admin.users.index'), 'users', __('Find a member')],
            [route('admin.notices'), 'bell', __('Write a notice')],
            [route('admin.mail'), 'megaphone', __('E-mail to members')],
            [route('admin.backups'), 'folder', __('Take a backup')],
            [route('admin.settings.edit'), 'sliders', __('Settings')],
        ];
    @endphp

    <div class="acp-tiles">
        @foreach ($stats as $label => [$value, $icon, $url, $alarming])
            <a @class(['acp-tile', 'warn' => $alarming && $value > 0]) href="{{ $url }}">
                <span class="ico"><x-icon :name="$icon" :size="19" /></span>
                <div><b>{{ number_format($value) }}</b><span>{{ __($label) }}</span></div>
            </a>
        @endforeach
    </div>

    @if ($marketUpdates = app(\App\Forum\Store\Client::class)->pendingUpdates())
        <div class="flash info"><x-icon name="refresh" :size="15" /> {{ trans_choice('{1} An update is available in the market.|[2,*] :count updates are available in the market.', $marketUpdates, ['count' => $marketUpdates]) }} <a href="{{ route('admin.market') }}">{{ __('Open the market') }}</a></div>
    @endif

    @hook('admin.dashboard')

    <div class="acp-cols">
        <div>
            @include('admin._checklist')

            <section class="block">
                <div class="block-head"><x-icon name="chart" :size="16" /><h2>{{ __('Last 30 days') }}</h2></div>
                <div class="acp-charts">
                    @foreach ($charts as $label => $series)
                        @php $peak = max(1, max($series)); @endphp
                        <div>
                            <h3><b>{{ forum_number(array_sum($series)) }}</b> {{ __($label) }}</h3>
                            <div class="bars" role="img" aria-label="{{ __($label) }}">
                                @foreach ($series as $day => $value)
                                    <i style="height: {{ max(2, round($value / $peak * 100)) }}%" @class(['zero' => $value === 0]) title="{{ \Illuminate\Support\Carbon::parse($day)->translatedFormat('j M') }}: {{ $value }}"></i>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="block">
                <div class="block-head"><x-icon name="users" :size="16" /><h2>{{ __('Newest members') }}</h2><span class="spacer"></span><a href="{{ route('admin.users.index') }}">{{ __('All members') }}</a></div>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>{{ __('Member') }}</th><th>{{ __('E-mail address') }}</th><th>{{ __('Group') }}</th><th>{{ __('Joined') }}</th></tr></thead>
                        <tbody>
                            @foreach ($newMembers as $member)
                                <tr>
                                    <td><div class="row" style="flex-wrap: nowrap"><x-avatar :user="$member" size="xs" :link="false" /> <a href="{{ route('admin.users.edit', $member) }}" style="font-weight: 600">{{ $member->username }}</a></div></td>
                                    <td class="muted">{{ $member->email }}</td>
                                    <td>{{ $member->group?->name }}</td>
                                    <td class="nowrap"><x-time :at="$member->created_at" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="block">
                <div class="block-head"><x-icon name="shield" :size="16" /><h2>{{ __('Recent moderator actions') }}</h2><span class="spacer"></span><a href="{{ route('admin.logs') }}">{{ __('Full log') }}</a></div>
                @if ($recentLogs->isEmpty())
                    <div class="empty">{{ __('No moderator actions yet.') }}</div>
                @else
                    <ul class="side-list">
                        @foreach ($recentLogs as $log)
                            <li><div class="meta"><span class="title" style="color: var(--text); font-weight: 600">{{ $log->user?->username ?? '—' }} · <code>{{ $log->action }}</code></span>{{ $log->summary }} · <x-time :at="$log->created_at" /></div></li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>

        <div>
            <section class="block">
                <div class="block-head"><x-icon name="bell" :size="16" /><h2>{{ __('Waiting for you') }}</h2>@if ($acpPending->total())<span class="badge amber">{{ $acpPending->total() }}</span>@endif</div>
                @if ($waiting)
                    <div class="acp-rows">
                        @foreach ($waiting as $item)
                            <a href="{{ $item['url'] }}"><x-icon :name="$item['icon']" :size="16" /> {{ __($item['label']) }} <span class="badge amber">{{ $item['count'] }}</span><x-icon name="chevron-right" :size="13" class="go" /></a>
                        @endforeach
                    </div>
                @else
                    <div class="acp-calm"><x-icon name="check" :size="17" /> {{ __('Nothing is waiting.') }}</div>
                @endif
            </section>

            <section class="block">
                <div class="block-head"><x-icon name="bolt" :size="16" /><h2>{{ __('Shortcuts') }}</h2></div>
                <div class="acp-rows">
                    @foreach ($shortcuts as [$url, $icon, $text])
                        <a href="{{ $url }}"><x-icon :name="$icon" :size="16" /> {{ $text }} <x-icon name="chevron-right" :size="13" class="go" /></a>
                    @endforeach
                </div>
            </section>

            <section class="block">
                <div class="block-head"><x-icon name="key" :size="16" /><h2>{{ __('System') }}</h2></div>
                <div class="block-body">
                    <dl class="kv">
                        <div><dt>{{ config('forum.product.name') }}</dt><dd>{{ config('forum.product.version') }} ({{ app(\App\Forum\Edition::class)->name() }})</dd></div>
                        @foreach ($system as $label => $value)
                            <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
                        @endforeach
                    </dl>
                </div>
            </section>
        </div>
    </div>
@endsection
