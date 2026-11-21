@extends('layouts.admin')

@section('title', __('Account deletion requests'))
@section('subtitle', __('Members who asked for their account to be deleted. Deleting removes everything personal and closes the account; threads and posts stay without the name.'))

@section('content')
    <section class="block">
        <div class="block-head"><x-icon name="trash" :size="16" /><h2>{{ __('Account deletion requests') }}</h2></div>
        @if ($requests->isEmpty())
            <div class="empty">{{ __('Nobody is waiting for a decision.') }}</div>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>{{ __('Member') }}</th><th>{{ __('Asked on') }}</th><th>{{ __('Reason given') }}</th><th>{{ __('Posts') }}</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($requests as $member)
                            <tr>
                                <td><a href="{{ route('members.show', $member) }}"><b>{{ $member->username }}</b></a><div class="muted small">{{ $member->email }}</div></td>
                                <td style="white-space: nowrap">{{ $member->deletion_requested_at?->translatedFormat('j M Y H:i') }}</td>
                                <td>{{ $member->deletion_reason ?: '—' }}</td>
                                <td>{{ forum_number($member->post_count) }}</td>
                                <td class="actions">
                                    <form method="post" action="{{ route('admin.deletions.decide', $member) }}" style="display: inline-flex; gap: 6px">
                                        @csrf
                                        <button class="btn btn-sm" type="submit" name="action" value="keep">{{ __('Keep the account') }}</button>
                                        <button class="btn btn-sm btn-danger" type="submit" name="action" value="delete" data-confirm="{{ __('Delete this account for good? This cannot be undone.') }}"><x-icon name="trash" :size="14" /> {{ __('Delete the account') }}</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
    {{ $requests->links() }}
@endsection
