@extends('layouts.app')

@section('title', __('Reported posts'))
@section('crumb', __('Moderation'))

@section('content')
    <div class="page-head"><div class="titles"><h1>{{ __('Moderation') }}</h1></div></div>

    <nav class="tabs">
        <a href="{{ route('mod.reports') }}" @class(['active' => $status === 'open'])>{{ __('Open reports') }}</a>
        <a href="{{ route('mod.reports', ['status' => 'closed']) }}" @class(['active' => $status === 'closed'])>{{ __('Closed reports') }}</a>
        <a href="{{ route('mod.queue') }}">{{ __('Approval queue') }}</a>
    </nav>

    <section class="block">
        @forelse ($reports as $report)
            <div class="item">
                <x-avatar :user="$report->post?->user" />
                <div class="body">
                    @if ($report->post)
                        <a class="title" href="{{ route('posts.show', $report->post->id) }}">{{ $report->post->thread->title }}</a>
                        <div class="snippet">{{ app(\App\Forum\BbCode\Renderer::class)->plain($report->post->body, 240) }}</div>
                    @endif
                    <div class="bb-quote" style="margin-bottom: 6px">
                        <div class="bb-quote-head">{{ __('Reported by :name', ['name' => $report->user?->username ?? __('Deleted member')]) }} · <x-time :at="$report->created_at" /></div>
                        <div class="bb-quote-body">{{ $report->reason }}</div>
                    </div>
                    @if ($report->status !== 'open')
                        <span @class(['badge', 'green' => $report->status === 'resolved'])>{{ $report->status === 'resolved' ? __('Resolved') : __('Rejected') }}</span>
                        <span class="muted small">{{ $report->handler?->username }} · <x-time :at="$report->handled_at" /></span>
                    @endif
                </div>
                @if ($report->status === 'open')
                    <form class="row" method="post" action="{{ route('mod.reports.update', $report) }}">
                        @csrf @method('PUT')
                        <button class="btn btn-sm btn-primary" name="status" value="resolved">{{ __('Resolve') }}</button>
                        <button class="btn btn-sm" name="status" value="rejected">{{ __('Reject') }}</button>
                    </form>
                @endif
            </div>
        @empty
            <div class="empty">{{ __('There are no reports here.') }}</div>
        @endforelse
    </section>

    {{ $reports->links() }}
@endsection
