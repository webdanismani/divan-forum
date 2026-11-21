@extends('layouts.admin')

@section('title', __('Reactions'))
@section('subtitle', __('Reactions members can give to posts. The score is added to the reaction score of the author.'))

@section('content')
    <section class="block">
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('Emoji') }}</th><th>{{ __('Name') }}</th><th>{{ __('Score') }}</th><th>{{ __('Order') }}</th><th>{{ __('Active') }}</th><th></th></tr></thead>
                <tbody>
                    @foreach ($reactions->concat([null]) as $reaction)
                        @php $formId = 'reaction-'.($reaction?->id ?? 'new'); @endphp
                        <tr>
                            <td>
                                <form id="{{ $formId }}" method="post" action="{{ route('admin.reactions.save') }}">@csrf
                                    @if ($reaction)<input type="hidden" name="id" value="{{ $reaction->id }}">@endif
                                </form>
                                <input class="input" style="width: 80px; font-size: 18px" form="{{ $formId }}" name="emoji" value="{{ $reaction?->emoji }}" maxlength="16" placeholder="🙂" required>
                            </td>
                            <td><input class="input" form="{{ $formId }}" name="name" value="{{ $reaction?->name }}" maxlength="40" placeholder="{{ __('New reaction…') }}" required></td>
                            <td>
                                <select class="input" form="{{ $formId }}" name="score">
                                    @foreach ([1 => __('Positive (+1)'), 0 => __('Neutral (0)'), -1 => __('Negative (-1)')] as $score => $label)
                                        <option value="{{ $score }}" @selected(($reaction?->score ?? 1) === $score)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td><input class="input" style="width: 90px" form="{{ $formId }}" type="number" name="display_order" value="{{ $reaction?->display_order ?? 0 }}" min="0"></td>
                            <td><input type="checkbox" form="{{ $formId }}" name="is_active" value="1" @checked($reaction?->is_active ?? true)></td>
                            <td class="actions">
                                <button class="btn btn-sm btn-primary" form="{{ $formId }}" type="submit">{{ $reaction ? __('Save') : __('Add') }}</button>
                                @if ($reaction)
                                    <form style="display: inline" method="post" action="{{ route('admin.reactions.delete', $reaction) }}" data-confirm="{{ __('Delete this reaction? Reactions already given with it are removed.') }}">
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
