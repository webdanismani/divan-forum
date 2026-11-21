@extends('layouts.app')

@section('title', __('New thread'))
@section('crumb', __('New thread'))

@section('content')
    <div class="medium">
        <div class="page-head"><div class="titles"><h1>{{ __('New thread') }}</h1><div class="desc">{{ $node->title }}</div></div></div>

        <form class="block" method="post" action="{{ route('threads.store', $node) }}">
            @csrf
            <div class="block-body">
                @if ($node->require_approval && ! forum_can('mod.approve', $node))
                    <div class="flash info">{{ __('Threads in this forum are reviewed by a moderator before they become visible.') }}</div>
                @endif

                <div class="row title-row" style="align-items: flex-start; flex-wrap: nowrap">
                    @if ($prefixes->isNotEmpty())
                        <div class="field" style="width: 170px; flex: none">
                            <label for="prefix_id">{{ __('Prefix') }}</label>
                            <select class="input" id="prefix_id" name="prefix_id" @required($node->require_prefix)>
                                <option value="">{{ $node->require_prefix ? __('Choose…') : __('(none)') }}</option>
                                @foreach ($prefixes as $prefix)
                                    <option value="{{ $prefix->id }}" @selected(old('prefix_id') == $prefix->id)>{{ $prefix->title }}</option>
                                @endforeach
                            </select>
                            @error('prefix_id')<div class="error">{{ $message }}</div>@enderror
                        </div>
                    @endif
                    <div class="field" style="flex: 1">
                        <label for="title">{{ __('Title') }}</label>
                        <input class="input" id="title" name="title" value="{{ old('title') }}" minlength="3" maxlength="150" required autofocus @if (setting('posting.title_case')) data-title-case @endif data-similar="{{ route('threads.similar') }}">
                        @error('title')<div class="error">{{ $message }}</div>@enderror
                        <div class="similar-box" data-similar-box hidden><b>{{ __('Has this been asked before?') }}</b><div data-similar-list></div></div>
                    </div>
                </div>

                @if ($market)
                    @if ($marketRefusal)<div class="flash error">{{ $marketRefusal }}</div>@endif
                    <div class="form-grid">
                        <div class="field">
                            <label for="listing_type">{{ __('Listing type') }}</label>
                            <select class="input" id="listing_type" name="listing_type">
                                @foreach (\App\Models\Listing::TYPES as $key => $label)<option value="{{ $key }}" @selected(old('listing_type') === $key)>{{ __($label) }}</option>@endforeach
                            </select>
                        </div>
                        <div class="field">
                            <label for="listing_price">{{ __('Price') }} ({{ config('forum.ads.currency') }}) <span class="muted">({{ __('optional') }})</span></label>
                            <input class="input" id="listing_price" type="number" name="listing_price" value="{{ old('listing_price') }}" min="0" step="0.01" placeholder="{{ __('Leave empty for "ask"') }}">
                        </div>
                    </div>
                @endif

                <div class="field">
                    <span class="label">{{ __('Message') }}</span>
                    <x-editor name="body" :rows="12" :node="$node" :draft="'thread-'.$node->id" :value="(string) $node->thread_template" :prefilled="filled($node->thread_template)" />
                    @error('body')<div class="error">{{ $message }}</div>@enderror
                </div>

                @if (app(\App\Forum\Tags::class)->enabled())
                    <div class="field">
                        <label for="tags">{{ __('Tags') }} <span class="muted">({{ __('optional') }})</span></label>
                        <input class="input" id="tags" name="tags" value="{{ old('tags') }}" maxlength="300" placeholder="{{ __('seo, hosting, wordpress') }}">
                        <div class="hint">{{ __('Separate tags with commas, :count at most.', ['count' => app(\App\Forum\Tags::class)->limit()]) }}</div>
                    </div>
                @endif

                @if (forum_can('poll.create', $node))
                    <details class="field" @if (old('poll_question') || $errors->has('poll_options')) open @endif>
                        <summary class="label" style="cursor: pointer"><x-icon name="chart" :size="15" /> {{ __('Add a poll') }}</summary>
                        <div style="padding-top: 10px">
                            <div class="field">
                                <label for="poll_question">{{ __('Question') }}</label>
                                <input class="input" id="poll_question" name="poll_question" value="{{ old('poll_question') }}" maxlength="200">
                            </div>
                            <div class="field">
                                <label for="poll_options">{{ __('Options (one per line)') }}</label>
                                <textarea class="input" id="poll_options" name="poll_options" style="min-height: 110px">{{ old('poll_options') }}</textarea>
                                @error('poll_options')<div class="error">{{ $message }}</div>@enderror
                            </div>
                            <div class="form-grid">
                                <div class="field">
                                    <label for="poll_max_choices">{{ __('Options a member may choose') }}</label>
                                    <input class="input" id="poll_max_choices" type="number" name="poll_max_choices" value="{{ old('poll_max_choices', 1) }}" min="1" max="{{ \App\Forum\Polls::MAX_OPTIONS }}">
                                </div>
                                <div class="field">
                                    <label for="poll_days">{{ __('Close after (days)') }}</label>
                                    <input class="input" id="poll_days" type="number" name="poll_days" value="{{ old('poll_days') }}" min="1" max="365" placeholder="{{ __('never') }}">
                                </div>
                            </div>
                            <label class="check"><input type="checkbox" name="poll_locked" value="1" @checked(old('poll_locked'))> {{ __('Votes cannot be changed') }}</label>
                        </div>
                    </details>
                @endif

                @if (forum_can('mod.stick', $node) || forum_can('mod.lock', $node))
                    <div class="field thread-options">
                        <span class="label">{{ __('Thread options') }}</span>
                        <div class="row">
                            @if (forum_can('mod.stick', $node))
                                <label class="check"><input type="checkbox" name="sticky" value="1" @checked(old('sticky'))> <x-icon name="pin" :size="14" /> {{ __('Stick this thread to the top of the forum') }}</label>
                            @endif
                            @if (forum_can('mod.lock', $node))
                                <label class="check"><input type="checkbox" name="locked" value="1" @checked(old('locked'))> <x-icon name="lock" :size="14" /> {{ __('Lock it: nobody can reply') }}</label>
                            @endif
                        </div>
                    </div>
                @endif

                <div class="form-actions">
                    <button class="btn btn-primary" type="submit">{{ __('Post thread') }}</button>
                    <a class="btn" href="{{ route('nodes.show', $node) }}">{{ __('Cancel') }}</a>
                </div>
            </div>
        </form>
    </div>
@endsection
