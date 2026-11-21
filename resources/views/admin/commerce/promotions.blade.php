@extends('layouts.admin')

@section('title', __('Thread promotion'))
@section('subtitle', __('What members can buy for their own threads, paid from their balance. Total sold: :amount', ['amount' => forum_money($income)]))

@section('content')
    @php $types = \App\Models\ThreadPromotion::TYPES; @endphp

    <section class="block">
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('Type') }}</th><th>{{ __('Period (days)') }}</th><th>{{ __('Price') }} ({{ config('forum.ads.currency_symbol') }})</th><th>{{ __('On sale') }}</th><th></th></tr></thead>
                <tbody>
                    @foreach ($options->concat([null]) as $option)
                        @php $formId = 'promo-'.($option?->id ?? 'new'); @endphp
                        <tr>
                            <td>
                                <form id="{{ $formId }}" method="post" action="{{ route('admin.promotions.save') }}">@csrf
                                    @if ($option)<input type="hidden" name="id" value="{{ $option->id }}">@endif
                                </form>
                                <select class="input" form="{{ $formId }}" name="type">
                                    @foreach ($types as $key => $label)
                                        <option value="{{ $key }}" @selected($option?->type === $key)>{{ __($label) }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td><input class="input" style="width: 110px" form="{{ $formId }}" type="number" name="days" value="{{ $option?->days ?? 7 }}" min="0" max="365"></td>
                            <td><input class="input" style="width: 130px" form="{{ $formId }}" type="number" step="0.01" name="price" value="{{ $option?->price }}" min="0" required></td>
                            <td><label class="check"><input type="checkbox" form="{{ $formId }}" name="is_active" value="1" @checked($option?->is_active ?? true)> {{ __('On sale') }}</label></td>
                            <td class="actions">
                                <button class="btn btn-sm btn-primary" form="{{ $formId }}" type="submit">{{ $option ? __('Save') : __('Add') }}</button>
                                @if ($option)
                                    <form style="display: inline" method="post" action="{{ route('admin.promotions.delete', $option) }}" data-confirm="{{ __('Delete this option?') }}">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-sm btn-danger" type="submit"><x-icon name="trash" :size="14" /></button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="block-foot muted small">{{ __('The period is ignored for "Move to the top once".') }}</div>
    </section>

    <section class="block">
        <div class="block-head"><h2>{{ __('Recent purchases') }}</h2></div>
        @if ($recent->isEmpty())
            <div class="empty">{{ __('Nothing to show yet.') }}</div>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Member') }}</th><th>{{ __('Thread') }}</th><th>{{ __('Type') }}</th><th>{{ __('Price') }}</th></tr></thead>
                    <tbody>
                        @foreach ($recent as $row)
                            <tr>
                                <td class="nowrap">{{ $row->created_at->translatedFormat('j M Y H:i') }}</td>
                                <td><x-username :user="$row->user" /></td>
                                <td>@if ($row->thread)<a href="{{ route('threads.show', $row->thread) }}">{{ $row->thread->title }}</a>@else — @endif</td>
                                <td>{{ __($types[$row->type] ?? $row->type) }}@if ($row->days) · {{ __(':days days', ['days' => $row->days]) }}@endif</td>
                                <td class="nowrap">{{ forum_money($row->price) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
