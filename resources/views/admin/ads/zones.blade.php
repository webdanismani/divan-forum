@extends('layouts.admin')

@section('title', __('Ad spaces'))
@section('subtitle', __('The places where banners are shown, how many fit at once and what each period costs.'))

@section('actions')
    <a class="btn" href="{{ route('admin.ads.index') }}"><x-icon name="megaphone" :size="16" /> {{ __('Ads') }}</a>
@endsection

@section('content')
    <section class="block">
        <div class="table-wrap">
            <table class="table">
                <thead><tr>
                    <th>{{ __('Ad space') }}</th><th>{{ __('Banner size') }}</th><th>{{ __('In use') }}</th><th>{{ __('Prices') }}</th><th>{{ __('Status') }}</th><th></th>
                </tr></thead>
                <tbody>
                    @foreach ($zones as $zone)
                        <tr>
                            <td><b>{{ $zone->title }}</b><div class="muted small">{{ $zone->description }}</div></td>
                            <td class="nowrap">{{ $zone->size() }} px</td>
                            <td class="nowrap">{{ $used[$zone->id] }} / {{ $zone->slots }}</td>
                            <td>
                                @forelse ($zone->priceList() as $days => $price)
                                    <span class="badge">{{ __(':days days', ['days' => $days]) }}: {{ forum_money($price) }}</span>
                                @empty
                                    <span class="muted">{{ __('No prices: not on sale') }}</span>
                                @endforelse
                            </td>
                            <td>@if ($zone->is_active)<span class="badge green">{{ __('On') }}</span>@else<span class="badge">{{ __('Off') }}</span>@endif</td>
                            <td class="actions"><a class="btn btn-sm" href="{{ route('admin.ad-zones.edit', $zone) }}"><x-icon name="edit" :size="14" /> {{ __('Edit') }}</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endsection
