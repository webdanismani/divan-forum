@extends('layouts.admin')

@section('title', $zone->title)
@section('subtitle', __('Ad space'))

@section('content')
    @php $rows = collect($zone->priceList())->map(fn ($price, $days) => [$days, $price])->values()->pad(6, [null, null]); @endphp

    <form class="medium" style="margin-left: 0" method="post" action="{{ route('admin.ad-zones.update', $zone) }}">
        @csrf @method('PUT')

        <section class="block">
            <div class="block-head"><h2>{{ __('Ad space') }}</h2></div>
            <div class="block-body">
                <div class="field">
                    <label for="title">{{ __('Title') }}</label>
                    <input class="input" id="title" name="title" value="{{ old('title', $zone->title) }}" maxlength="80" required>
                    @error('title')<div class="error">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label for="description">{{ __('Description') }}</label>
                    <input class="input" id="description" name="description" value="{{ old('description', $zone->description) }}" maxlength="255">
                </div>
                <div class="form-grid" style="grid-template-columns: repeat(3, minmax(0, 1fr))">
                    <div class="field">
                        <label for="width">{{ __('Width (px)') }}</label>
                        <input class="input" id="width" type="number" name="width" value="{{ old('width', $zone->width) }}" min="50" max="2000" required>
                        @error('width')<div class="error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="height">{{ __('Height (px)') }}</label>
                        <input class="input" id="height" type="number" name="height" value="{{ old('height', $zone->height) }}" min="20" max="2000" required>
                        @error('height')<div class="error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="slots">{{ __('Banners shown at once') }}</label>
                        <input class="input" id="slots" type="number" name="slots" value="{{ old('slots', $zone->slots) }}" min="1" max="10" required>
                        @error('slots')<div class="error">{{ $message }}</div>@enderror
                    </div>
                </div>
                <label class="check"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $zone->is_active))> {{ __('This ad space is in use') }}</label>
            </div>
        </section>

        <section class="block">
            <div class="block-head"><h2>{{ __('Prices') }}</h2></div>
            <div class="block-body">
                <p class="muted">{{ __('One row per period members can buy. Empty rows are ignored; without any price the space is not on sale.') }}</p>
                <div class="price-rows">
                    <span class="label" style="margin: 0">{{ __('Period (days)') }}</span>
                    <span class="label" style="margin: 0">{{ __('Price') }} ({{ config('forum.ads.currency_symbol') }})</span>
                    @foreach ($rows as $i => [$days, $price])
                        <input class="input" type="number" name="days[{{ $i }}]" value="{{ old('days.'.$i, $days) }}" min="1" max="730" aria-label="{{ __('Period (days)') }}">
                        <input class="input" type="number" step="0.01" name="price[{{ $i }}]" value="{{ old('price.'.$i, $price) }}" min="0" aria-label="{{ __('Price') }}">
                    @endforeach
                </div>
                @error('days.*')<div class="field"><div class="error">{{ $message }}</div></div>@enderror
                @error('price.*')<div class="field"><div class="error">{{ $message }}</div></div>@enderror
            </div>
        </section>

        <div class="form-actions" style="margin-top: 16px">
            <button class="btn btn-primary" type="submit">{{ __('Save') }}</button>
            <a class="btn" href="{{ route('admin.ad-zones.index') }}">{{ __('Cancel') }}</a>
        </div>
    </form>
@endsection
