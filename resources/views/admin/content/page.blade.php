@extends('layouts.admin')

@section('title', $page->exists ? __('Edit page') : __('Add page'))
@section('subtitle', $page->exists ? $page->title : '')

@section('content')
    <form class="medium" style="margin-left: 0" method="post" action="{{ $page->exists ? route('admin.pages.update', $page) : route('admin.pages.store') }}">
        @csrf
        @if ($page->exists) @method('PUT') @endif

        <section class="block">
            <div class="block-body">
                <div class="form-grid">
                    <div class="field">
                        <label for="title">{{ __('Title') }}</label>
                        <input class="input" id="title" name="title" value="{{ old('title', $page->title) }}" maxlength="150" required>
                        @error('title')<div class="error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="slug">{{ __('Address') }} <span class="muted">/pages/…</span></label>
                        <input class="input" id="slug" name="slug" value="{{ old('slug', $page->slug) }}" maxlength="80" pattern="[a-z0-9-]+" placeholder="{{ __('made from the title when left empty') }}">
                        @error('slug')<div class="error">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="field">
                    <span class="label">{{ __('Content') }}</span>
                    <x-editor name="body" :value="$page->body" :rows="18" />
                </div>
                <div class="row">
                    <label class="check"><input type="checkbox" name="is_published" value="1" @checked(old('is_published', $page->is_published))> {{ __('Published') }}</label>
                    <label class="check"><input type="checkbox" name="show_in_footer" value="1" @checked(old('show_in_footer', $page->show_in_footer))> {{ __('Link in the footer') }}</label>
                    <span class="spacer"></span>
                    <label class="muted">{{ __('Order') }} <input class="input" style="width: 90px; display: inline-block" type="number" name="display_order" value="{{ old('display_order', $page->display_order ?? 0) }}" min="0"></label>
                </div>
            </div>
        </section>

        <div class="form-actions" style="margin-top: 16px">
            <button class="btn btn-primary" type="submit">{{ __('Save') }}</button>
            <a class="btn" href="{{ route('admin.pages') }}">{{ __('Cancel') }}</a>
        </div>
    </form>
@endsection
