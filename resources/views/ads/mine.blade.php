@extends('layouts.app')

@section('title', __('My ads'))
@section('crumb', __('My ads'))

@section('content')
    <div class="page-head">
        <div class="titles">
            <h1>{{ __('My ads') }}</h1>
            <div class="desc">{{ __('Your banner orders, their status and how they perform.') }}</div>
        </div>
        <a class="btn btn-primary" href="{{ route('ads.index') }}"><x-icon name="plus" :size="16" /> {{ __('New ad') }}</a>
    </div>

    <section class="block">
        @if ($ads->isEmpty())
            <div class="empty">{{ __('You have not ordered any ads yet.') }}</div>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr>
                        <th>{{ __('Banner') }}</th><th>{{ __('Ad') }}</th><th>{{ __('Period') }}</th><th>{{ __('Total') }}</th>
                        <th>{{ __('Status') }}</th><th>{{ __('Ends') }}</th><th>{{ __('Impressions') }} / {{ __('Clicks') }}</th><th></th>
                    </tr></thead>
                    <tbody>
                        @foreach ($ads as $ad)
                            <tr>
                                <td>@if ($ad->imageUrl())<img class="ad-thumb" src="{{ $ad->imageUrl() }}" alt="">@endif</td>
                                <td><a href="{{ route('ads.show', $ad) }}"><b>{{ $ad->title }}</b></a><div class="muted small">{{ $ad->zone->title }}</div></td>
                                <td class="nowrap">{{ __(':days days', ['days' => $ad->days]) }}</td>
                                <td class="nowrap">{{ forum_money($ad->price) }}</td>
                                <td><span class="badge {{ $ad->stateColor() }}">{{ __($ad->stateLabel()) }}</span></td>
                                <td class="nowrap">{{ $ad->ends_at?->translatedFormat('j M Y') ?? '—' }}</td>
                                <td class="nowrap">{{ forum_number($ad->impressions) }} / {{ forum_number($ad->clicks) }}</td>
                                <td class="actions">
                                    <a class="btn btn-sm {{ $ad->status === 'unpaid' ? 'btn-primary' : '' }}" href="{{ route('ads.show', $ad) }}">{{ $ad->status === 'unpaid' ? __('Pay') : __('View') }}</a>
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
