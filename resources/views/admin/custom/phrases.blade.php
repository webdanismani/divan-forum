@extends('layouts.admin')

@section('title', __('Texts'))
@section('subtitle', __('Change any text of the interface without touching the language files. :count texts are changed.', ['count' => $changedCount]))

@section('content')
    <form class="block" method="get" action="{{ route('admin.phrases') }}">
        <div class="block-body row">
            <select class="input" name="locale" style="width: auto">
                <option value="tr" @selected($locale === 'tr')>Türkçe</option>
                <option value="en" @selected($locale === 'en')>English</option>
            </select>
            <input class="input" style="flex: 1; min-width: 200px" type="search" name="q" value="{{ $search }}" placeholder="{{ __('Search in the texts…') }}">
            <label class="check"><input type="checkbox" name="changed" value="1" @checked($onlyChanged)> {{ __('Changed only') }}</label>
            <button class="btn btn-primary" type="submit"><x-icon name="search" :size="16" /> {{ __('Search') }}</button>
        </div>
    </form>

    <section class="block">
        <div class="block-head"><h2>{{ trans_choice(':count result|:count results', $total, ['count' => forum_number($total)]) }}</h2></div>
        @forelse ($rows as $row)
            <form class="item" method="post" action="{{ route('admin.phrases.save') }}" style="align-items: flex-end">
                @csrf
                <input type="hidden" name="locale" value="{{ $locale }}">
                <input type="hidden" name="key" value="{{ $row['key'] }}">
                <div class="body">
                    <div class="muted small" style="margin-bottom: 4px; overflow-wrap: anywhere">{{ $row['default'] }}@if ($row['value'] !== null) <span class="badge amber">{{ __('Changed') }}</span>@endif</div>
                    <textarea class="input" name="value" rows="1" style="min-height: 36px" maxlength="5000" placeholder="{{ $row['default'] }}">{{ $row['value'] }}</textarea>
                </div>
                <button class="btn btn-sm btn-primary" type="submit">{{ __('Save') }}</button>
                @if ($row['value'] !== null)<button class="btn btn-sm" type="submit" name="reset" value="1" title="{{ __('Use the original text again') }}">{{ __('Reset') }}</button>@endif
            </form>
        @empty
            <div class="empty">{{ __('No results found.') }}</div>
        @endforelse
    </section>

    @if ($pages > 1)
        <nav class="pagination">
            @for ($i = max(1, $page - 4); $i <= min($pages, $page + 4); $i++)
                @if ($i === $page)<span class="current">{{ $i }}</span>
                @else<a href="{{ route('admin.phrases', array_filter(['locale' => $locale, 'q' => $search, 'changed' => $onlyChanged ? 1 : null, 'page' => $i])) }}">{{ $i }}</a>@endif
            @endfor
        </nav>
    @endif

    <p class="muted small">{{ __('Words that start with a colon, such as :name, are filled in by the forum: keep them as they are.') }}</p>
@endsection
