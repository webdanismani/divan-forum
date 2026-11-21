{{-- Friend button for the member in $member; $friendship is none | friends | sent | received, $canFriend whether a request may be sent. --}}
@if (auth()->check() && auth()->id() !== $member->id)
    @if ($friendship === 'friends')
        <form method="post" action="{{ route('members.friend', $member) }}" data-confirm="{{ __('Remove this member from your friends?') }}">@csrf
            <input type="hidden" name="action" value="remove">
            <button class="btn" type="submit"><x-icon name="check" :size="16" /> {{ __('Friends') }}</button>
        </form>
    @elseif ($friendship === 'sent')
        <form method="post" action="{{ route('members.friend', $member) }}">@csrf
            <input type="hidden" name="action" value="remove">
            <button class="btn" type="submit" title="{{ __('Withdraw the request') }}"><x-icon name="clock" :size="16" /> {{ __('Request sent') }}</button>
        </form>
    @elseif ($friendship === 'received')
        <form method="post" action="{{ route('members.friend', $member) }}">@csrf
            <input type="hidden" name="action" value="add">
            <button class="btn btn-primary" type="submit"><x-icon name="check" :size="16" /> {{ __('Accept friend request') }}</button>
        </form>
        <form method="post" action="{{ route('members.friend', $member) }}">@csrf
            <input type="hidden" name="action" value="remove">
            <button class="btn btn-ghost" type="submit">{{ __('Decline') }}</button>
        </form>
    @elseif ($canFriend)
        <form method="post" action="{{ route('members.friend', $member) }}">@csrf
            <input type="hidden" name="action" value="add">
            <button class="btn" type="submit"><x-icon name="plus" :size="16" /> {{ __('Add friend') }}</button>
        </form>
    @endif
@endif
