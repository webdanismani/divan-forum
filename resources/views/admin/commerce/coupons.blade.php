@extends('layouts.admin')

@section('title', __('Coupon codes'))
@section('subtitle', __('Discount codes advertisers can enter when they order an ad.'))

@section('content')
    <section class="block">
        <div class="block-head"><x-icon name="tag" :size="16" /><h2>{{ __('Coupon codes') }}</h2></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('Code') }}</th><th>{{ __('Discount (%)') }}</th><th>{{ __('or a fixed amount') }}</th><th>{{ __('Uses allowed') }}</th><th>{{ __('Used') }}</th><th>{{ __('Valid until') }}</th><th></th><th></th></tr></thead>
                <tbody>
                    @foreach ($coupons->concat([null]) as $coupon)
                        @php $formId = 'coupon-'.($coupon?->id ?? 'new'); @endphp
                        <tr>
                            <td>
                                <form id="{{ $formId }}" method="post" action="{{ route('admin.coupons.save') }}">@csrf
                                    @if ($coupon)<input type="hidden" name="id" value="{{ $coupon->id }}">@endif
                                </form>
                                <input class="input" form="{{ $formId }}" name="code" value="{{ $coupon?->code }}" maxlength="30" placeholder="{{ __('NEWCODE') }}" style="text-transform: uppercase" required>
                            </td>
                            <td><input class="input" style="width: 90px" form="{{ $formId }}" type="number" name="percent" value="{{ $coupon?->percent }}" min="1" max="100"></td>
                            <td><input class="input" style="width: 110px" form="{{ $formId }}" type="number" name="amount" value="{{ $coupon?->amount }}" min="0.01" step="0.01"></td>
                            <td><input class="input" style="width: 100px" form="{{ $formId }}" type="number" name="max_uses" value="{{ $coupon?->max_uses }}" min="1" placeholder="∞"></td>
                            <td class="muted">{{ $coupon ? forum_number($coupon->used_count) : '' }}</td>
                            <td><input class="input" style="width: auto" form="{{ $formId }}" type="date" name="expires_at" value="{{ $coupon?->expires_at?->format('Y-m-d') }}"></td>
                            <td><label class="check"><input type="checkbox" form="{{ $formId }}" name="is_active" value="1" @checked($coupon?->is_active ?? true)> {{ __('In use') }}</label></td>
                            <td class="actions">
                                <button class="btn btn-sm btn-primary" form="{{ $formId }}" type="submit">{{ $coupon ? __('Save') : __('Add') }}</button>
                                @if ($coupon)
                                    <form style="display: inline" method="post" action="{{ route('admin.coupons.delete', $coupon) }}" data-confirm="{{ __('Delete this coupon?') }}">@csrf @method('DELETE')
                                        <button class="btn btn-sm btn-danger" type="submit"><x-icon name="trash" :size="14" /></button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @foreach (['code', 'percent', 'amount', 'max_uses', 'expires_at'] as $name)
            @error($name)<div class="block-foot"><span class="field"><span class="error">{{ $message }}</span></span></div>@enderror
        @endforeach
        <div class="block-foot muted small">{{ __('Fill in either a percentage or a fixed amount. A coupon counts as used when the order has been paid.') }}</div>
    </section>
@endsection
