@extends('layouts.admin')

@section('title', $node->exists ? __('Edit node') : __('Add node'))
@section('subtitle', $node->exists ? $node->title : '')

@section('content')
    <form class="block medium" style="margin-left: 0" method="post" action="{{ $node->exists ? route('admin.nodes.update', $node) : route('admin.nodes.store') }}" enctype="multipart/form-data">
        @csrf
        @if ($node->exists) @method('PUT') @endif
        <div class="block-body">
            <div class="form-grid">
                <div class="field">
                    <label for="title">{{ __('Title') }}</label>
                    <input class="input" id="title" name="title" value="{{ old('title', $node->title) }}" maxlength="120" required>
                </div>
                <div class="field">
                    <label for="type">{{ __('Type') }}</label>
                    <select class="input" id="type" name="type">
                        @foreach (['category' => __('Category'), 'forum' => __('Forum'), 'link' => __('Link')] as $key => $label)
                            <option value="{{ $key }}" @selected(old('type', $node->type) === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="parent_id">{{ __('Parent node') }}</label>
                    <select class="input" id="parent_id" name="parent_id">
                        <option value="">{{ __('(none - top level)') }}</option>
                        @foreach ($parents as $item)
                            <option value="{{ $item['node']->id }}" @selected((int) old('parent_id', $node->parent_id) === $item['node']->id)>{{ str_repeat('— ', $item['depth']) }}{{ $item['node']->title }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="display_order">{{ __('Display order') }}</label>
                    <input class="input" id="display_order" type="number" name="display_order" value="{{ old('display_order', $node->display_order) }}" min="0">
                    <div class="hint">{{ __('Lower numbers are shown first.') }}</div>
                </div>
            </div>
            <div class="field">
                <label for="description">{{ __('Description') }}</label>
                <textarea class="input" id="description" name="description" maxlength="500" style="min-height: 70px">{{ old('description', $node->description) }}</textarea>
            </div>
            <div class="field">
                <label for="icon">{{ __('Own icon') }}</label>
                <div class="row" style="flex-wrap: nowrap; align-items: center; gap: 12px">
                    @if ($node->iconUrl())
                        <span class="node-icon has-image" style="flex: none"><img src="{{ $node->iconUrl() }}" alt=""></span>
                    @endif
                    <input class="input" id="icon" type="file" name="icon" accept="image/png,image/jpeg,image/webp,image/gif">
                </div>
                <div class="hint">{{ __('Optional. Shown instead of the standard icon in the forum list. A square picture works best (for example 96×96); PNG, JPG, WebP or GIF, at most 512 KB.') }}</div>
                @error('icon')<div class="error">{{ $message }}</div>@enderror
                @if ($node->iconUrl())
                    <label class="check" style="margin-top: 6px"><input type="checkbox" name="remove_icon" value="1"> {{ __('Remove the icon and use the standard one') }}</label>
                @endif
            </div>
            <div class="form-grid">
                <div class="field">
                    <label for="glyph">{{ __('Standard icon') }}</label>
                    <select class="input" id="glyph" name="glyph">
                        <option value="">{{ __('Automatic') }}</option>
                        @foreach (\App\Forum\Home::ICONS as $glyph)
                            <option value="{{ $glyph }}" @selected(old('glyph', $node->icon) === $glyph)>{{ $glyph }}</option>
                        @endforeach
                    </select>
                    <div class="hint">{{ __('Used when no picture of your own is uploaded.') }}</div>
                </div>
                <div class="field">
                    <label for="color">{{ __('Icon colour') }}</label>
                    <div class="row" style="flex-wrap: nowrap; align-items: center; gap: 10px">
                        <input type="color" id="color" name="color" value="{{ old('color', $node->color ?: '#1877d4') }}" style="width: 46px; height: 34px; padding: 2px">
                        <label class="check" style="margin: 0"><input type="checkbox" name="color_on" value="1" @checked(old('color_on', (bool) $node->color))> {{ __('Give this forum a colour of its own') }}</label>
                    </div>
                </div>
            </div>
            <div class="field">
                <label for="link_url">{{ __('Link address') }}</label>
                <input class="input" id="link_url" type="url" name="link_url" value="{{ old('link_url', $node->link_url) }}" placeholder="https://">
                <div class="hint">{{ __('Only used for link nodes.') }}</div>
            </div>
            <div class="field">
                <label class="check"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $node->is_active))> {{ __('Active (visible on the board)') }}</label>
            </div>
            <div class="field">
                <label class="check"><input type="checkbox" name="require_approval" value="1" @checked(old('require_approval', $node->require_approval))> {{ __('New threads and replies need moderator approval') }}</label>
            </div>
            <div class="field">
                <label class="check"><input type="checkbox" name="allow_solutions" value="1" @checked(old('allow_solutions', $node->allow_solutions))> {{ __('Question forum: a reply can be marked as the solution') }}</label>
                <label class="check"><input type="checkbox" name="is_marketplace" value="1" @checked(old('is_marketplace', $node->is_marketplace))> {{ __('Marketplace: threads are listings with a type, a price and a state') }}</label>
                <label class="check"><input type="checkbox" name="require_prefix" value="1" @checked(old('require_prefix', $node->require_prefix))> {{ __('A new thread must have a prefix') }}</label>
                <div class="field" style="margin-top: 12px">
                    <label for="thread_template">{{ __('Starting text of a new thread') }}</label>
                    <textarea class="input" id="thread_template" name="thread_template" maxlength="5000" style="min-height: 130px; font-family: var(--mono, monospace)" placeholder="[b]{{ __('Price') }}:[/b]&#10;[b]{{ __('Condition') }}:[/b]&#10;[b]{{ __('Delivery') }}:[/b]">{{ old('thread_template', $node->thread_template) }}</textarea>
                    <div class="hint">{{ __('Optional. The message box of a new thread in this forum starts with this text (BBCode works), so that every thread carries the same details. A thread that leaves it untouched is not accepted.') }}</div>
                    @error('thread_template')<div class="error">{{ $message }}</div>@enderror
                </div>
            </div>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit">{{ __('Save') }}</button>
                <a class="btn" href="{{ route('admin.nodes.index') }}">{{ __('Cancel') }}</a>
            </div>
        </div>
    </form>

    @if ($node->exists)
        <form class="block medium" style="margin: 16px 0 0" method="post" action="{{ route('admin.nodes.destroy', $node) }}"
              data-confirm="{{ __('Delete this node and ALL threads inside it? This cannot be undone.') }}">
            @csrf @method('DELETE')
            <div class="block-body row">
                <div style="flex: 1"><strong>{{ __('Delete node') }}</strong><div class="muted small">{{ __('All threads and posts in this node are deleted with it.') }}</div></div>
                <button class="btn btn-danger" type="submit"><x-icon name="trash" :size="16" /> {{ __('Delete') }}</button>
            </div>
        </form>
    @endif
@endsection
