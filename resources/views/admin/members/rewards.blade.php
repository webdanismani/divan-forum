@extends('layouts.admin')

@section('title', __('Badges and promotions'))
@section('subtitle', __('Badges members earn, and groups they are added to automatically.'))

@section('content')
    @php $criteria = ['manual' => __('Given by hand'), 'posts' => __('Posts'), 'reactions' => __('Reaction score'), 'solutions' => __('Solutions'), 'days' => __('Days as a member')]; @endphp

    <section class="block">
        <div class="block-head"><h2>{{ __('Badges') }}</h2></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('Icon') }}</th><th>{{ __('Title') }}</th><th>{{ __('Description') }}</th><th>{{ __('Earned by') }}</th><th>{{ __('At least') }}</th><th>{{ __('Holders') }}</th><th></th></tr></thead>
                <tbody>
                    @foreach ($badges->concat([null]) as $badge)
                        @php $formId = 'badge-'.($badge?->id ?? 'new'); @endphp
                        <tr>
                            <td>
                                <form id="{{ $formId }}" method="post" action="{{ route('admin.rewards.badges.save') }}">@csrf
                                    @if ($badge)<input type="hidden" name="id" value="{{ $badge->id }}">@endif
                                </form>
                                <input class="input" style="width: 64px; text-align: center" form="{{ $formId }}" name="icon" value="{{ $badge?->icon ?? '⭐' }}" maxlength="16" required>
                            </td>
                            <td><input class="input" form="{{ $formId }}" name="title" value="{{ $badge?->title }}" maxlength="60" placeholder="{{ __('New badge…') }}" required></td>
                            <td><input class="input" form="{{ $formId }}" name="description" value="{{ $badge?->description }}" maxlength="255"></td>
                            <td>
                                <select class="input" form="{{ $formId }}" name="criteria">
                                    @foreach ($criteria as $key => $label)
                                        <option value="{{ $key }}" @selected(($badge?->criteria ?? 'manual') === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td><input class="input" style="width: 100px" form="{{ $formId }}" type="number" name="threshold" value="{{ $badge?->threshold ?? 0 }}" min="0"></td>
                            <td class="nowrap">
                                {{ $badge ? forum_number($holders[$badge->id] ?? 0) : '' }}
                                <label class="check"><input type="checkbox" form="{{ $formId }}" name="is_active" value="1" @checked($badge?->is_active ?? true)> {{ __('In use') }}</label>
                            </td>
                            <td class="actions">
                                <button class="btn btn-sm btn-primary" form="{{ $formId }}" type="submit">{{ $badge ? __('Save') : __('Add') }}</button>
                                @if ($badge)
                                    <form style="display: inline" method="post" action="{{ route('admin.rewards.badges.delete', $badge) }}" data-confirm="{{ __('Delete this badge? Members lose it.') }}">@csrf @method('DELETE')
                                        <button class="btn btn-sm btn-danger" type="submit"><x-icon name="trash" :size="14" /></button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="block-foot muted small">{{ __('Automatic badges are handed out by a background task about every half hour. Badges given by hand are managed on the member page.') }}</div>
    </section>

    <section class="block">
        <div class="block-head"><h2>{{ __('Automatic group promotions') }}</h2></div>
        @if ($groups->isEmpty())
            <div class="empty">{{ __('Create a user group first: members can only be promoted to groups you have made yourself.') }} <a href="{{ route('admin.groups.create') }}">{{ __('Add group') }}</a></div>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>{{ __('Title') }}</th><th>{{ __('User group') }}</th><th>{{ __('Posts') }}</th><th>{{ __('Days as a member') }}</th><th>{{ __('Reaction score') }}</th><th></th><th></th></tr></thead>
                    <tbody>
                        @foreach ($promotions->concat([null]) as $promotion)
                            @php $formId = 'promotion-'.($promotion?->id ?? 'new'); @endphp
                            <tr>
                                <td>
                                    <form id="{{ $formId }}" method="post" action="{{ route('admin.rewards.promotions.save') }}">@csrf
                                        @if ($promotion)<input type="hidden" name="id" value="{{ $promotion->id }}">@endif
                                    </form>
                                    <input class="input" form="{{ $formId }}" name="title" value="{{ $promotion?->title }}" maxlength="80" placeholder="{{ __('New promotion…') }}" required>
                                </td>
                                <td>
                                    <select class="input" form="{{ $formId }}" name="user_group_id">
                                        @foreach ($groups as $group)
                                            <option value="{{ $group->id }}" @selected($promotion?->user_group_id === $group->id)>{{ $group->name }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td><input class="input" style="width: 100px" form="{{ $formId }}" type="number" name="min_posts" value="{{ $promotion?->min_posts ?? 0 }}" min="0"></td>
                                <td><input class="input" style="width: 100px" form="{{ $formId }}" type="number" name="min_days" value="{{ $promotion?->min_days ?? 0 }}" min="0"></td>
                                <td><input class="input" style="width: 100px" form="{{ $formId }}" type="number" name="min_reaction_score" value="{{ $promotion?->min_reaction_score ?? 0 }}" min="0"></td>
                                <td><label class="check"><input type="checkbox" form="{{ $formId }}" name="is_active" value="1" @checked($promotion?->is_active ?? true)> {{ __('In use') }}</label></td>
                                <td class="actions">
                                    <button class="btn btn-sm btn-primary" form="{{ $formId }}" type="submit">{{ $promotion ? __('Save') : __('Add') }}</button>
                                    @if ($promotion)
                                        <form style="display: inline" method="post" action="{{ route('admin.rewards.promotions.delete', $promotion) }}" data-confirm="{{ __('Delete this promotion? Members keep the group.') }}">@csrf @method('DELETE')
                                            <button class="btn btn-sm btn-danger" type="submit"><x-icon name="trash" :size="14" /></button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="block-foot muted small">{{ __('A member who meets every condition is added to the group as a secondary group. Nobody is removed automatically.') }}</div>
        @endif
    </section>
@endsection
