{{-- Account settings: the browsers that are signed in to this account right now. --}}
@php
    $sessions = app(\App\Forum\Security\Devices::class)->sessionsOf($user, session()->getId());
    $others = $sessions->where('current', false)->count();
@endphp
<section class="block" id="sessions">
    <div class="block-head"><x-icon name="monitor" :size="16" /><h2>{{ __('Open sessions') }}</h2><span class="spacer"></span><span class="muted">{{ $sessions->count() }}</span></div>
    @if ($sessions->isEmpty())
        <div class="empty">{{ __('Only this session is open.') }}</div>
    @else
        <ul class="session-list">
            @foreach ($sessions as $open)
                <li @class(['current' => $open['current']])>
                    <span class="session-icon"><x-icon :name="\App\Models\User::DEVICES[$open['device']][0] ?? 'monitor'" :size="20" /></span>
                    <div class="meta">
                        <b>{{ $open['browser'] }} · {{ $open['system'] }}</b>
                        <span class="muted small">{{ $open['ip'] ?? '—' }} · @if ($open['current']){{ __('active now') }}@else{{ __('last active :time', ['time' => $open['active']->diffForHumans()]) }}@endif</span>
                    </div>
                    @if ($open['current'])
                        <span class="badge green">{{ __('This device') }}</span>
                    @else
                        <form method="post" action="{{ route('account.sessions.end', $open['key']) }}">@csrf @method('DELETE')
                            <button class="btn btn-sm" type="submit">{{ __('Close') }}</button>
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
    @if ($others > 0)
        <form class="block-foot row" method="post" action="{{ route('account.sessions.end-others') }}">
            @csrf @method('DELETE')
            <input class="input" style="max-width: 240px" type="password" name="current_password" placeholder="{{ __('Your password') }}" autocomplete="current-password" required>
            <button class="btn btn-danger" type="submit">{{ __('Close all other sessions') }}</button>
            @error('current_password')<span class="error">{{ $message }}</span>@enderror
        </form>
    @endif
    <div class="block-foot muted small">{{ __('If you see a device you do not know, close it and change your password.') }}@if (setting('security.new_device_alerts')) {{ __('You get an alert whenever a new device signs in.') }}@endif</div>
</section>
