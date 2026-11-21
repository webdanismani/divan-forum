@extends('layouts.admin')

@section('title', __('Notices and menu links'))
@section('subtitle', __('Notice bars shown at the top of every page, and extra links for the menu and the footer.'))

@section('content')
    @php
        $styles = ['info' => __('Information'), 'success' => __('Good news'), 'warning' => __('Warning')];
        $audiences = ['all' => __('Everybody'), 'guests' => __('Guests only'), 'members' => __('Members only')];
    @endphp

    @foreach ($notices->concat([null]) as $notice)
        <form class="block" method="post" action="{{ route('admin.notices.save') }}">
            @csrf
            @if ($notice)<input type="hidden" name="id" value="{{ $notice->id }}">@endif
            <div class="block-head"><x-icon name="bell" :size="16" /><h2>{{ $notice ? ($notice->title ?: __('Notice').' #'.$notice->id) : __('New notice') }}</h2></div>
            <div class="block-body">
                <div class="form-grid" style="grid-template-columns: 2fr 1fr 1fr">
                    <div class="field">
                        <label>{{ __('Title') }} <span class="muted">({{ __('optional') }})</span></label>
                        <input class="input" name="title" value="{{ $notice?->title }}" maxlength="150">
                    </div>
                    <div class="field">
                        <label>{{ __('Style') }}</label>
                        <select class="input" name="style">@foreach ($styles as $key => $label)<option value="{{ $key }}" @selected($notice?->style === $key)>{{ $label }}</option>@endforeach</select>
                    </div>
                    <div class="field">
                        <label>{{ __('Shown to') }}</label>
                        <select class="input" name="audience">@foreach ($audiences as $key => $label)<option value="{{ $key }}" @selected($notice?->audience === $key)>{{ $label }}</option>@endforeach</select>
                    </div>
                </div>
                <div class="field">
                    <label>{{ __('Text') }} <span class="muted">(BBCode)</span></label>
                    <textarea class="input" name="body" maxlength="2000" style="min-height: 70px" required>{{ $notice?->body }}</textarea>
                </div>
                <div class="row">
                    <label class="muted">{{ __('From') }} <input class="input" style="width: auto; display: inline-block" type="datetime-local" name="starts_at" value="{{ $notice?->starts_at?->format('Y-m-d\TH:i') }}"></label>
                    <label class="muted">{{ __('Until') }} <input class="input" style="width: auto; display: inline-block" type="datetime-local" name="ends_at" value="{{ $notice?->ends_at?->format('Y-m-d\TH:i') }}"></label>
                    <label class="check"><input type="checkbox" name="is_dismissible" value="1" @checked($notice?->is_dismissible ?? true)> {{ __('Visitors can hide it') }}</label>
                    <label class="check"><input type="checkbox" name="is_active" value="1" @checked($notice?->is_active ?? true)> {{ __('In use') }}</label>
                    <span class="spacer"></span>
                    <label class="muted">{{ __('Order') }} <input class="input" style="width: 80px; display: inline-block" type="number" name="display_order" value="{{ $notice?->display_order ?? 0 }}" min="0"></label>
                    <button class="btn btn-primary" type="submit">{{ $notice ? __('Save') : __('Add') }}</button>
                    @if ($notice)<button class="btn btn-danger" type="submit" form="delete-notice-{{ $notice->id }}"><x-icon name="trash" :size="15" /></button>@endif
                </div>
            </div>
        </form>
        @if ($notice)
            <form id="delete-notice-{{ $notice->id }}" method="post" action="{{ route('admin.notices.delete', $notice) }}" data-confirm="{{ __('Delete this notice?') }}">@csrf @method('DELETE')</form>
        @endif
    @endforeach

    <section class="block">
        <div class="block-head"><x-icon name="link" :size="16" /><h2>{{ __('Extra links') }}</h2></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('Title') }}</th><th>{{ __('Address') }}</th><th>{{ __('Place') }}</th><th>{{ __('Options') }}</th><th>{{ __('Order') }}</th><th></th></tr></thead>
                <tbody>
                    @foreach ($links->concat([null]) as $link)
                        @php $formId = 'link-'.($link?->id ?? 'new'); @endphp
                        <tr>
                            <td>
                                <form id="{{ $formId }}" method="post" action="{{ route('admin.links.save') }}">@csrf
                                    @if ($link)<input type="hidden" name="id" value="{{ $link->id }}">@endif
                                </form>
                                <input class="input" form="{{ $formId }}" name="title" value="{{ $link?->title }}" maxlength="60" placeholder="{{ __('New link…') }}" required>
                            </td>
                            <td><input class="input" form="{{ $formId }}" name="url" value="{{ $link?->url }}" maxlength="255" placeholder="/pages/kurallar · https://…" required></td>
                            <td>
                                <select class="input" form="{{ $formId }}" name="position">
                                    <option value="header" @selected($link?->position === 'header')>{{ __('Menu') }}</option>
                                    <option value="footer" @selected($link?->position === 'footer')>{{ __('Footer') }}</option>
                                </select>
                            </td>
                            <td>
                                <label class="check"><input type="checkbox" form="{{ $formId }}" name="is_active" value="1" @checked($link?->is_active ?? true)> {{ __('In use') }}</label>
                                <label class="check"><input type="checkbox" form="{{ $formId }}" name="new_tab" value="1" @checked($link?->new_tab)> {{ __('New tab') }}</label>
                            </td>
                            <td><input class="input" style="width: 80px" form="{{ $formId }}" type="number" name="display_order" value="{{ $link?->display_order ?? 0 }}" min="0"></td>
                            <td class="actions">
                                <button class="btn btn-sm btn-primary" form="{{ $formId }}" type="submit">{{ $link ? __('Save') : __('Add') }}</button>
                                @if ($link)
                                    <form style="display: inline" method="post" action="{{ route('admin.links.delete', $link) }}" data-confirm="{{ __('Delete this link?') }}">@csrf @method('DELETE')
                                        <button class="btn btn-sm btn-danger" type="submit"><x-icon name="trash" :size="14" /></button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @error('url')<div class="block-foot"><span class="field"><span class="error">{{ $message }}</span></span></div>@enderror
    </section>
@endsection
