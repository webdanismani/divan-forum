@extends('layouts.admin')

@section('title', __('Member balances'))
@section('subtitle', __('Every change to a member balance. To add or take balance by hand, open the member in Members.'))

@section('content')
    @php $labels = ['topup' => __('Top-up'), 'ad' => __('Ad'), 'promotion' => __('Thread promotion'), 'upgrade' => __('Membership package'), 'adjustment' => __('Adjustment')]; @endphp

    <div class="grid-2">
        <section class="block">
            <div class="block-head"><h2>{{ __('Transactions') }}</h2></div>
            @if ($transactions->isEmpty())
                <div class="empty">{{ __('No transactions yet.') }}</div>
            @else
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Member') }}</th><th>{{ __('Description') }}</th><th>{{ __('Amount') }}</th></tr></thead>
                        <tbody>
                            @foreach ($transactions as $row)
                                <tr>
                                    <td class="nowrap">{{ $row->created_at->translatedFormat('j M H:i') }}</td>
                                    <td><x-username :user="$row->user" /></td>
                                    <td>{{ $labels[$row->type] ?? $row->type }}: {{ $row->description }}@if ($row->actor)<div class="muted small">{{ $row->actor->username }}</div>@endif</td>
                                    <td class="{{ $row->amount >= 0 ? 'amount-plus' : 'amount-minus' }}">{{ $row->amount >= 0 ? '+' : '−' }}{{ forum_money(abs($row->amount)) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="block">
            <div class="block-head"><h2>{{ __('Highest balances') }}</h2></div>
            @if ($richest->isEmpty())
                <div class="empty">{{ __('Nothing to show yet.') }}</div>
            @else
                <ul class="side-list">
                    @foreach ($richest as $member)
                        <li>
                            <x-avatar :user="$member" size="sm" />
                            <div class="meta"><x-username :user="$member" /></div>
                            <span class="num">{{ forum_money($member->balance) }}</span>
                            <a class="btn btn-sm" href="{{ route('admin.users.edit', $member) }}"><x-icon name="edit" :size="14" /></a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    {{ $transactions->links() }}
@endsection
