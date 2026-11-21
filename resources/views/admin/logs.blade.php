@extends('layouts.admin')

@section('title', __('Moderator log'))
@section('subtitle', __('A record of moderator and administrator actions.'))

@section('content')
    <section class="block">
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Member') }}</th><th>{{ __('Action') }}</th><th>{{ __('Content') }}</th><th>{{ __('IP address') }}</th></tr></thead>
                <tbody>
                    @forelse ($logs as $log)
                        <tr>
                            <td class="nowrap">{{ $log->created_at?->translatedFormat('j M Y H:i') }}</td>
                            <td>{{ $log->user?->username ?? '—' }}</td>
                            <td><code>{{ $log->action }}</code></td>
                            <td>{{ $log->summary }} @if ($log->content_id)<span class="muted small">({{ $log->content_type }} #{{ $log->content_id }})</span>@endif</td>
                            <td class="muted">{{ $log->ip_address }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="empty">{{ __('No moderator actions yet.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{ $logs->links() }}
@endsection
