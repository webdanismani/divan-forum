{{-- For the staff only: notes about the member and the names the account had before. --}}
@php
    $staffNotes = \Illuminate\Support\Facades\DB::table('user_notes as n')->leftJoin('users as a', 'a.id', '=', 'n.author_id')
        ->where('n.user_id', $member->id)->orderByDesc('n.id')->limit(20)->get(['n.id', 'n.body', 'n.created_at', 'n.author_id', 'a.username as author']);
    $oldNames = \Illuminate\Support\Facades\DB::table('username_changes')->where('user_id', $member->id)->orderByDesc('id')->limit(10)->get();
    $linkedAccounts = app(\App\Forum\Security\Duplicates::class)->linkedTo($member, 8);
@endphp
<section class="block staff-notes" id="staff-notes">
    <div class="block-head"><x-icon name="shield" :size="16" /><h2>{{ __('Staff notes') }}</h2><span class="muted" style="margin-left: auto">{{ __('Only the staff sees this') }}</span></div>
    @if ($oldNames->isNotEmpty())
        <div class="staff-names"><b>{{ __('Earlier usernames') }}:</b>
            @foreach ($oldNames as $change){{ $change->old_name }} <span class="muted">({{ \Illuminate\Support\Carbon::parse($change->created_at)->translatedFormat('j M Y') }})</span>@if (! $loop->last), @endif @endforeach
        </div>
    @endif
    @if ($linkedAccounts)
        <div class="staff-names"><b>{{ __('Possibly the same person') }}:</b>
            @foreach ($linkedAccounts as $link)<a href="{{ route('members.show', $link['user']) }}">{{ $link['user']->username }}</a> <span class="muted">({{ $link['device'] ? __('Same browser') : __(':count shared IP addresses', ['count' => $link['ips']]) }})</span>@if (! $loop->last), @endif @endforeach
        </div>
    @endif
    @forelse ($staffNotes as $note)
        <div class="staff-note">
            <div class="text">{{ $note->body }}</div>
            <div class="meta">
                <span>{{ $note->author ?? '—' }} · {{ \Illuminate\Support\Carbon::parse($note->created_at)->diffForHumans() }}</span>
                @if ((int) $note->author_id === auth()->id() || auth()->user()->isAdmin())
                    <form method="post" action="{{ route('member-notes.destroy', $note->id) }}" data-confirm="{{ __('Delete this note?') }}">@csrf @method('DELETE')
                        <button class="link-btn" type="submit">{{ __('Delete') }}</button>
                    </form>
                @endif
            </div>
        </div>
    @empty
        <div class="muted small" style="padding: 10px 14px">{{ __('No notes about this member yet.') }}</div>
    @endforelse
    <form class="block-foot" method="post" action="{{ route('member-notes.store', $member) }}">
        @csrf
        <textarea class="input" name="body" maxlength="1000" style="min-height: 54px" placeholder="{{ __('A note for the other moderators…') }}" required></textarea>
        @error('body')<div class="error">{{ $message }}</div>@enderror
        <button class="btn btn-sm" type="submit" style="margin-top: 6px">{{ __('Add note') }}</button>
    </form>
</section>
