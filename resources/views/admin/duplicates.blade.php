@extends('layouts.admin')

@section('title', __('Shared accounts'))
@section('subtitle', __('Accounts that were used in the same browser or from the same IP address. One person behind several names is the usual reason — not the only one.'))

@section('content')
    <section class="block">
        <div class="block-head"><x-icon name="monitor" :size="16" /><h2>{{ __('Same browser') }}</h2><span class="spacer"></span><span class="muted">{{ count($byDevice) }}</span></div>
        @if (! $byDevice)
            <div class="empty">{{ __('No two accounts have signed in from the same browser so far.') }}</div>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>{{ __('Accounts') }}</th><th class="nowrap">{{ __('Last seen together') }}</th></tr></thead>
                    <tbody>
                        @foreach ($byDevice as $group)
                            <tr>
                                <td>@include('admin.partials.account-chips', ['accounts' => $group['users']])</td>
                                <td class="nowrap muted">{{ $group['seen'] ? \Illuminate\Support\Carbon::parse($group['seen'])->diffForHumans() : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        <div class="block-foot muted small">{{ __('A strong sign: every browser carries its own mark, and these accounts signed in with the same one. A shared family or office computer looks the same.') }}</div>
    </section>

    <section class="block" style="margin-top: 16px">
        <div class="block-head"><x-icon name="link" :size="16" /><h2>{{ __('Same IP address') }}</h2><span class="spacer"></span><span class="muted">{{ count($byIp) }}</span></div>
        @if (! $byIp)
            <div class="empty">{{ __('No IP address was used by several accounts in the last 90 days.') }}</div>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>{{ __('IP address') }}</th><th>{{ __('Accounts') }}</th><th class="nowrap">{{ __('Last used') }}</th></tr></thead>
                    <tbody>
                        @foreach ($byIp as $group)
                            <tr>
                                <td class="nowrap"><a href="{{ route('admin.bans', ['ip' => $group['ip']]) }}">{{ $group['ip'] }}</a></td>
                                <td>@include('admin.partials.account-chips', ['accounts' => $group['users']])</td>
                                <td class="nowrap muted">{{ $group['seen'] ? \Illuminate\Support\Carbon::parse($group['seen'])->diffForHumans() : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        <div class="block-foot muted small">{{ __('A weak sign: a household, an office or a mobile network share one address. Addresses used by more than :count accounts are left out.', ['count' => \App\Forum\Security\Duplicates::CROWD]) }}</div>
    </section>
@endsection
