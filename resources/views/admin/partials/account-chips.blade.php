{{-- A row of accounts, each leading to its page in the panel. --}}
<div class="account-chips">
    @foreach ($accounts as $account)
        <a @class(['account-chip', 'banned' => $account->is_banned]) href="{{ route('admin.users.edit', $account) }}" title="{{ $account->email }}">
            <b>{{ $account->username }}</b>
            <span>{{ $account->group?->name }} · {{ forum_number($account->post_count) }} {{ __('Posts') }} · {{ $account->created_at?->translatedFormat('j M Y') }}@if ($account->is_banned) · {{ __('Banned') }}@endif</span>
        </a>
    @endforeach
</div>
