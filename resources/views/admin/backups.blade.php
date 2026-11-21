@extends('layouts.admin')

@section('title', __('Backups'))
@section('subtitle', __('Copies of the database and of the uploaded files. Download them: a backup that only lives on this server is lost together with it.'))

@php
    $human = function (int $bytes) {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                return ($unit === 'B' ? $bytes : number_format($bytes, 1, ',', '.')).' '.$unit;
            }
            $bytes /= 1024;
        }
    };
@endphp

@section('content')
    <div class="grid-2">
        <section class="block">
            <div class="block-head"><x-icon name="list" :size="16" /><h2>{{ __('Database') }}</h2></div>
            <div class="block-body">
                <p style="margin: 0 0 10px">{{ __('Members, threads, posts, settings — everything except the uploaded files, as one compressed SQL file.') }}</p>
                <form method="post" action="{{ route('admin.backups.store') }}">@csrf
                    <input type="hidden" name="kind" value="db">
                    <div class="row" style="gap: 8px">
                        <input class="input" style="flex: 1; min-width: 150px" type="password" name="current_password" autocomplete="current-password" placeholder="{{ __('Your password') }}" required>
                        <button class="btn btn-primary" type="submit"><x-icon name="plus" :size="15" /> {{ __('Back up the database now') }}</button>
                    </div>
                </form>
            </div>
            <div class="block-foot muted small">
                @if ((int) setting('backup.auto_days') > 0)
                    {{ __('Automatic: every :days days, the newest :keep are kept.', ['days' => (int) setting('backup.auto_days'), 'keep' => (int) setting('backup.keep')]) }}
                @else
                    {{ __('Automatic backups are off.') }}
                @endif
                <a href="{{ \App\Forum\Setup\Checklist::settingsUrl('backup.auto_days') }}">{{ __('Change') }}</a>
            </div>
        </section>
        <section class="block">
            <div class="block-head"><x-icon name="image" :size="16" /><h2>{{ __('Uploaded files') }}</h2></div>
            <div class="block-body">
                <p style="margin: 0 0 10px">{{ __('Profile pictures, attachments, logos and icons as one zip file. Size now: about :size.', ['size' => $human($uploadsSize)]) }}</p>
                <form method="post" action="{{ route('admin.backups.store') }}">@csrf
                    <input type="hidden" name="kind" value="files">
                    <div class="row" style="gap: 8px">
                        <input class="input" style="flex: 1; min-width: 150px" type="password" name="current_password" autocomplete="current-password" placeholder="{{ __('Your password') }}" required>
                        <button class="btn" type="submit"><x-icon name="plus" :size="15" /> {{ __('Back up the files now') }}</button>
                    </div>
                </form>
            </div>
            <div class="block-foot muted small">{{ __('With many attachments this takes a while and needs the same amount of free disk space.') }}</div>
        </section>
    </div>

    <section class="block" style="margin-top: 16px">
        <div class="block-head"><x-icon name="folder" :size="16" /><h2>{{ __('Backups on this server') }}</h2><span class="spacer"></span><span class="muted">{{ count($backups) }}</span></div>
        @if (! $backups)
            <div class="empty">{{ __('No backup has been made yet.') }}</div>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>{{ __('File') }}</th><th>{{ __('Contents') }}</th><th>{{ __('Size') }}</th><th>{{ __('Date') }}</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($backups as $backup)
                            <tr>
                                <td><code>{{ $backup['name'] }}</code></td>
                                <td>{{ $backup['kind'] === 'db' ? __('Database') : __('Uploaded files') }}</td>
                                <td class="nowrap">{{ $human($backup['size']) }}</td>
                                <td class="nowrap">{{ \Illuminate\Support\Carbon::createFromTimestamp($backup['time'])->translatedFormat('j M Y H:i') }}</td>
                                <td class="actions">
                                    <form style="display: inline-flex; gap: 6px; vertical-align: middle" method="post" action="{{ route('admin.backups.download', $backup['name']) }}">@csrf
                                        <input class="input" style="width: 140px; padding: 4px 8px" type="password" name="current_password" autocomplete="current-password" placeholder="{{ __('Your password') }}" required>
                                        <button class="btn btn-sm btn-primary" type="submit">{{ __('Download') }}</button>
                                    </form>
                                    <form style="display: inline" method="post" action="{{ route('admin.backups.destroy', $backup['name']) }}" data-confirm="{{ __('Delete this backup?') }}">@csrf @method('DELETE')
                                        <button class="btn btn-sm btn-danger" type="submit"><x-icon name="trash" :size="14" /></button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        <div class="block-foot muted small">{{ __('To put a database backup back, import the file with phpMyAdmin or the database tool of your hosting panel. The file with the passwords of the installation (.env) is not part of any backup.') }}</div>
    </section>
@endsection
