@extends('layouts.admin')

@section('title', __('Maintenance and updates'))
@section('subtitle', config('forum.product.name').' '.$version)

@section('content')
    @if ($log)
        <section class="block" style="margin-bottom: 14px">
            <div class="block-head"><h2>{{ __('Result') }}</h2></div>
            <div class="block-body"><pre class="log-box">{{ $log }}</pre></div>
        </section>
    @endif

    <div class="grid-2">
        <div>
            <section class="block">
                <div class="block-head"><x-icon name="refresh" :size="16" /><h2>{{ __('Database') }}</h2></div>
                <div class="block-body">
                    @if ($pending)
                        <div class="flash info">{{ __(':count database updates are waiting.', ['count' => count($pending)]) }}</div>
                        <ul class="muted small" style="margin: 0 0 12px; padding-left: 18px">@foreach ($pending as $name)<li>{{ $name }}</li>@endforeach</ul>
                    @else
                        <p class="muted">{{ __('The database is up to date.') }}</p>
                    @endif
                    <form method="post" action="{{ route('admin.update.migrate') }}">@csrf
                        <button class="btn @if ($pending) btn-primary @endif" type="submit">{{ __('Update the database and clear caches') }}</button>
                    </form>
                </div>
            </section>

            <section class="block">
                <div class="block-head"><x-icon name="clock" :size="16" /><h2>{{ __('Background tasks') }}</h2></div>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>{{ __('Task') }}</th><th>{{ __('Every') }}</th><th>{{ __('Last run') }}</th></tr></thead>
                        <tbody>
                            @foreach ($tasks as $name => $task)
                                <tr>
                                    <td><code>{{ $name }}</code></td>
                                    <td class="nowrap">{{ $task['minutes'] >= 60 ? __(':count hours', ['count' => round($task['minutes'] / 60, 1)]) : __(':count minutes', ['count' => $task['minutes']]) }}</td>
                                    <td class="nowrap">{{ $task['last'] ? \Illuminate\Support\Carbon::createFromTimestamp($task['last'])->diffForHumans() : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="block-foot">
                    <form method="post" action="{{ route('admin.update.tasks') }}">@csrf
                        <button class="btn btn-sm" type="submit">{{ __('Run all tasks now') }}</button>
                    </form>
                    <div class="muted small" style="margin-top: 8px">{{ __('Tasks run by themselves while the forum has visitors. With cron access you can also run "php artisan forum:run-tasks" every few minutes.') }}</div>
                </div>
            </section>
        </div>

        <section class="block">
            <div class="block-head"><x-icon name="plus" :size="16" /><h2>{{ __('Install an update package') }}</h2></div>
            <form class="block-body" method="post" action="{{ route('admin.update.upload') }}" enctype="multipart/form-data" data-confirm="{{ __('Install this package over the current files?') }}">
                @csrf
                <p class="muted">{{ __('Upload the .zip of a new release. Its files replace the current ones, then the database is updated. Your settings, uploads and .env file are never touched.') }}</p>
                <div class="flash info">{{ __('Take a backup of the files and the database first.') }}</div>
                @unless ($zip)<div class="flash error">{{ __('The PHP zip extension is missing on this server.') }}</div>@endunless
                <div class="field">
                    <label for="package">{{ __('Update package (.zip)') }}</label>
                    <input class="input" id="package" type="file" name="package" accept=".zip" required>
                    <div class="hint">upload_max_filesize: {{ $limits['upload_max_filesize'] }} · post_max_size: {{ $limits['post_max_size'] }}</div>
                    @error('package')<div class="error">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label for="current_password">{{ __('Your password') }}</label>
                    <input class="input" id="current_password" type="password" name="current_password" autocomplete="current-password" required>
                    @error('current_password')<div class="error">{{ $message }}</div>@enderror
                </div>
                <button class="btn btn-primary" type="submit" @disabled(! $zip)>{{ __('Install update') }}</button>
            </form>
        </section>
    </div>
@endsection
