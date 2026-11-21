@extends('layouts.admin')

@section('title', __('Settings'))

@section('subtitle', __('Every option of the forum, section by section. The list on the left jumps to a section.'))

@section('content')
    <div class="acp-split">
    <nav class="acp-sections" data-acp-sections aria-label="{{ __('Sections') }}">
        @foreach ($groups as $groupLabel => $definitions)
            <a href="#s-{{ \Illuminate\Support\Str::slug($groupLabel) }}" @class(['active' => $loop->first])>{{ __($groupLabel) }}</a>
        @endforeach
    </nav>
    <form method="post" action="{{ route('admin.settings.update') }}">
        @csrf @method('PUT')

        @foreach ($groups as $groupLabel => $definitions)
            <section class="block" id="s-{{ \Illuminate\Support\Str::slug($groupLabel) }}">
                <div class="block-head"><h2>{{ __($groupLabel) }}</h2></div>
                <div class="block-body">
                    @foreach ($definitions as $key => $def)
                        @php
                            $field = str_replace('.', '__', $key);
                            $value = old($field, $values[$key] ?? $def['default']);
                        @endphp
                        <div class="field">
                            @if ($def['type'] === 'bool')
                                <label class="check"><input type="checkbox" name="{{ $field }}" value="1" @checked($value)> {{ __($def['label']) }}</label>
                            @else
                                <label for="{{ $field }}">{{ __($def['label']) }}</label>
                                @if ($def['type'] === 'text')
                                    <textarea class="input" id="{{ $field }}" name="{{ $field }}" style="min-height: 80px">{{ $value }}</textarea>
                                @elseif ($def['type'] === 'select')
                                    <select class="input" id="{{ $field }}" name="{{ $field }}">
                                        @foreach ($def['options'] as $optionKey => $optionLabel)
                                            <option value="{{ $optionKey }}" @selected($value === $optionKey)>{{ __($optionLabel) }}</option>
                                        @endforeach
                                    </select>
                                @elseif ($def['type'] === 'secret')
                                    <input class="input" id="{{ $field }}" type="password" name="{{ $field }}" value="" maxlength="255" autocomplete="new-password" placeholder="{{ filled($values[$key] ?? '') ? __('Saved — leave empty to keep it') : '' }}">
                                @elseif ($def['type'] === 'int')
                                    <input class="input" style="max-width: 180px" id="{{ $field }}" type="number" name="{{ $field }}" value="{{ $value }}" min="{{ $def['min'] ?? 0 }}" max="{{ $def['max'] ?? '' }}" required>
                                @else
                                    <input class="input" id="{{ $field }}" name="{{ $field }}" value="{{ $value }}" maxlength="255">
                                @endif
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach

        <div class="acp-save">
            <button class="btn btn-primary" type="submit">{{ __('Save settings') }}</button>
            <button class="btn" type="submit" form="test-mail">{{ __('Send a test e-mail') }}</button>
        </div>
    </form>
    </div>
    <form id="test-mail" method="post" action="{{ route('admin.settings.test-mail') }}">@csrf</form>
@endsection
