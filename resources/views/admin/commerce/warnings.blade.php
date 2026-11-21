@extends('layouts.admin')

@section('title', __('Warnings'))
@section('subtitle', __('The warnings moderators can give, and what happens when a member collects too many points.'))

@section('content')
    <section class="block">
        <div class="block-head"><h2>{{ __('Warning types') }}</h2></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('Title') }}</th><th>{{ __('Points') }}</th><th>{{ __('Expires after (days)') }}</th><th>{{ __('Order') }}</th><th></th></tr></thead>
                <tbody>
                    @foreach ($types->concat([null]) as $type)
                        @php $formId = 'wtype-'.($type?->id ?? 'new'); @endphp
                        <tr>
                            <td>
                                <form id="{{ $formId }}" method="post" action="{{ route('admin.warnings.types.save') }}">@csrf
                                    @if ($type)<input type="hidden" name="id" value="{{ $type->id }}">@endif
                                </form>
                                <input class="input" form="{{ $formId }}" name="title" value="{{ $type?->title }}" maxlength="100" placeholder="{{ __('New warning…') }}" required>
                            </td>
                            <td><input class="input" style="width: 90px" form="{{ $formId }}" type="number" name="points" value="{{ $type?->points ?? 1 }}" min="0" max="100" required></td>
                            <td><input class="input" style="width: 110px" form="{{ $formId }}" type="number" name="expiry_days" value="{{ $type?->expiry_days ?? 30 }}" min="0" max="3650" required></td>
                            <td><input class="input" style="width: 90px" form="{{ $formId }}" type="number" name="display_order" value="{{ $type?->display_order ?? 0 }}" min="0"></td>
                            <td class="actions">
                                <button class="btn btn-sm btn-primary" form="{{ $formId }}" type="submit">{{ $type ? __('Save') : __('Add') }}</button>
                                @if ($type)
                                    <form style="display: inline" method="post" action="{{ route('admin.warnings.types.delete', $type) }}" data-confirm="{{ __('Delete this warning type? Warnings already given are kept.') }}">
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
        <div class="block-foot muted small">{{ __('0 days = the points never expire.') }}</div>
    </section>

    <section class="block">
        <div class="block-head"><h2>{{ __('Automatic bans') }}</h2></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('When active points reach') }}</th><th>{{ __('Ban for (days)') }}</th><th></th></tr></thead>
                <tbody>
                    @foreach ($actions->concat([null]) as $action)
                        @php $formId = 'waction-'.($action?->id ?? 'new'); @endphp
                        <tr>
                            <td>
                                <form id="{{ $formId }}" method="post" action="{{ route('admin.warnings.actions.save') }}">@csrf
                                    @if ($action)<input type="hidden" name="id" value="{{ $action->id }}">@endif
                                </form>
                                <input class="input" style="width: 110px" form="{{ $formId }}" type="number" name="points" value="{{ $action?->points }}" min="1" max="1000" required>
                            </td>
                            <td><input class="input" style="width: 110px" form="{{ $formId }}" type="number" name="ban_days" value="{{ $action?->ban_days ?? 7 }}" min="0" max="3650" required></td>
                            <td class="actions">
                                <button class="btn btn-sm btn-primary" form="{{ $formId }}" type="submit">{{ $action ? __('Save') : __('Add') }}</button>
                                @if ($action)
                                    <form style="display: inline" method="post" action="{{ route('admin.warnings.actions.delete', $action) }}" data-confirm="{{ __('Delete this rule?') }}">
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
        <div class="block-foot muted small">{{ __('0 days = permanent ban. The highest rule that applies is used.') }}</div>
    </section>
@endsection
