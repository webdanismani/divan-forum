@extends('layouts.admin')

@section('title', __('Import from another forum'))
@section('subtitle', __('Bring the members, forums, threads and posts of your old forum into this one.'))

@section('content')
    @php
        $steps = ['users' => __('Members'), 'forums' => __('Forums'), 'threads' => __('Threads'), 'posts' => __('Posts'), 'rebuild' => __('Counting replies'), 'members' => __('Counting member posts'), 'done' => __('Finished')];
        $labels = ['users' => __('Members'), 'forums' => __('Forums'), 'threads' => __('Threads'), 'posts' => __('Posts')];
        $active = $runs->firstWhere('status', 'running');
    @endphp

    @foreach ($runs as $run)
        <section class="block" @if ($run->status === 'running') data-import="{{ route('admin.import.advance', $run) }}" @endif>
            <div class="block-head"><x-icon name="refresh" :size="16" /><h2>{{ $sources[$run->source]['label'] ?? $run->source }} · #{{ $run->id }}</h2>
                <span class="spacer"></span>
                <span class="muted">{{ $run->created_at?->format('d.m.Y H:i') }}</span>
                <span class="badge {{ ['done' => 'green', 'failed' => 'red'][$run->status] ?? '' }}" data-import-status>{{ ['running' => __('Running'), 'done' => __('Finished'), 'failed' => __('Stopped')][$run->status] ?? $run->status }}</span>
            </div>
            <div class="block-body">
                <div class="import-bar"><span data-import-bar style="width: {{ $run->percent }}%"></span></div>
                <div class="row" style="margin-top: 10px">
                    <strong data-import-percent>{{ $run->percent }}%</strong>
                    <span class="muted" data-import-step>{{ $steps[$run->step] ?? $run->step }}</span>
                    <span class="spacer"></span>
                    @foreach ($labels as $key => $label)
                        <span class="muted">{{ $label }}: <strong data-import-count="{{ $key }}">{{ forum_number($run->stats['done'][$key] ?? 0) }}</strong> / {{ forum_number($run->stats['totals'][$key] ?? 0) }}</span>
                    @endforeach
                </div>
                @if (($run->stats['merged'] ?? 0) || ($run->stats['skipped'] ?? 0))
                    <div class="hint" style="margin-top: 8px">
                        {{ __(':merged members already had an account here with the same e-mail address and were joined with it. :skipped threads or posts were left out because their forum or thread no longer exists.', ['merged' => forum_number($run->stats['merged'] ?? 0), 'skipped' => forum_number($run->stats['skipped'] ?? 0)]) }}
                    </div>
                @endif
                <div class="hint" style="margin-top: 8px; color: var(--danger, #c0392b)" data-import-error @if (! $run->error) hidden @endif>{{ $run->error }}</div>
                @if ($run->status !== 'done')
                    <div class="row" style="margin-top: 12px">
                        @if ($run->status === 'running')
                            <span class="muted small">{{ __('Keep this page open. If it is closed, the import pauses and continues when you come back.') }}</span>
                        @endif
                        <span class="spacer"></span>
                        <form method="post" action="{{ route('admin.import.update', $run) }}">@csrf @method('PUT')
                            @if ($run->status === 'failed' && $run->config !== '')
                                <button class="btn btn-primary" type="submit" name="action" value="retry">{{ __('Continue') }}</button>
                            @endif
                            @if ($run->status === 'running' || $run->config !== '')
                                <button class="btn btn-danger" type="submit" name="action" value="stop" data-confirm="{{ __('Stop this import? What has been imported so far stays.') }}">{{ __('Stop') }}</button>
                            @endif
                        </form>
                    </div>
                @endif
            </div>
        </section>
    @endforeach

    @unless ($active)
        <form class="block" method="post" action="{{ route('admin.import.start') }}" autocomplete="off">
            @csrf
            <div class="block-head"><x-icon name="folder" :size="16" /><h2>{{ __('New import') }}</h2></div>
            <div class="block-body">
                <div class="form-grid">
                    <div class="field">
                        <label for="source">{{ __('Old forum software') }}</label>
                        <select class="input" id="source" name="source" data-import-source>
                            @foreach ($sources as $key => $source)
                                <option value="{{ $key }}" data-prefix="{{ $source['prefix'] }}" @selected(old('source') === $key)>{{ $source['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="prefix">{{ __('Table prefix') }}</label>
                        <input class="input" id="prefix" name="prefix" value="{{ old('prefix', $sources->first()['prefix']) }}" maxlength="30" data-import-prefix>
                        <div class="hint">{{ __('What the table names of the old forum start with, for example xf_ or mybb_. Leave empty if there is none.') }}</div>
                    </div>
                </div>
                <div class="form-grid" style="grid-template-columns: 2fr 1fr">
                    <div class="field">
                        <label for="host">{{ __('Database server') }}</label>
                        <input class="input" id="host" name="host" value="{{ old('host', 'localhost') }}" maxlength="255" required>
                    </div>
                    <div class="field">
                        <label for="port">{{ __('Port') }}</label>
                        <input class="input" id="port" type="number" name="port" value="{{ old('port', 3306) }}" min="1" max="65535" required>
                    </div>
                </div>
                <div class="form-grid" style="grid-template-columns: 1fr 1fr 1fr">
                    <div class="field">
                        <label for="database">{{ __('Database name') }}</label>
                        <input class="input" id="database" name="database" value="{{ old('database') }}" maxlength="64" required>
                    </div>
                    <div class="field">
                        <label for="db-username">{{ __('Database user') }}</label>
                        <input class="input" id="db-username" name="username" value="{{ old('username') }}" maxlength="64" required>
                    </div>
                    <div class="field">
                        <label for="db-password">{{ __('Database password') }}</label>
                        <input class="input" id="db-password" type="password" name="password" maxlength="255" autocomplete="new-password">
                    </div>
                </div>
                <div class="field" style="max-width: 320px">
                    <label for="charset">{{ __('Character set of the connection') }}</label>
                    <select class="input" id="charset" name="charset">
                        @foreach (['utf8mb4' => 'utf8mb4 ('.__('recommended').')', 'utf8' => 'utf8', 'latin1' => 'latin1', 'latin5' => 'latin5 (ISO-8859-9)'] as $key => $label)
                            <option value="{{ $key }}" @selected(old('charset', 'utf8mb4') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <div class="hint">{{ __('If Turkish letters arrive broken (ý, þ, ð instead of ı, ş, ğ), stop, and run the import again with latin1.') }}</div>
                </div>
                @foreach (['source', 'host', 'port', 'database', 'username', 'prefix', 'charset'] as $name)
                    @error($name)<div class="field"><span class="error">{{ $message }}</span></div>@enderror
                @endforeach
                <button class="btn btn-primary" type="submit">{{ __('Start the import') }}</button>
            </div>
        </form>
    @endunless

    <section class="block">
        <div class="block-head"><x-icon name="alert" :size="16" /><h2>{{ __('Before you start') }}</h2></div>
        <div class="block-body">
            <ul class="bb-list" style="margin: 0">
                <li>{{ __('The old forum\'s database has to be reachable from this server: usually you restore a copy of it as a second database in the same hosting account. The old forum is only read, never changed.') }}</li>
                <li>{{ __('Members, forums and categories, threads and posts are imported. Members keep their passwords. Attachments, private messages, polls, reactions and avatars are not imported.') }}</li>
                <li>{{ __('Everybody arrives as a normal member; give your moderators and administrators their groups afterwards. A member whose e-mail address already exists here is joined with that account.') }}</li>
                <li>{{ __('Take a backup of this forum first. An import adds to what is already here and cannot be undone with one click.') }}</li>
                <li>{{ __('Large forums take a while. With server access, "php artisan forum:import" finishes a started import without the browser.') }}</li>
            </ul>
        </div>
    </section>
@endsection
