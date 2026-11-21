@extends('layouts.admin')

@section('title', __('Profile fields and titles'))
@section('subtitle', __('Extra questions on member profiles, and the titles members earn as they post.'))

@section('content')
    <section class="block">
        <div class="block-head"><h2>{{ __('Profile fields') }}</h2></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('Label') }}</th><th>{{ __('Type') }}</th><th>{{ __('Choices (comma separated)') }}</th><th>{{ __('Options') }}</th><th>{{ __('Order') }}</th><th></th></tr></thead>
                <tbody>
                    @foreach ($fields->concat([null]) as $field)
                        @php $formId = 'field-'.($field?->id ?? 'new'); @endphp
                        <tr>
                            <td>
                                <form id="{{ $formId }}" method="post" action="{{ route('admin.profile.fields.save') }}">@csrf
                                    @if ($field)<input type="hidden" name="id" value="{{ $field->id }}">@endif
                                </form>
                                <input class="input" form="{{ $formId }}" name="label" value="{{ $field?->label }}" maxlength="80" placeholder="{{ __('New field…') }}" required>
                            </td>
                            <td>
                                <select class="input" form="{{ $formId }}" name="type">
                                    @foreach (['text' => __('Text'), 'url' => __('Link'), 'select' => __('Choice')] as $key => $label)
                                        <option value="{{ $key }}" @selected($field?->type === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td><input class="input" form="{{ $formId }}" name="options" value="{{ implode(', ', $field?->options ?? []) }}" maxlength="1000"></td>
                            <td>
                                <label class="check"><input type="checkbox" form="{{ $formId }}" name="is_active" value="1" @checked($field?->is_active ?? true)> {{ __('In use') }}</label>
                                <label class="check"><input type="checkbox" form="{{ $formId }}" name="is_required" value="1" @checked($field?->is_required)> {{ __('Required') }}</label>
                                <label class="check"><input type="checkbox" form="{{ $formId }}" name="show_on_posts" value="1" @checked($field?->show_on_posts)> {{ __('Show next to posts') }}</label>
                            </td>
                            <td><input class="input" style="width: 80px" form="{{ $formId }}" type="number" name="display_order" value="{{ $field?->display_order ?? 0 }}" min="0"></td>
                            <td class="actions">
                                <button class="btn btn-sm btn-primary" form="{{ $formId }}" type="submit">{{ $field ? __('Save') : __('Add') }}</button>
                                @if ($field)
                                    <form style="display: inline" method="post" action="{{ route('admin.profile.fields.delete', $field) }}" data-confirm="{{ __('Delete this field and what members entered in it?') }}">@csrf @method('DELETE')
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

    <section class="block">
        <div class="block-head"><h2>{{ __('Titles by post count') }}</h2></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('From this many posts') }}</th><th>{{ __('Title') }}</th><th>{{ __('Colour') }}</th><th>{{ __('Icon') }}</th><th>{{ __('Looks like') }}</th><th></th></tr></thead>
                <tbody>
                    @foreach ($titles->concat([null]) as $title)
                        @php $formId = 'title-'.($title?->id ?? 'new'); @endphp
                        <tr>
                            <td>
                                <form id="{{ $formId }}" method="post" action="{{ route('admin.profile.titles.save') }}">@csrf
                                    @if ($title)<input type="hidden" name="id" value="{{ $title->id }}">@endif
                                </form>
                                <input class="input" style="width: 130px" form="{{ $formId }}" type="number" name="min_posts" value="{{ $title?->min_posts }}" min="0" required>
                            </td>
                            <td><input class="input" form="{{ $formId }}" name="title" value="{{ $title?->title }}" maxlength="60" placeholder="{{ __('New title…') }}" required></td>
                            <td><input class="input" style="width: 110px" form="{{ $formId }}" name="color" value="{{ $title?->color }}" placeholder="#3b82c4" pattern="#[0-9a-fA-F]{6}"></td>
                            <td>
                                <select class="input" style="width: 130px" form="{{ $formId }}" name="icon">
                                    <option value="">{{ __('(none)') }}</option>
                                    @foreach (\App\Models\UserGroup::BANNER_ICONS as $icon)<option value="{{ $icon }}" @selected($title?->icon === $icon)>{{ $icon }}</option>@endforeach
                                </select>
                            </td>
                            <td>@if ($title)<x-rank :posts="(int) $title->min_posts" inline />@endif</td>
                            <td class="actions">
                                <button class="btn btn-sm btn-primary" form="{{ $formId }}" type="submit">{{ $title ? __('Save') : __('Add') }}</button>
                                @if ($title)
                                    <form style="display: inline" method="post" action="{{ route('admin.profile.titles.delete', $title) }}" data-confirm="{{ __('Delete this title?') }}">@csrf @method('DELETE')
                                        <button class="btn btn-sm btn-danger" type="submit"><x-icon name="trash" :size="14" /></button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="block-foot muted small">{{ __('A custom title or a staff badge always wins over these.') }} {{ __('Without a colour of its own a step takes one of the standard colours, by its place on the ladder.') }}</div>
    </section>
@endsection
