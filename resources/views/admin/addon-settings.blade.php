@extends('layouts.admin')

@section('title', __('Settings').': '.__($addon['title']))
@section('subtitle', $id)

@section('content')
    <form class="block medium" method="post" action="{{ route('admin.addons.settings.save') }}">
        @csrf
        <input type="hidden" name="id" value="{{ $id }}">
        <div class="block-head"><x-icon name="sliders" :size="16" /><h2>{{ __($addon['title']) }}</h2></div>
        <div class="block-body">
            @foreach ($definitions as $name => $def)
                @php $value = old('s.'.$name, $values[$name] ?? ''); $field = 's['.$name.']'; @endphp
                <div class="field">
                    @if ($def['type'] === 'bool')
                        <label class="check"><input type="checkbox" name="{{ $field }}" value="1" @checked($value)> {{ __($def['label']) }}</label>
                    @else
                        <label for="s-{{ $name }}">{{ __($def['label']) }}</label>
                        @if ($def['type'] === 'text')
                            <textarea class="input" id="s-{{ $name }}" name="{{ $field }}" style="min-height: 110px; font-family: ui-monospace, Consolas, monospace; font-size: 12.5px">{{ $value }}</textarea>
                        @elseif ($def['type'] === 'select')
                            <select class="input" id="s-{{ $name }}" name="{{ $field }}">
                                @foreach ($def['options'] ?? [] as $key => $label)<option value="{{ $key }}" @selected((string) $value === (string) $key)>{{ __($label) }}</option>@endforeach
                            </select>
                        @elseif ($def['type'] === 'secret')
                            <input class="input" id="s-{{ $name }}" type="password" name="{{ $field }}" autocomplete="off" placeholder="{{ filled($values[$name] ?? '') ? __('(saved; leave empty to keep)') : '' }}">
                        @elseif ($def['type'] === 'int')
                            <input class="input" id="s-{{ $name }}" type="number" name="{{ $field }}" value="{{ $value }}" min="{{ $def['min'] ?? 0 }}" @isset($def['max']) max="{{ $def['max'] }}" @endisset style="max-width: 160px">
                        @else
                            <input class="input" id="s-{{ $name }}" name="{{ $field }}" value="{{ $value }}" maxlength="500">
                        @endif
                    @endif
                    @if (! empty($def['hint']))<div class="hint">{{ __($def['hint']) }}</div>@endif
                    @error('s.'.$name)<div class="error">{{ $message }}</div>@enderror
                </div>
            @endforeach
            <div class="form-actions">
                <button class="btn btn-primary" type="submit">{{ __('Save') }}</button>
                <a class="btn" href="{{ route('admin.addons') }}">{{ __('Back') }}</a>
            </div>
        </div>
    </form>
@endsection
