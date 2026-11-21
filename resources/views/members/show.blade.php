@extends('layouts.app')

@section('title', $member->username)
@section('crumb', $member->username)

@section('content')
    <section class="block">
        @if ($member->coverUrl())
            <div class="profile-cover" style="background-image: url('{{ $member->coverUrl() }}')" role="img" aria-label="{{ __('Cover photo') }}"></div>
        @elseif (auth()->id() === $member->id)
            <a class="profile-cover empty" href="{{ route('account.edit') }}#cover"><x-icon name="image" :size="18" /> {{ __('Add a cover photo') }}</a>
        @endif
        <div @class(['profile-head', 'has-cover' => (bool) $member->coverUrl()])>
            <x-avatar :user="$member" size="xl" :link="false" />
            <div class="info">
                <h1><x-username :user="$member" :link="false" /></h1>
                <div class="row" style="margin-top: 6px">
                    <x-rank :user="$member" inline />
                    @if ($member->phone_verified_at)<span class="badge green" title="{{ __('This member has verified a phone number.') }}"><x-icon name="check" :size="12" /> {{ __('Verified member') }}</span>@endif
                    @if (setting('market.enabled') && ($tradeScore = \App\Forum\Market::score($member)) !== null)
                        <a class="badge blue" href="{{ route('members.feedback', $member) }}">{{ __('Trader score: :score%', ['score' => $tradeScore]) }} (+{{ $member->trade_positive }} / −{{ $member->trade_negative }})</a>
                    @endif
                    @foreach ($member->groups->prepend($member->group)->filter()->unique('id') as $group)
                        @if ($group->badge)
                            <x-group-banner :group="$group" />
                        @endif
                    @endforeach
                    @if ($member->isBanned())<span class="badge red">{{ __('Banned') }}</span>@endif
                    @if ($showOnline)<x-presence :user="$member" :online="$member->isOnline()" />@endif
                </div>
                <div class="profile-stats">
                    <div><span class="stat-num">{{ number_format($member->post_count) }}</span><span class="stat-label">{{ __('Posts') }}</span></div>
                    <div><span class="stat-num">{{ number_format($member->thread_count) }}</span><span class="stat-label">{{ __('Threads') }}</span></div>
                    <div><span class="stat-num">{{ number_format($member->reaction_score) }}</span><span class="stat-label">{{ __('Reaction score') }}</span></div>
                    @if ($member->solution_count)<div><span class="stat-num">{{ number_format($member->solution_count) }}</span><span class="stat-label">{{ __('Solutions') }}</span></div>@endif
                    <div><span class="stat-num">{{ number_format($member->follower_count) }}</span><span class="stat-label">{{ __('Followers') }}</span></div>
                </div>
            </div>
            <div class="row">
                @auth
                    @if (auth()->id() === $member->id)
                        <a class="btn" href="{{ route('account.edit') }}"><x-icon name="edit" :size="16" /> {{ __('Edit profile') }}</a>
                    @else
                        @include('members._friend-button')
                        <form method="post" action="{{ route('members.follow', $member) }}">@csrf
                            <button @class(['btn', 'btn-primary' => ! $following]) type="submit"><x-icon name="users" :size="16" /> {{ $following ? __('Following') : __('Follow') }}</button>
                        </form>
                        @if (forum_can('conversation.start') && $canMessage)
                            <a class="btn" href="{{ route('conversations.create', ['to' => $member->username]) }}"><x-icon name="mail" :size="16" /> {{ __('Send message') }}</a>
                        @endif
                        @unless ($member->group?->is_staff)
                            <form method="post" action="{{ route('members.ignore', $member) }}">@csrf
                                <button class="btn btn-ghost" type="submit" title="{{ __('Hide this member\'s posts and stop their alerts and messages') }}">{{ $ignoring ? __('Stop ignoring') : __('Ignore') }}</button>
                            </form>
                        @endunless
                    @endif
                    @if (auth()->id() !== $member->id && ! $member->group?->is_staff && forum_can('mod.spamClean'))
                        <button class="btn btn-danger" type="button" data-dialog="spam-dialog"><x-icon name="trash" :size="16" /> {{ __('Spam cleaner') }}</button>
                    @endif
                    @can('admin')
                        <a class="btn" href="{{ route('admin.users.edit', $member) }}"><x-icon name="shield" :size="16" /> {{ __('Manage') }}</a>
                    @endcan
                @endauth
            </div>
        </div>
    </section>

    @if (auth()->check() && auth()->id() !== $member->id && ! $member->group?->is_staff && forum_can('mod.spamClean'))
        <dialog id="spam-dialog">
            <form method="post" action="{{ route('members.spam-clean', $member) }}">
                @csrf
                <div class="block-head"><h2>{{ __('Spam cleaner') }}</h2></div>
                <div class="block-body">
                    <p>{{ __(':name will be banned for good, and every thread and post of this member will be removed from the board (moderators can restore them).', ['name' => $member->username]) }}</p>
                    <div class="field">
                        <label class="check"><input type="checkbox" name="ban_addresses" value="1" checked> {{ __('Also ban the IP addresses of this member for 30 days') }}</label>
                    </div>
                    <div class="form-actions">
                        <button class="btn btn-danger" type="submit">{{ __('Ban and remove everything') }}</button>
                        <button class="btn" type="button" data-dialog-close>{{ __('Cancel') }}</button>
                    </div>
                </div>
            </form>
        </dialog>
    @endif

    <div class="layout" style="margin-top: 16px">
        <div>
        <section class="block" id="wall">
            <div class="block-head"><x-icon name="message" :size="16" /><h2>{{ __('Profile messages') }}</h2></div>
            @auth
                @if (forum_can('profile.post') && ! $member->isBanned())
                    <form class="block-body" method="post" action="{{ route('profile-posts.store', $member) }}" style="border-bottom: 1px solid var(--border)">
                        @csrf
                        <div class="row" style="flex-wrap: nowrap; align-items: flex-start">
                            <textarea class="input" name="body" maxlength="1000" style="min-height: 44px; flex: 1" placeholder="{{ auth()->id() === $member->id ? __('Write something on your profile…') : __('Write a message to :name…', ['name' => $member->username]) }}" required></textarea>
                            <button class="btn btn-primary" type="submit">{{ __('Post') }}</button>
                        </div>
                    </form>
                @endif
            @endauth
            @forelse ($wall as $message)
                <div class="item">
                    <x-avatar :user="$message->author" size="sm" />
                    <div class="body">
                        <div><x-username :user="$message->author" /> <span class="muted small">· <x-time :at="$message->created_at" /></span></div>
                        <div class="snippet" style="white-space: pre-line">{{ $message->body }}</div>
                    </div>
                    @if (auth()->check() && ($message->user_id === auth()->id() || $member->id === auth()->id() || forum_can('mod.deleteAny')))
                        <form method="post" action="{{ route('profile-posts.destroy', $message) }}" data-confirm="{{ __('Delete this message?') }}">@csrf @method('DELETE')
                            <button class="btn btn-sm btn-ghost btn-danger" type="submit" title="{{ __('Delete') }}"><x-icon name="trash" :size="14" /></button>
                        </form>
                    @endif
                </div>
            @empty
                <div class="empty">{{ __('Nobody has written here yet.') }}</div>
            @endforelse
        </section>
        {{ $wall->fragment('wall')->links() }}

        <section class="block">
            <div class="block-head"><h2>{{ __('Recent posts') }}</h2></div>
            @forelse ($posts as $post)
                <div class="item">
                    <div class="body">
                        <a class="title" href="{{ route('posts.show', $post->id) }}">{{ $post->thread->title }}</a>
                        <div class="snippet">{{ app(\App\Forum\BbCode\Renderer::class)->plain($post->body, 220) }}</div>
                        <div class="thread-meta"><x-time :at="$post->created_at" /></div>
                    </div>
                </div>
            @empty
                <div class="empty">{{ __('This member has not posted anything yet.') }}</div>
            @endforelse
        </section>
        </div>

        <aside>
            @if (auth()->check() && (auth()->user()->group?->is_staff || auth()->user()->isAdmin()))
                @include('members._staff')
            @endif
            @hook('profile.sidebar.top', ['member' => $member])
            <div class="block">
                <div class="block-head"><h2>{{ __('About') }}</h2></div>
                <div class="block-body">
                    @if ($member->about)<p style="white-space: pre-line">{{ $member->about }}</p>@endif
                    <dl class="kv">
                        <div><dt>{{ __('Joined') }}</dt><dd>{{ $member->created_at->translatedFormat('j F Y') }}</dd></div>
                        <div><dt>{{ __('Last seen') }}</dt><dd>@if ($member->last_seen_at)<x-time :at="$member->last_seen_at" />@else — @endif</dd></div>
                        @if ($member->location)<div><dt>{{ __('Location') }}</dt><dd>{{ $member->location }}</dd></div>@endif
                        @if ($member->website)<div><dt>{{ __('Website') }}</dt><dd class="truncate"><a href="{{ $member->website }}" rel="nofollow ugc noopener" target="_blank">{{ parse_url($member->website, PHP_URL_HOST) }}</a></dd></div>@endif
                        @foreach ($fields as [$field, $value])
                            <div><dt>{{ $field->label }}</dt><dd class="truncate">@if ($field->type === 'url')<a href="{{ $value }}" rel="nofollow ugc noopener" target="_blank">{{ parse_url($value, PHP_URL_HOST) ?: $value }}</a>@else{{ $value }}@endif</dd></div>
                        @endforeach
                    </dl>
                </div>
            </div>
            @if ($friendCount > 0)
                <section class="block">
                    <div class="block-head"><x-icon name="users" :size="16" /><h2>{{ __('Friends') }}</h2><span class="muted">{{ forum_number($friendCount) }}</span></div>
                    <div class="friend-grid">
                        @foreach ($friends as $friend)
                            <a href="{{ route('members.show', $friend) }}" title="{{ $friend->username }}"><x-avatar :user="$friend" size="sm" :link="false" /><span class="truncate">{{ $friend->username }}</span></a>
                        @endforeach
                    </div>
                </section>
            @endif
            @hook('profile.sidebar', ['member' => $member])
            @if ($badges->isNotEmpty())
                <div class="block">
                    <div class="block-head"><x-icon name="star" :size="16" /><h2>{{ __('Badges') }}</h2></div>
                    <ul class="side-list">
                        @foreach ($badges as $badge)
                            <li><span class="badge-icon">{{ $badge->icon }}</span><div class="meta"><span class="title">{{ $badge->title }}</span>{{ $badge->description }}</div></li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @php
                $viewer = auth()->user();
                $canWarn = $viewer && forum_can('mod.warn');
                $showWarnings = $canWarn || ($viewer && $viewer->id === $member->id);
                $memberWarnings = $showWarnings ? $member->warnings()->with('issuer')->latest('id')->limit(20)->get() : collect();
            @endphp
            @if ($showWarnings && ($memberWarnings->isNotEmpty() || $canWarn))
                <div class="block">
                    <div class="block-head"><x-icon name="alert" :size="16" /><h2>{{ __('Warnings') }}</h2><span class="spacer"></span><span class="muted">{{ __(':points points', ['points' => $member->warning_points]) }}</span></div>
                    @if ($memberWarnings->isEmpty())
                        <div class="empty" style="padding: 16px 14px">{{ __('No warnings.') }}</div>
                    @else
                        <ul class="side-list">
                            @foreach ($memberWarnings as $warning)
                                <li @style(['opacity: .55' => ! $warning->isActive()])>
                                    <div class="meta" style="flex: 1">
                                        <span class="title">{{ $warning->title }} <span class="badge {{ $warning->isActive() ? 'red' : '' }}">+{{ $warning->points }}</span></span>
                                        <x-time :at="$warning->created_at" />@if ($canWarn && $warning->issuer) · {{ $warning->issuer->username }}@endif
                                        @if ($warning->expires_at) · {{ $warning->isActive() ? __('expires :date', ['date' => $warning->expires_at->translatedFormat('j M Y')]) : __('expired') }}@endif
                                        @if ($canWarn && $warning->note)<div>“{{ $warning->note }}”</div>@endif
                                    </div>
                                    @if ($canWarn)
                                        <form method="post" action="{{ route('warnings.destroy', $warning) }}" data-confirm="{{ __('Remove this warning?') }}">@csrf @method('DELETE')
                                            <button class="btn btn-sm btn-ghost btn-danger" type="submit" title="{{ __('Remove') }}"><x-icon name="trash" :size="14" /></button>
                                        </form>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @if ($canWarn && ! $member->is($viewer) && ! $member->isAdmin())
                        <form class="block-foot" method="post" action="{{ route('members.warn', $member) }}">
                            @csrf
                            <div class="row" style="flex-wrap: nowrap">
                                <select class="input" name="warning_type_id" required style="flex: 1; min-width: 0">
                                    @foreach (\App\Models\WarningType::orderBy('display_order')->get() as $type)
                                        <option value="{{ $type->id }}">{{ $type->title }} (+{{ $type->points }})</option>
                                    @endforeach
                                </select>
                                <button class="btn btn-danger" type="submit" data-confirm="{{ __('Warn this member?') }}">{{ __('Warn') }}</button>
                            </div>
                        </form>
                    @endif
                </div>
            @endif
        </aside>
    </div>
@endsection
