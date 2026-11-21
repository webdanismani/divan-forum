@extends('layouts.admin')

@section('title', $ad->exists ? __('Edit ad') : __('Add ad'))
@section('subtitle', $ad->exists ? __('Ad order #:id', ['id' => $ad->id]) : __('An ad you add here is published straight away, without payment.'))

@section('content')
    <form class="medium" style="margin-left: 0" method="post" enctype="multipart/form-data"
        action="{{ $ad->exists ? route('admin.ads.update', $ad) : route('admin.ads.store') }}">
        @csrf
        @if ($ad->exists) @method('PUT') @endif

        <section class="block">
            <div class="block-head"><h2>{{ __('Ad') }}</h2></div>
            <div class="block-body">
                @if ($ad->exists && $ad->user)
                    <div class="field"><span class="label">{{ __('Advertiser') }}</span>
                        <x-username :user="$ad->user" />
                        @if ($ad->billing_name)<span class="muted"> · {{ $ad->billing_name }} · {{ $ad->billing_phone }}</span>@endif
                        @if ($ad->billing_address)<div class="muted small">{{ $ad->billing_address }}</div>@endif
                    </div>
                @endif
                <div class="form-grid">
                    <div class="field">
                        <label for="zone_id">{{ __('Ad space') }}</label>
                        <select class="input" id="zone_id" name="zone_id" required>
                            @foreach ($zones as $zone)
                                <option value="{{ $zone->id }}" @selected((int) old('zone_id', $ad->zone_id) === $zone->id)>{{ $zone->title }} ({{ $zone->size() }})</option>
                            @endforeach
                        </select>
                        @error('zone_id')<div class="error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="title">{{ __('Ad title') }}</label>
                        <input class="input" id="title" name="title" value="{{ old('title', $ad->title) }}" maxlength="80" required>
                        @error('title')<div class="error">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="field">
                    <label for="target_url">{{ __('Link address') }}</label>
                    <input class="input" id="target_url" type="url" name="target_url" value="{{ old('target_url', $ad->target_url) }}" maxlength="255" placeholder="https://" required>
                    @error('target_url')<div class="error">{{ $message }}</div>@enderror
                </div>
                <div class="field" style="margin-bottom: 0">
                    <label for="image">{{ __('Banner image') }}</label>
                    @if ($ad->imageUrl())<img class="banner-preview" src="{{ $ad->imageUrl() }}" alt="" style="margin-bottom: 8px; max-height: 260px">@endif
                    <input class="input" id="image" type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp" @required(! $ad->exists)>
                    <div class="hint">JPG, PNG, GIF, WebP · {{ __('2 MB at most') }}@if ($ad->exists) · {{ __('leave empty to keep the current banner') }}@endif</div>
                    @error('image')<div class="error">{{ $message }}</div>@enderror
                </div>
            </div>
        </section>

        <section class="block">
            <div class="block-head"><h2>{{ __('Period and price') }}</h2></div>
            <div class="block-body">
                <div class="form-grid">
                    <div class="field">
                        <label for="days">{{ __('Period (days)') }}</label>
                        <input class="input" id="days" type="number" name="days" value="{{ old('days', $ad->days) }}" min="1" max="730" required>
                        @error('days')<div class="error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="price">{{ __('Price') }} ({{ config('forum.ads.currency_symbol') }})</label>
                        <input class="input" id="price" type="number" step="0.01" name="price" value="{{ old('price', $ad->price ?? 0) }}" min="0">
                        @error('price')<div class="error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="starts_at">{{ __('Starts') }}</label>
                        <input class="input" id="starts_at" type="datetime-local" name="starts_at" value="{{ old('starts_at', $ad->starts_at?->format('Y-m-d\TH:i')) }}">
                        @unless ($ad->exists)<div class="hint">{{ __('Leave empty to start now.') }}</div>@endunless
                        @error('starts_at')<div class="error">{{ $message }}</div>@enderror
                    </div>
                    @if ($ad->exists)
                        <div class="field">
                            <label for="ends_at">{{ __('Ends') }}</label>
                            <input class="input" id="ends_at" type="datetime-local" name="ends_at" value="{{ old('ends_at', $ad->ends_at?->format('Y-m-d\TH:i')) }}">
                            @error('ends_at')<div class="error">{{ $message }}</div>@enderror
                        </div>
                    @endif
                </div>
                <div class="field" style="margin-bottom: 0">
                    <label for="admin_note">{{ __('Administrator note') }}</label>
                    <textarea class="input" id="admin_note" name="admin_note" maxlength="500" style="min-height: 70px">{{ old('admin_note', $ad->admin_note) }}</textarea>
                    <div class="hint">{{ __('Shown to the advertiser when the ad is rejected.') }}</div>
                </div>
            </div>
        </section>

        <div class="form-actions" style="margin-top: 16px">
            <button class="btn btn-primary" type="submit">{{ $ad->exists ? __('Save') : __('Publish ad') }}</button>
            <a class="btn" href="{{ route('admin.ads.index') }}">{{ __('Cancel') }}</a>
        </div>
    </form>
@endsection
