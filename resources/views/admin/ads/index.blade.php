@extends('layouts.admin')

@section('title', __('Ads'))
@section('subtitle', __('Banner orders from members and the ads you add yourself.'))

@section('actions')
    <a class="btn" href="{{ route('admin.ad-zones.index') }}"><x-icon name="grid" :size="16" /> {{ __('Ad spaces') }}</a>
    <a class="btn btn-primary" href="{{ route('admin.ads.create') }}"><x-icon name="plus" :size="16" /> {{ __('Add ad') }}</a>
@endsection

@section('content')
    @php
        $labels = [
            'all' => __('All'), 'review' => __('Awaiting approval'), 'unpaid' => __('Awaiting payment'), 'active' => __('Live'),
            'paused' => __('Paused'), 'expired' => __('Expired'), 'rejected' => __('Rejected'),
        ];
    @endphp

    <div class="stat-grid">
        <div class="block stat-card"><div class="num">{{ forum_number($counts['active']) }}</div><div class="muted">{{ __('Live') }}</div></div>
        <div class="block stat-card"><div class="num">{{ forum_number($counts['review']) }}</div><div class="muted">{{ __('Awaiting approval') }}</div></div>
        <div class="block stat-card"><div class="num">{{ forum_number($counts['unpaid']) }}</div><div class="muted">{{ __('Awaiting payment') }}</div></div>
        <div class="block stat-card"><div class="num">{{ forum_money($income) }}</div><div class="muted">{{ __('Income received') }}</div></div>
    </div>

    <section class="block" style="margin-bottom: 14px">
        <div class="block-head"><x-icon name="card" :size="16" /><h2>{{ __('Payment methods') }}</h2><span class="spacer"></span><a href="{{ route('admin.settings.edit') }}">{{ __('Settings') }}</a></div>
        <div class="block-body">
            <dl class="kv">
                <div><dt>{{ __('Card payments (PayTR)') }}</dt><dd>
                    @if ($cardEnabled)
                        <span class="badge green">{{ __('On') }}</span>@if ($testMode) <span class="badge amber">{{ __('Test mode') }}</span>@endif
                    @else
                        <span class="badge">{{ __('Off') }}</span> <span class="muted" style="font-weight: 400">{{ __('enter the PayTR merchant details in the settings') }}</span>
                    @endif
                </dd></div>
                <div><dt>{{ __('Bank transfer / EFT') }}</dt><dd>
                    @if ($transferEnabled)<span class="badge green">{{ __('On') }}</span>@else<span class="badge">{{ __('Off') }}</span>@endif
                </dd></div>
                <div><dt>{{ __('PayTR notification URL') }}</dt><dd><code>{{ route('ads.paytr') }}</code></dd></div>
            </dl>
        </div>
    </section>

    <nav class="tabs">
        @foreach ($labels as $key => $label)
            <a href="{{ route('admin.ads.index', $key === 'all' ? [] : ['status' => $key]) }}" @class(['active' => $filter === $key])>{{ $label }} ({{ $counts[$key] }})</a>
        @endforeach
    </nav>

    <section class="block">
        @if ($ads->isEmpty())
            <div class="empty">{{ __('There are no ads here.') }}</div>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr>
                        <th>#</th><th>{{ __('Banner') }}</th><th>{{ __('Ad') }}</th><th>{{ __('Ad space') }}</th><th>{{ __('Period') }}</th>
                        <th>{{ __('Payment') }}</th><th>{{ __('Status') }}</th><th>{{ __('Impressions') }} / {{ __('Clicks') }}</th><th></th>
                    </tr></thead>
                    <tbody>
                        @foreach ($ads as $ad)
                            @php $state = $ad->state(); @endphp
                            <tr>
                                <td>{{ $ad->id }}</td>
                                <td>@if ($ad->imageUrl())<a href="{{ $ad->imageUrl() }}" target="_blank" rel="noopener"><img class="ad-thumb" src="{{ $ad->imageUrl() }}" alt=""></a>@endif</td>
                                <td>
                                    <b>{{ $ad->title }}</b>
                                    <div class="muted small truncate" style="max-width: 240px"><a href="{{ $ad->target_url }}" target="_blank" rel="noopener nofollow">{{ $ad->target_url }}</a></div>
                                    <div class="small">@if ($ad->user)<x-username :user="$ad->user" />@else<span class="muted">{{ __('Added by an administrator') }}</span>@endif</div>
                                    @if ($ad->note)<div class="muted small">“{{ $ad->note }}”</div>@endif
                                </td>
                                <td>{{ $ad->zone->title }}</td>
                                <td class="nowrap">
                                    {{ __(':days days', ['days' => $ad->days]) }}
                                    @if ($ad->starts_at)<div class="muted small">{{ $ad->starts_at->translatedFormat('j M') }} – {{ $ad->ends_at?->translatedFormat('j M Y') }}</div>@endif
                                </td>
                                <td class="nowrap">
                                    {{ forum_money($ad->price) }}
                                    <div class="small">
                                        @if ($ad->paid_at)
                                            <span class="badge green">{{ __('Paid') }}</span> <span class="muted">{{ __(\App\Models\Ad::METHODS[$ad->payment_method] ?? '') }}</span>
                                        @elseif ($ad->payment_method === 'transfer')
                                            <span class="badge amber">{{ __('Transfer reported') }}</span>
                                        @elseif ($ad->payment_method !== 'manual')
                                            <span class="badge">{{ __('Not paid yet') }}</span>
                                        @endif
                                    </div>
                                </td>
                                <td><span class="badge {{ $ad->stateColor() }}">{{ __($ad->stateLabel()) }}</span></td>
                                <td class="nowrap">{{ forum_number($ad->impressions) }} / {{ forum_number($ad->clicks) }}</td>
                                <td class="actions">
                                    <form style="display: inline" method="post" action="{{ route('admin.ads.action', $ad) }}">@csrf
                                        @if (in_array($state, ['review', 'unpaid', 'rejected', 'expired'], true))
                                            <button class="btn btn-sm btn-primary" name="action" value="approve" title="{{ __('Publish for the ordered period, starting now') }}"
                                                @if (! $ad->paid_at && $ad->payment_method !== 'manual') data-confirm="{{ __('This order has no confirmed payment. Mark it as paid and publish it?') }}" @endif>
                                                <x-icon name="check" :size="14" /> {{ $state === 'expired' ? __('Renew') : __('Approve') }}
                                            </button>
                                        @endif
                                        @if (in_array($state, ['review', 'unpaid'], true))
                                            <button class="btn btn-sm btn-danger" name="action" value="reject" data-confirm="{{ __('Reject this ad?') }}"><x-icon name="x" :size="14" /> {{ __('Reject') }}</button>
                                        @endif
                                        @if (in_array($state, ['active', 'scheduled'], true))
                                            <button class="btn btn-sm" name="action" value="pause"><x-icon name="pause" :size="14" /> {{ __('Pause') }}</button>
                                        @endif
                                        @if ($state === 'paused')
                                            <button class="btn btn-sm" name="action" value="resume"><x-icon name="play" :size="14" /> {{ __('Resume') }}</button>
                                        @endif
                                    </form>
                                    <a class="btn btn-sm" href="{{ route('admin.ads.edit', $ad) }}" title="{{ __('Edit') }}"><x-icon name="edit" :size="14" /></a>
                                    <form style="display: inline" method="post" action="{{ route('admin.ads.destroy', $ad) }}" data-confirm="{{ __('Delete this ad?') }}">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-sm btn-danger" type="submit" title="{{ __('Delete') }}"><x-icon name="trash" :size="14" /></button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{ $ads->links() }}
@endsection
