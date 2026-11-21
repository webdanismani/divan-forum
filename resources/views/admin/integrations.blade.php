@extends('layouts.admin')

@section('title', __('API and webhooks'))
@section('subtitle', __('Let other software read and write the forum, and tell it when something happens.'))

@section('content')
    <section class="block">
        <div class="block-head"><x-icon name="key" :size="16" /><h2>{{ __('REST API') }}</h2><span class="spacer"></span>
            @if (setting('api.enabled'))<span class="badge green">{{ __('On') }}</span>@else<span class="badge">{{ __('Off') }}</span>@endif
        </div>
        <div class="block-body">
            <p class="muted" style="margin-top: 0">
                {{ __('The API is switched on under Settings > API and apps. Members create their own keys in their account settings; a key can do exactly what its owner may do on the forum, nothing more.') }}
            </p>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>{{ __('Request') }}</th><th>{{ __('What it does') }}</th></tr></thead>
                    <tbody>
                        @foreach ([
                            'GET /api/v1/forums' => __('Forums the key may see'),
                            'GET /api/v1/forums/{id}/threads' => __('Threads of a forum, newest activity first'),
                            'POST /api/v1/forums/{id}/threads' => __('Start a thread (title, body, tags)'),
                            'GET /api/v1/threads/{id}' => __('One thread with its first post'),
                            'GET /api/v1/threads/{id}/posts' => __('Posts of a thread'),
                            'POST /api/v1/threads/{id}/posts' => __('Reply to a thread (body)'),
                            'GET /api/v1/members/{id}' => __('Public profile of a member'),
                            'GET /api/v1/me' => __('The owner of the key'),
                        ] as $request => $text)
                            <tr><td><code>{{ $request }}</code></td><td>{{ $text }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="muted small" style="margin-bottom: 0">
                {{ __('Send the key in the header "Authorization: Bearer <key>". Without a key the answer is what a guest would see. Lists take ?page=2. Base address:') }}
                <code>{{ url('/api/v1') }}</code>
            </p>
        </div>
    </section>

    @if ($tokens->isNotEmpty())
        <section class="block">
            <div class="block-head"><h2>{{ __('API keys of members') }}</h2></div>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>{{ __('Member') }}</th><th>{{ __('Name') }}</th><th>{{ __('Created') }}</th><th>{{ __('Last used') }}</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($tokens as $token)
                            <tr>
                                <td>{{ $token->username }}</td>
                                <td>{{ $token->name }}</td>
                                <td class="muted">{{ $token->created_at?->format('d.m.Y') }}</td>
                                <td class="muted">{{ $token->last_used_at?->diffForHumans() ?? '—' }}</td>
                                <td class="actions">
                                    <form method="post" action="{{ route('admin.integrations.tokens.delete', $token) }}" data-confirm="{{ __('Delete this API key?') }}">@csrf @method('DELETE')
                                        <button class="btn btn-sm btn-danger" type="submit"><x-icon name="trash" :size="14" /></button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @foreach ($webhooks->concat([null]) as $hook)
        <form class="block" method="post" action="{{ route('admin.integrations.webhooks.save') }}">
            @csrf
            @if ($hook)<input type="hidden" name="id" value="{{ $hook->id }}">@endif
            <div class="block-head"><x-icon name="link" :size="16" /><h2>{{ $hook ? __('Webhook').' #'.$hook->id : __('New webhook') }}</h2>
                @if ($hook?->last_called_at)
                    <span class="spacer"></span>
                    <span class="badge {{ $hook->last_status >= 200 && $hook->last_status < 300 ? 'green' : 'red' }}">{{ $hook->last_status ? 'HTTP '.$hook->last_status : __('No answer') }}</span>
                    <span class="muted">{{ $hook->last_called_at->diffForHumans() }}</span>
                @endif
            </div>
            <div class="block-body">
                <div class="field">
                    <label>{{ __('Address that receives the requests') }}</label>
                    <input class="input" type="url" name="url" value="{{ old('id') == $hook?->id ? old('url', $hook?->url) : $hook?->url }}" maxlength="255" placeholder="https://" required>
                </div>
                <div class="row" style="margin-bottom: 12px" data-check-group>
                    <span class="check-tools" style="margin: 0; width: 100%">
                        <button class="btn btn-sm" type="button" data-check="all">{{ __('Select all') }}</button>
                        <button class="btn btn-sm" type="button" data-check="none">{{ __('Clear all') }}</button>
                    </span>
                    @foreach ($events as $key => $label)
                        <label class="check"><input type="checkbox" name="events[]" value="{{ $key }}" @checked($hook ? in_array($key, $hook->events ?? [], true) : true)> {{ __($label) }} <code>{{ $key }}</code></label>
                    @endforeach
                </div>
                @if ($hook)
                    <div class="field">
                        <label>{{ __('Secret') }}</label>
                        <input class="input" value="{{ $hook->secret }}" readonly onfocus="this.select()">
                        <div class="hint">{{ __('Every request carries the header X-Forum-Signature: the HMAC-SHA256 of the request body with this secret. Check it before you trust a request.') }}</div>
                    </div>
                    @if ($hook->last_error)<div class="hint" style="margin-bottom: 12px">{{ __('Last error:') }} {{ $hook->last_error }}</div>@endif
                @endif
                <div class="row">
                    <label class="check"><input type="checkbox" name="is_active" value="1" @checked($hook?->is_active ?? true)> {{ __('In use') }}</label>
                    <span class="spacer"></span>
                    @if ($hook)<button class="btn" type="submit" form="test-hook-{{ $hook->id }}">{{ __('Send a test') }}</button>@endif
                    <button class="btn btn-primary" type="submit">{{ $hook ? __('Save') : __('Add') }}</button>
                    @if ($hook)<button class="btn btn-danger" type="submit" form="delete-hook-{{ $hook->id }}"><x-icon name="trash" :size="15" /></button>@endif
                </div>
                @error('url')<div class="field"><span class="error">{{ $message }}</span></div>@enderror
                @error('events')<div class="field"><span class="error">{{ $message }}</span></div>@enderror
            </div>
        </form>
        @if ($hook)
            <form id="test-hook-{{ $hook->id }}" method="post" action="{{ route('admin.integrations.webhooks.test', $hook) }}">@csrf</form>
            <form id="delete-hook-{{ $hook->id }}" method="post" action="{{ route('admin.integrations.webhooks.delete', $hook) }}" data-confirm="{{ __('Delete this webhook?') }}">@csrf @method('DELETE')</form>
        @endif
    @endforeach

    <section class="block">
        <div class="block-head"><x-icon name="bell" :size="16" /><h2>{{ __('App and browser notifications') }}</h2></div>
        <div class="block-body muted">
            {{ __('Visitors can install the forum on their phone or computer like an app, and members can switch on browser notifications in their account settings. Both are switched on under Settings > API and apps.') }}
            {{ trans_choice('{0} No browser is subscribed yet.|{1} :count browser is subscribed.|[2,*] :count browsers are subscribed.', $pushCount, ['count' => $pushCount]) }}
        </div>
    </section>
@endsection
