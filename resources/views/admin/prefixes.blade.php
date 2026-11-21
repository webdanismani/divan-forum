@extends('layouts.admin')

@section('title', __('Thread prefixes'))
@section('subtitle', __('Short labels members can put in front of a thread title.'))

@section('content')
    <section class="block">
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('Title') }}</th><th>{{ __('Colour') }}</th><th>{{ __('Order') }}</th><th></th></tr></thead>
                <tbody>
                    @foreach ($prefixes->concat([null]) as $prefix)
                        @php $formId = 'prefix-'.($prefix?->id ?? 'new'); @endphp
                        <tr>
                            <td>
                                <form id="{{ $formId }}" method="post" action="{{ route('admin.prefixes.save') }}">@csrf
                                    @if ($prefix)<input type="hidden" name="id" value="{{ $prefix->id }}">@endif
                                </form>
                                <div class="row" style="flex-wrap: nowrap">
                                    @if ($prefix)<span class="prefix {{ $prefix->color }}">{{ $prefix->title }}</span>@endif
                                    <input class="input" form="{{ $formId }}" name="title" value="{{ $prefix?->title }}" maxlength="40" placeholder="{{ __('New prefix…') }}" required>
                                </div>
                            </td>
                            <td>
                                <select class="input" form="{{ $formId }}" name="color">
                                    @foreach ($colors as $color)
                                        <option value="{{ $color }}" @selected($prefix?->color === $color)>{{ __(ucfirst($color)) }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td><input class="input" style="width: 90px" form="{{ $formId }}" type="number" name="display_order" value="{{ $prefix?->display_order ?? 0 }}" min="0"></td>
                            <td class="actions">
                                <button class="btn btn-sm btn-primary" form="{{ $formId }}" type="submit">{{ $prefix ? __('Save') : __('Add') }}</button>
                                @if ($prefix)
                                    <form style="display: inline" method="post" action="{{ route('admin.prefixes.delete', $prefix) }}" data-confirm="{{ __('Delete this prefix?') }}">
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
    </section>
@endsection
