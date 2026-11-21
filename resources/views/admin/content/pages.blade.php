@extends('layouts.admin')

@section('title', __('Pages'))
@section('subtitle', __('Your own pages: rules, privacy notice, about us… Published pages are linked in the footer.'))

@section('actions')
    <a class="btn btn-primary" href="{{ route('admin.pages.create') }}"><x-icon name="plus" :size="16" /> {{ __('Add page') }}</a>
@endsection

@section('content')
    <section class="block">
        @if ($pages->isEmpty())
            <div class="empty">{{ __('There are no pages yet.') }}</div>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>{{ __('Title') }}</th><th>{{ __('Address') }}</th><th>{{ __('Status') }}</th><th>{{ __('Order') }}</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($pages as $page)
                            <tr>
                                <td><b>{{ $page->title }}</b></td>
                                <td><a href="{{ route('pages.show', $page) }}" target="_blank">/pages/{{ $page->slug }}</a></td>
                                <td>
                                    @if ($page->is_published)<span class="badge green">{{ __('Published') }}</span>@else<span class="badge amber">{{ __('Draft') }}</span>@endif
                                    @if ($page->show_in_footer && $page->is_published)<span class="badge">{{ __('Footer') }}</span>@endif
                                </td>
                                <td>{{ $page->display_order }}</td>
                                <td class="actions">
                                    <a class="btn btn-sm" href="{{ route('admin.pages.edit', $page) }}"><x-icon name="edit" :size="14" /> {{ __('Edit') }}</a>
                                    <form style="display: inline" method="post" action="{{ route('admin.pages.delete', $page) }}" data-confirm="{{ __('Delete this page?') }}">@csrf @method('DELETE')
                                        <button class="btn btn-sm btn-danger" type="submit"><x-icon name="trash" :size="14" /></button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
