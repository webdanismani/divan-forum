@extends('layouts.admin')

@section('title', $group->exists ? __('Edit group') : __('Add group'))
@section('subtitle', $group->exists ? $group->name : '')

@section('content')
    <form method="post" action="{{ $group->exists ? route('admin.groups.update', $group) : route('admin.groups.store') }}">
        @csrf
        @if ($group->exists) @method('PUT') @endif

        <section class="block">
            <div class="block-head"><h2>{{ __('Group details') }}</h2></div>
            <div class="block-body">
                <div class="form-grid">
                    <div class="field">
                        <label for="name">{{ __('Name') }}</label>
                        <input class="input" id="name" name="name" value="{{ old('name', $group->name) }}" maxlength="60" required>
                    </div>
                    <div class="field">
                        <label for="badge">{{ __('Badge text') }}</label>
                        <input class="input" id="badge" name="badge" value="{{ old('badge', $group->badge) }}" maxlength="40">
                        <div class="hint">{{ __('Shown next to posts and on profiles. Leave empty for no badge.') }}</div>
                    </div>
                    <div class="field">
                        <label for="color">{{ __('Username colour') }}</label>
                        <input class="input" id="color" name="color" value="{{ old('color', $group->color) }}" placeholder="#3355d8" pattern="#[0-9a-fA-F]{6}">
                    </div>
                    <div class="field">
                        <label for="priority">{{ __('Priority') }}</label>
                        <input class="input" id="priority" type="number" name="priority" value="{{ old('priority', $group->priority ?? 0) }}" min="0">
                    </div>
                    <div class="field">
                        <label for="conversation_quota">{{ __('Inbox quota (conversations)') }}</label>
                        <input class="input" id="conversation_quota" type="number" name="conversation_quota" value="{{ old('conversation_quota', $group->conversation_quota) }}" min="0" max="100000" placeholder="{{ __('General setting: :count', ['count' => (int) setting('conversations.quota') ?: __('no limit')]) }}">
                        <div class="hint">{{ __('How many private conversations members of this group can keep. Empty uses the general setting, 0 means no limit. With several groups the most generous one counts.') }}</div>
                        @error('conversation_quota')<div class="error">{{ $message }}</div>@enderror
                    </div>
                </div>
                <label class="check"><input type="checkbox" name="is_staff" value="1" @checked(old('is_staff', $group->is_staff))> {{ __('Staff group (exempt from flood limits, badge highlighted)') }}</label>
            </div>
        </section>

        @php
            $bannerStyle = old('banner_style', $group->bannerStyle());
            $bannerIcon = old('banner_icon', $group->banner_icon);
            $sample = clone $group;
            $sample->forceFill(['badge' => old('badge', $group->badge) ?: (old('name', $group->name) ?: __('Group')), 'color' => old('color', $group->color), 'banner_color2' => old('banner_color2', $group->banner_color2), 'banner_icon' => $bannerIcon]);
        @endphp
        <section class="block" data-banner-form>
            <div class="block-head"><h2>{{ __('Banner design') }}</h2></div>
            <div class="block-body stack">
                <p class="muted" style="margin: 0">{{ __('How the badge of this group looks next to posts, on profiles and on the member card. It is shown when the badge text above is filled in.') }}</p>
                <div class="banner-picker">
                    @foreach (\App\Models\UserGroup::BANNERS as $key => $label)
                        @php $sample->banner_style = $key; @endphp
                        <label>
                            <span class="name"><input type="radio" name="banner_style" value="{{ $key }}" @checked($bannerStyle === $key)> {{ __($label) }}</span>
                            <x-group-banner :group="$sample" block data-banner-sample />
                        </label>
                    @endforeach
                </div>
                <div class="form-grid">
                    <div class="field">
                        <label>{{ __('Icon') }}</label>
                        <div class="icon-picker">
                            <label title="{{ __('No icon') }}"><input type="radio" name="banner_icon" value="" @checked(! $bannerIcon)>—</label>
                            @foreach (\App\Models\UserGroup::BANNER_ICONS as $icon)
                                <label title="{{ $icon }}"><input type="radio" name="banner_icon" value="{{ $icon }}" @checked($bannerIcon === $icon)><x-icon :name="$icon" :size="17" /></label>
                            @endforeach
                        </div>
                        @error('banner_icon')<div class="error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="banner_color2">{{ __('Second colour') }}</label>
                        <input class="input" id="banner_color2" name="banner_color2" value="{{ old('banner_color2', $group->banner_color2) }}" placeholder="#7c3aed" pattern="#[0-9a-fA-F]{6}">
                        <div class="hint">{{ __('The colour the banner fades into. Empty: a darker tone of the group colour. The first colour is the username colour above.') }}</div>
                        @error('banner_color2')<div class="error">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>
        </section>

        <section class="block">
            <div class="block-head"><h2>{{ __('Permissions') }}</h2></div>
            @if ($group->system_key === 'admin')
                <div class="block-body muted">{{ __('Administrators always have every permission.') }}</div>
            @else
                <div class="table-wrap">
                    <table class="table perm-table">
                        <tbody>
                            @foreach (config('forum.permissions') as $section => $permissions)
                                <tr class="group-row"><td colspan="2">{{ __($section) }}</td></tr>
                                @foreach ($permissions as $key => $permission)
                                    <tr>
                                        <td>{{ __($permission['label']) }}</td>
                                        <td>
                                            @include('admin.partials.permission-select', [
                                                'name' => "perms[{$key}]",
                                                'value' => old("perms.$key", $group->permissions[$key] ?? null),
                                                'unsetLabel' => __('Not set (no)'),
                                            ])
                                        </td>
                                    </tr>
                                @endforeach
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            <div class="block-foot form-actions">
                <button class="btn btn-primary" type="submit">{{ __('Save') }}</button>
                <a class="btn" href="{{ route('admin.groups.index') }}">{{ __('Cancel') }}</a>
            </div>
        </section>
    </form>

    @if ($group->exists && ! $group->isSystem())
        <form style="margin-top: 16px" method="post" action="{{ route('admin.groups.destroy', $group) }}" data-confirm="{{ __('Delete this user group?') }}">
            @csrf @method('DELETE')
            <button class="btn btn-danger" type="submit"><x-icon name="trash" :size="16" /> {{ __('Delete group') }}</button>
        </form>
    @endif
@endsection
