@extends('layouts.app')

@section('title', $thread->title)
@section('description', app(\App\Forum\BbCode\Renderer::class)->plain($posts->first()?->body ?? '', 160))

@section('og_type', 'article')
@section('head')
    <link rel="canonical" href="{{ route('threads.show', $thread) }}{{ $posts->currentPage() > 1 ? '?page='.$posts->currentPage() : '' }}">
    @if ($thread->isVisible() && $posts->currentPage() === 1)
        {{-- JSON_HEX_TAG keeps user text from closing the script element. --}}
        <script type="application/ld+json">{!! json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'DiscussionForumPosting',
            'headline' => $thread->title,
            'url' => route('threads.show', $thread),
            'datePublished' => $thread->created_at->toAtomString(),
            'dateModified' => ($thread->last_post_at ?? $thread->created_at)->toAtomString(),
            'author' => ['@type' => 'Person', 'name' => $thread->user?->username ?? '—'] + ($thread->user ? ['url' => route('members.show', $thread->user)] : []),
            'text' => app(\App\Forum\BbCode\Renderer::class)->plain($posts->first()?->body ?? '', 800),
            'interactionStatistic' => [
                ['@type' => 'InteractionCounter', 'interactionType' => 'https://schema.org/CommentAction', 'userInteractionCount' => $thread->reply_count],
                ['@type' => 'InteractionCounter', 'interactionType' => 'https://schema.org/ViewAction', 'userInteractionCount' => $thread->view_count],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
    @endif
@endsection

@php
    $me = auth()->user();
    $can = [
        'react' => $me && forum_can('post.react', $node),
        'report' => $me && forum_can('post.report', $node),
        'editAny' => forum_can('mod.editAny', $node),
        'editOwn' => forum_can('post.editOwn', $node) && ! $thread->is_locked,
        'deleteAny' => forum_can('mod.deleteAny', $node),
        'deleteOwn' => forum_can('post.deleteOwn', $node) && ! $thread->is_locked,
        'hardDelete' => forum_can('mod.hardDelete', $node),
        'approve' => forum_can('mod.approve', $node),
        'lock' => forum_can('mod.lock', $node),
        'stick' => forum_can('mod.stick', $node),
        'move' => forum_can('mod.move', $node),
        'merge' => forum_can('mod.merge', $node),
        'warn' => $me && forum_can('mod.warn'),
        'viewFiles' => forum_can('attachment.view', $node),
    ];
    $can['bulk'] = $can['approve'] || $can['deleteAny'] || $can['merge'];
    $warningTypes = $can['warn'] ? \App\Models\WarningType::orderBy('display_order')->get() : collect();
    $canPromote = $me && setting('wallet.enabled') && $thread->isVisible() && ($thread->user_id === $me->id || $me->isAdmin());
    $editMinutes = (int) setting('posting.edit_minutes');
    $hasModTools = $can['lock'] || $can['stick'] || $can['move'] || $can['deleteAny'] || $can['hardDelete'] || ($can['approve'] && $thread->state === 'moderated');
@endphp

@section('content')
    <div class="page-head">
        <div class="titles">
            <h1>
                @if ($thread->prefix)<span class="prefix {{ $thread->prefix->color }}">{{ $thread->prefix->title }}</span>@endif
                {{ $thread->title }}
            </h1>
            <div class="thread-meta" style="margin-top: 6px">
                <x-icon name="user" :size="14" /> <x-username :user="$thread->user" />
                <span>·</span> <x-icon name="clock" :size="14" /> <x-time :at="$thread->created_at" />
                @if ($thread->is_sticky)<span class="badge amber"><x-icon name="pin" :size="12" /> {{ __('Sticky') }}</span>@endif
                @if ($thread->promoted_until?->isFuture())<span class="badge amber"><x-icon name="star" :size="12" /> {{ __('Featured') }}</span>@endif
                @if ($thread->isSponsored())<span class="badge blue">{{ __('Sponsored') }}</span>@endif
                @if ($thread->is_locked)<span class="badge"><x-icon name="lock" :size="12" /> {{ __('Locked') }}</span>@endif
                @if ($thread->state === 'moderated')<span class="badge amber">{{ __('Awaiting approval') }}</span>@endif
                @if ($thread->state === 'deleted')<span class="badge red">{{ __('Deleted') }}</span>@endif
                @if ($thread->isSolved())<a class="badge green" href="{{ route('posts.show', $thread->solution_post_id) }}"><x-icon name="check" :size="12" /> {{ __('Solved') }}</a>@endif
            </div>
            @if ($thread->tags->isNotEmpty())
                <div class="tag-list" style="margin-top: 8px">
                    @foreach ($thread->tags as $tag)<a class="tag" href="{{ route('tags.show', $tag) }}"><x-icon name="tag" :size="12" /> {{ $tag->name }}</a>@endforeach
                </div>
            @endif
        </div>

        <div class="row">
            @auth
                <form method="post" action="{{ route('threads.watch', $thread) }}">@csrf
                    <button class="btn" type="submit"><x-icon name="eye" :size="16" /> {{ $watching ? __('Unwatch') : __('Watch') }}</button>
                </form>
            @endauth

            @if ($canPromote)
                <a class="btn" href="{{ route('threads.promote', $thread) }}"><x-icon name="star" :size="16" /> {{ __('Promote') }}</a>
            @endif

            @if ($hasModTools)
                <details class="menu">
                    <summary class="btn"><x-icon name="shield" :size="16" /> {{ __('Moderation') }} <x-icon name="chevron-down" :size="14" /></summary>
                    <form class="menu-panel" method="post" action="{{ route('threads.moderate', $thread) }}">
                        @csrf
                        @if ($can['approve'] && $thread->state === 'moderated')
                            <button name="action" value="approve"><x-icon name="check" :size="16" /> {{ __('Approve thread') }}</button>
                        @endif
                        @if ($can['lock'])
                            <button name="action" value="{{ $thread->is_locked ? 'unlock' : 'lock' }}"><x-icon name="lock" :size="16" /> {{ $thread->is_locked ? __('Unlock thread') : __('Lock thread') }}</button>
                        @endif
                        @if ($can['stick'])
                            <button name="action" value="{{ $thread->is_sticky ? 'unstick' : 'stick' }}"><x-icon name="pin" :size="16" /> {{ $thread->is_sticky ? __('Unstick thread') : __('Stick thread') }}</button>
                        @endif
                        @if ($can['move'])
                            <button type="button" data-dialog="move-dialog"><x-icon name="folder" :size="16" /> {{ __('Move thread') }}</button>
                        @endif
                        @if ($can['deleteAny'])
                            <div class="menu-sep"></div>
                            @if ($thread->state === 'deleted')
                                <button name="action" value="restore"><x-icon name="check" :size="16" /> {{ __('Restore thread') }}</button>
                            @else
                                <button class="danger" name="action" value="delete" data-confirm="{{ __('Delete this thread?') }}"><x-icon name="trash" :size="16" /> {{ __('Delete thread') }}</button>
                            @endif
                        @endif
                        @if ($can['hardDelete'])
                            <button class="danger" name="action" value="hard_delete" data-confirm="{{ __('Permanently delete this thread and all of its posts? This cannot be undone.') }}"><x-icon name="trash" :size="16" /> {{ __('Delete permanently') }}</button>
                        @endif
                    </form>
                </details>
            @endif

            @if ($canReply)
                <a class="btn btn-primary" href="#reply"><x-icon name="reply" :size="16" /> {{ __('Reply') }}</a>
            @endif
        </div>
    </div>

    {{ $posts->links() }}

    @if ($thread->listing && $posts->currentPage() === 1)
        @include('threads._listing')
    @endif

    @if ($summary || $canSummarize)
        <section class="block ai-summary" id="summary" style="margin-bottom: 12px">
            <div class="block-head">
                <x-icon name="bolt" :size="16" /><h2>{{ __('AI summary') }}</h2>
                @if ($summary)<span class="muted">{{ trans_choice('based on :count post|based on the first :count posts', $summary->post_count, ['count' => forum_number($summary->post_count)]) }} · {{ $summary->updated_at?->translatedFormat('j M Y') }}</span>@endif
                <span class="spacer"></span>
                @if ($canSummarize)
                    <form method="post" action="{{ route('threads.summary', $thread) }}" data-busy="{{ __('Writing the summary…') }}">@csrf
                        <button class="btn btn-sm" type="submit">{{ $summary ? __('Update the summary') : __('Summarise this thread') }}</button>
                    </form>
                @endif
            </div>
            @if ($summary)
                <div class="block-body" style="white-space: pre-line">{{ $summary->body }}</div>
                <div class="block-foot muted small">{{ __('Written by an AI model from the posts in this thread. It can be wrong or incomplete; read the posts before you rely on it.') }}</div>
            @else
                <div class="block-body muted">{{ __('This is a long thread. An AI model can write a short summary of it for you.') }}</div>
            @endif
        </section>
    @endif

    @if ($thread->poll && $posts->currentPage() === 1)
        @php
            $poll = $thread->poll;
            $voted = $pollChoices !== [];
            $canVote = $me && $thread->isVisible() && ! $poll->isClosed() && forum_can('poll.vote', $node);
            $voting = $canVote && (! $voted || ($poll->can_change && request()->boolean('change'))) && ! request()->boolean('results');
            $totalVotes = max(1, $poll->options->sum('vote_count'));
        @endphp
        <section class="block poll" id="poll" style="margin-bottom: 12px">
            <div class="block-head">
                <x-icon name="chart" :size="16" /><h2>{{ $poll->question }}</h2>
                <span class="spacer"></span>
                <span class="muted">{{ trans_choice(':count voter|:count voters', $poll->voter_count, ['count' => forum_number($poll->voter_count)]) }}@if ($poll->isClosed()) · {{ __('closed') }}@elseif ($poll->closes_at) · {{ __('closes :date', ['date' => $poll->closes_at->translatedFormat('j M Y')]) }}@endif</span>
            </div>
            @error('poll')<div class="block-body" style="padding-bottom: 0"><div class="flash error" style="margin: 0">{{ $message }}</div></div>@enderror
            @if ($voting)
                <form method="post" action="{{ route('polls.vote', $thread) }}">
                    @csrf
                    @foreach ($poll->options as $option)
                        <label class="poll-option">
                            <input type="{{ $poll->max_choices > 1 ? 'checkbox' : 'radio' }}" name="options[]" value="{{ $option->id }}" @checked(in_array($option->id, $pollChoices))>
                            <span>{{ $option->text }}</span>
                        </label>
                    @endforeach
                    <div class="block-foot row">
                        <button class="btn btn-primary btn-sm" type="submit">{{ __('Vote') }}</button>
                        @if ($poll->max_choices > 1)<span class="muted small">{{ __('Up to :count options', ['count' => $poll->max_choices]) }}</span>@endif
                        <span class="spacer"></span>
                        <a class="small" href="{{ route('threads.show', [$thread, 'results' => 1]) }}#poll">{{ __('Show results') }}</a>
                    </div>
                </form>
            @else
                @foreach ($poll->options as $option)
                    @php $percent = round($option->vote_count / $totalVotes * 100); @endphp
                    <div @class(['poll-result', 'mine' => in_array($option->id, $pollChoices)])>
                        <div class="line"><span class="text">{{ $option->text }}@if (in_array($option->id, $pollChoices)) <x-icon name="check" :size="13" />@endif</span><span class="muted nowrap">{{ forum_number($option->vote_count) }} · %{{ $percent }}</span></div>
                        <div class="poll-bar"><i style="width: {{ $percent }}%"></i></div>
                    </div>
                @endforeach
                @if ($canVote || ($me && ! $poll->isClosed() && ($can['editAny'] || $thread->user_id === $me->id)))
                    <div class="block-foot row">
                        @if ($canVote && ! $voted)<a class="btn btn-sm btn-primary" href="{{ route('threads.show', $thread) }}#poll">{{ __('Vote') }}</a>@endif
                        @if ($canVote && $voted && $poll->can_change)<a class="btn btn-sm" href="{{ route('threads.show', [$thread, 'change' => 1]) }}#poll">{{ __('Change my vote') }}</a>@endif
                        <span class="spacer"></span>
                        @if ($can['editAny'] || $thread->user_id === $me->id)
                            <form class="row" method="post" action="{{ route('polls.update', $thread) }}">@csrf @method('PUT')
                                <button class="btn btn-sm btn-ghost" name="action" value="close" data-confirm="{{ __('Close this poll?') }}">{{ __('Close poll') }}</button>
                                @if ($can['editAny'])<button class="btn btn-sm btn-ghost btn-danger" name="action" value="delete" data-confirm="{{ __('Remove this poll?') }}">{{ __('Remove poll') }}</button>@endif
                            </form>
                        @endif
                    </div>
                @endif
            @endif
        </section>
    @endif

    @hook('thread.top', ['thread' => $thread, 'node' => $node])

    <div data-thread-posts @if ($posts->currentPage() === $posts->lastPage() && $thread->isVisible()) data-since="{{ url('/threads/'.$thread->getRouteKey().'/since') }}" @endif>
    <div data-post-list>
    @foreach ($posts as $post)
        @php
            $author = $post->user;
            $own = $me && $post->user_id === $me->id;
            $isFirst = $post->id === $thread->first_post_id;
            $canEdit = $can['editAny'] || ($own && $can['editOwn'] && ($editMinutes === 0 || $post->created_at->gt(now()->subMinutes($editMinutes))));
            $canDelete = $isFirst ? $can['deleteAny'] : ($can['deleteAny'] || ($own && $can['deleteOwn']));
            $grouped = $post->reactions->groupBy('reaction_id');
            $mine = $me ? $post->reactions->firstWhere('user_id', $me->id)?->reaction_id : null;
        @endphp
        <article id="post-{{ $post->id }}" @class(['block', 'post', 'is-deleted' => $post->state === 'deleted', 'is-moderated' => $post->state === 'moderated', 'is-solution' => $thread->solution_post_id === $post->id])>
            <header class="post-bar">
                <x-icon name="clock" :size="13" /> <x-time :at="$post->created_at" />
                @if ($post->state === 'moderated')<span class="badge amber">{{ __('Awaiting approval') }}</span>@endif
                @if ($post->state === 'deleted')<span class="badge red">{{ __('Deleted') }}</span>@endif
                <span class="spacer"></span>
                <a href="{{ route('posts.show', $post->id) }}" title="{{ __('Permalink') }}">#{{ $posts->firstItem() + $loop->index }}</a>
                @if ($can['bulk'])<input class="select-box" type="checkbox" name="posts[]" value="{{ $post->id }}" form="post-mod-form" aria-label="{{ __('Select') }}">@endif
            </header>
            @if (in_array($post->user_id, $ignored, true))
                <details class="ignored-post"><summary>{{ __('You are ignoring this member.') }} <span class="link-btn">{{ __('Show the post') }}</span></summary>
            @endif
            <div class="post-cols">
            @php
                // Whether the author's online state may be shown is asked once per member and page.
                $presence ??= [];
                $authorSeen = $author ? ($presence[$author->id] ??= app(\App\Forum\Privacy::class)->showsOnline($me, $author)) : false;
                $authorOnline = $authorSeen && $author->isOnline();
                $authorBanners = $author ? $author->groups->prepend($author->group)->filter()->unique('id')->filter(fn ($group) => filled($group->badge))->sortByDesc('priority') : collect();
            @endphp
            <aside class="post-user">
                <div @class(['post-avatar', 'is-online' => $authorOnline, 'is-offline' => $authorSeen && ! $authorOnline])>
                    <x-avatar :user="$author" size="lg" />
                    @if ($authorSeen)<span class="post-online" title="{{ $authorOnline ? __('Online') : __('Offline') }}"></span>@endif
                </div>
                <div class="post-who">
                    <x-username :user="$author" />
                    @if ($author)
                        @if ($author->custom_title || ! $authorBanners->contains('is_staff', true))
                            <x-rank :user="$author" />
                        @endif
                        @foreach ($authorBanners as $bannerGroup)
                            <x-group-banner :group="$bannerGroup" block />
                        @endforeach
                        @if (! $isFirst && $post->user_id === $thread->user_id)
                            <span class="post-starter" title="{{ __('This member started the thread.') }}"><x-icon name="edit" :size="11" /> {{ __('Thread starter') }}</span>
                        @endif
                        @if ($author->phone_verified_at)
                            <div class="verified" title="{{ __('This member has verified a phone number.') }}"><x-icon name="check" :size="12" /> {{ __('Verified member') }}</div>
                        @endif
                        @if ($authorSeen)
                            <x-presence :user="$author" :online="$authorOnline" />
                        @endif
                    @endif
                </div>
                @if ($author)
                    <div class="post-counts">
                        <div title="{{ __('Posts') }}"><b>{{ forum_number($author->post_count) }}</b><span>{{ __('Posts') }}</span></div>
                        <div title="{{ __('Threads') }}"><b>{{ forum_number($author->thread_count) }}</b><span>{{ __('Threads') }}</span></div>
                        <div title="{{ __('Reaction score') }}"><b>{{ forum_number($author->reaction_score) }}</b><span>{{ __('Reaction pts.') }}</span></div>
                    </div>
                    <dl>
                        <div><dt>{{ __('Joined') }}</dt><dd>{{ $author->created_at->translatedFormat('M Y') }}</dd></div>
                        @if (filled($author->location))<div><dt>{{ __('Location') }}</dt><dd class="truncate">{{ $author->location }}</dd></div>@endif
                        @if ($author->solution_count)<div><dt>{{ __('Solutions') }}</dt><dd>{{ number_format($author->solution_count) }}</dd></div>@endif
                        @foreach ($authorFields[$author->id] ?? [] as [$fieldLabel, $fieldValue])
                            <div><dt>{{ $fieldLabel }}</dt><dd class="truncate">{{ $fieldValue }}</dd></div>
                        @endforeach
                    </dl>
                    @hook('post.author', ['author' => $author, 'post' => $post, 'thread' => $thread])
                @endif
            </aside>

            <div class="post-main">
                @if ($thread->solution_post_id === $post->id)
                    <div class="solution-flag"><x-icon name="check" :size="15" /> {{ __('Solution') }}</div>
                @elseif ($isFirst && $thread->isSolved())
                    <div class="solution-flag"><x-icon name="check" :size="15" /> {{ __('This question has an accepted answer.') }} <a href="{{ route('posts.show', $thread->solution_post_id) }}">{{ __('Go to the solution') }}</a></div>
                @endif
                <div class="post-body bb">{!! app(\App\Forum\Addons\Hooks::class)->filter('post.html', $post->body_html, $post) !!}</div>

                @if ($post->edited_at)
                    <div class="post-edited">{{ __('Last edited') }}: <x-time :at="$post->edited_at" />@if ($post->editor && $post->edited_by !== $post->user_id) · {{ $post->editor->username }}@endif @if ($me && ($can['editAny'] || $own)) · <a href="{{ route('posts.history', $post) }}">{{ __('History') }}</a>@endif</div>
                @endif

                @if ($grouped->isNotEmpty())
                    <div class="reaction-summary">
                        @foreach ($grouped as $reactionId => $items)
                            <span @class(['reaction-chip', 'mine' => $mine === $reactionId]) title="{{ $items->first()->reaction?->name }}">
                                {{ $items->first()->reaction?->emoji }} {{ $items->count() }}
                            </span>
                        @endforeach
                    </div>
                @endif

                @php $extraFiles = $can['viewFiles'] ? $post->attachments->reject(fn ($file) => str_contains($post->body, $file->bbcode())) : collect(); @endphp
                @if ($extraFiles->isNotEmpty())
                    <div class="post-attachments">
                        <h4><x-icon name="paperclip" :size="13" /> {{ __('Attachments') }}</h4>
                        <div class="attach-grid">
                            @foreach ($extraFiles as $file)
                                @if ($file->is_image)
                                    <a class="thumb" href="{{ $file->url() }}" target="_blank" title="{{ $file->filename }}"><img src="{{ $file->url(true) }}" alt="{{ $file->filename }}" loading="lazy"></a>
                                @else
                                    <a class="bb-attach" href="{{ $file->url() }}">{{ $file->filename }} ({{ $file->humanSize() }})</a>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endif

                @if ($author?->signature_html && app(\App\Forum\Permissions\PermissionResolver::class)->can($author, 'signature.use'))
                    <div class="post-sig bb">{!! $author->signature_html !!}</div>
                @endif

                @if ($me)
                    <footer class="post-foot">
                        @if ($post->isVisible() && $can['react'] && ! $own && $reactions->isNotEmpty())
                            <details class="menu">
                                <summary class="btn btn-sm btn-ghost"><x-icon name="smile" :size="15" /> {{ __('React') }}</summary>
                                <form class="menu-panel left up reaction-picker" method="post" action="{{ route('posts.react', $post) }}">
                                    @csrf
                                    @foreach ($reactions as $reaction)
                                        <button name="reaction_id" value="{{ $reaction->id }}" title="{{ $reaction->name }}" aria-label="{{ $reaction->name }}">{{ $reaction->emoji }}</button>
                                    @endforeach
                                </form>
                            </details>
                        @endif
                        @if ($post->isVisible() && $canReply)
                            <button class="btn btn-sm btn-ghost" type="button" data-reply="{{ route('posts.quote', $post) }}" title="{{ __('Reply to this post with a quote') }}"><x-icon name="reply" :size="15" /> {{ __('Reply') }}</button>
                            <button class="btn btn-sm btn-ghost" type="button" data-quote="{{ route('posts.quote', $post) }}"><x-icon name="quote" :size="15" /> {{ __('Quote') }}</button>
                            <button class="btn btn-sm btn-ghost" type="button" data-multiquote="{{ route('posts.quote', $post) }}" title="{{ __('Collect this post to quote it together with others') }}"><x-icon name="plus" :size="14" /> {{ __('Quote') }}</button>
                        @endif
                        @if ($post->isVisible())
                            <form method="post" action="{{ route('posts.bookmark', $post) }}">@csrf
                                <button @class(['btn', 'btn-sm', 'btn-ghost', 'active' => in_array($post->id, $bookmarked)]) type="submit" title="{{ in_array($post->id, $bookmarked) ? __('Remove bookmark') : __('Bookmark') }}"><x-icon name="bookmark" :size="15" /> {{ in_array($post->id, $bookmarked) ? __('Bookmarked') : __('Bookmark') }}</button>
                            </form>
                        @endif
                        @if ($node->allow_solutions && ! $isFirst && $post->isVisible() && ($thread->user_id === $me->id || $can['editAny']))
                            <form method="post" action="{{ route('posts.solution', $post) }}">@csrf
                                <button class="btn btn-sm btn-ghost" type="submit"><x-icon name="check" :size="15" /> {{ $thread->solution_post_id === $post->id ? __('Not the solution') : __('Mark as solution') }}</button>
                            </form>
                        @endif
                        @hook('post.footer', ['post' => $post, 'thread' => $thread])
                        <span class="spacer"></span>
                        @if ($post->isVisible() && $can['report'] && ! $own)
                            <button class="btn btn-sm btn-ghost" type="button" data-dialog="report-dialog" data-action="{{ route('posts.report', $post) }}"><x-icon name="flag" :size="15" /> {{ __('Report') }}</button>
                        @endif
                        @if ($can['warn'] && $author && ! $own && ! $author->isAdmin())
                            <button class="btn btn-sm btn-ghost" type="button" data-dialog="warn-dialog" data-action="{{ route('members.warn', $author) }}" data-fill='@json(['post_id' => $post->id])'><x-icon name="alert" :size="15" /> {{ __('Warn') }}</button>
                        @endif
                        @if ($canEdit)
                            <a class="btn btn-sm btn-ghost" href="{{ route('posts.edit', $post) }}"><x-icon name="edit" :size="15" /> {{ __('Edit') }}</a>
                        @endif
                        @if (! $isFirst && $post->state !== 'visible' && ($can['deleteAny'] || $can['approve'] || $can['hardDelete']))
                            <form class="row" method="post" action="{{ route('posts.moderate', $post) }}">@csrf
                                @if ($post->state === 'moderated' && $can['approve'])
                                    <button class="btn btn-sm" name="action" value="approve"><x-icon name="check" :size="15" /> {{ __('Approve') }}</button>
                                @endif
                                @if ($post->state === 'deleted' && $can['deleteAny'])
                                    <button class="btn btn-sm" name="action" value="restore"><x-icon name="check" :size="15" /> {{ __('Restore') }}</button>
                                @endif
                                @if ($can['hardDelete'])
                                    <button class="btn btn-sm btn-danger" name="action" value="hard_delete" data-confirm="{{ __('Permanently delete this post? This cannot be undone.') }}">{{ __('Delete permanently') }}</button>
                                @endif
                            </form>
                        @endif
                        @if ($canDelete && $post->state !== 'deleted')
                            <form method="post" action="{{ route('posts.destroy', $post) }}" data-confirm="{{ $isFirst ? __('Delete this thread?') : __('Delete this post?') }}">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-ghost btn-danger" type="submit"><x-icon name="trash" :size="15" /> {{ __('Delete') }}</button>
                            </form>
                        @endif
                    </footer>
                @endif
            </div>
            </div>
            @if (in_array($post->user_id, $ignored, true))</details>@endif
        </article>
        @if ($loop->first)
            <x-ad-zone position="thread" />
            @hook('thread.after_first_post', ['thread' => $thread])
        @endif
    @endforeach
    </div>
    <button class="new-posts-bar" type="button" data-new-posts data-one="{{ __('1 new reply — show it') }}" data-many="{{ __(':count new replies — show them') }}" hidden></button>
    <div data-post-pager>{{ $posts->links() }}</div>
    </div>

    @if ($canReply)
        <section class="block" id="reply" style="margin-top: 16px">
            <div class="block-head"><x-icon name="reply" :size="16" /><h2>{{ __('Write a reply') }}</h2><span class="spacer"></span><button class="btn btn-sm" type="button" data-multiquote-insert hidden>{{ __('Insert quotes') }} (<span data-count>0</span>)</button></div>
            <form class="block-body" method="post" action="{{ route('posts.store', $thread) }}" data-live-reply data-failed="{{ __('Your reply could not be sent. Check your connection and try again.') }}">
                @csrf
                <x-editor name="body" :placeholder="__('Write your reply…')" :node="$node" :draft="'reply-'.$thread->id" />
                <div class="form-actions" style="margin-top: 12px">
                    <button class="btn btn-primary" type="submit"><x-icon name="reply" :size="16" /> {{ __('Post reply') }}</button>
                </div>
            </form>
        </section>
    @elseif ($thread->is_locked)
        <div class="block" style="margin-top: 16px"><div class="empty"><x-icon name="lock" /> {{ __('This thread is locked. New replies are not accepted.') }}</div></div>
    @elseif (auth()->guest())
        <div class="block" style="margin-top: 16px">
            <div class="empty">
                {{ __('You must be signed in to reply.') }}
                <div class="row" style="justify-content: center; margin-top: 12px">
                    <a class="btn btn-primary" href="{{ route('login') }}">{{ __('Log in') }}</a>
                    @if (setting('registration.enabled'))<a class="btn" href="{{ route('register') }}">{{ __('Register') }}</a>@endif
                </div>
            </div>
        </div>
    @endif

    @if ($can['bulk'])
        <form id="post-mod-form" class="mod-bar" method="post" action="{{ route('mod.posts', $thread) }}" data-mod-bar hidden>
            @csrf
            <span>{{ __('Selected posts') }}: <b data-count>0</b></span>
            <select class="input" name="action" data-mod-action required>
                @if ($can['approve'])<option value="approve">{{ __('Approve') }}</option>@endif
                @if ($can['deleteAny'])
                    <option value="delete">{{ __('Delete') }}</option>
                    <option value="restore">{{ __('Restore') }}</option>
                @endif
                @if ($can['merge'])
                    <option value="move">{{ __('Move to another thread') }}</option>
                    <option value="split">{{ __('Split into a new thread') }}</option>
                @endif
            </select>
            <span data-for="move" hidden><input class="input" name="target" placeholder="{{ __('Address or number of the thread') }}" style="min-width: 280px"></span>
            <span data-for="split" hidden><input class="input" name="title" minlength="3" maxlength="150" @if (setting('posting.title_case')) data-title-case @endif placeholder="{{ __('Title of the new thread') }}" style="min-width: 280px"></span>
            <button class="btn btn-primary" type="submit">{{ __('Apply') }}</button>
        </form>
    @endif

    @if ($can['warn'])
        <dialog id="warn-dialog">
            <form method="post" action="">
                @csrf
                <input type="hidden" name="post_id" value="">
                <div class="block-head"><h2>{{ __('Warn member') }}</h2></div>
                <div class="block-body">
                    <div class="field">
                        <label for="warn-type">{{ __('Warning') }}</label>
                        <select class="input" id="warn-type" name="warning_type_id" required>
                            @foreach ($warningTypes as $type)
                                <option value="{{ $type->id }}">{{ $type->title }} (+{{ $type->points }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="warn-note">{{ __('Note for the moderators') }} <span class="muted">({{ __('optional') }})</span></label>
                        <textarea class="input" id="warn-note" name="note" maxlength="500" style="min-height: 70px"></textarea>
                    </div>
                    <div class="form-actions">
                        <button class="btn btn-primary" type="submit">{{ __('Warn member') }}</button>
                        <button class="btn" type="button" data-dialog-close>{{ __('Cancel') }}</button>
                    </div>
                </div>
            </form>
        </dialog>
    @endif

    @if ($can['report'])
        <dialog id="report-dialog">
            <form method="post" action="">
                @csrf
                <div class="block-head"><h2>{{ __('Report post') }}</h2></div>
                <div class="block-body">
                    @php $reportReasons = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) setting('reports.reasons'))))); @endphp
                    @if ($reportReasons)
                        <div class="field">
                            <label for="report-type">{{ __('Why are you reporting this post?') }}</label>
                            <select class="input" id="report-type" name="reason_type" required>
                                <option value="">{{ __('Choose a reason…') }}</option>
                                @foreach ($reportReasons as $reportReason)
                                    <option value="{{ $reportReason }}">{{ $reportReason }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field">
                            <label for="report-reason">{{ __('Details') }} <span class="muted">({{ __('optional') }})</span></label>
                            <textarea class="input" id="report-reason" name="reason" minlength="5" maxlength="1000"></textarea>
                        </div>
                    @else
                        <div class="field">
                            <label for="report-reason">{{ __('Why are you reporting this post?') }}</label>
                            <textarea class="input" id="report-reason" name="reason" minlength="5" maxlength="1000" required></textarea>
                        </div>
                    @endif
                    <div class="form-actions">
                        <button class="btn btn-primary" type="submit">{{ __('Send report') }}</button>
                        <button class="btn" type="button" data-dialog-close>{{ __('Cancel') }}</button>
                    </div>
                </div>
            </form>
        </dialog>
    @endif

    @if ($can['move'])
        <dialog id="move-dialog">
            <form method="post" action="{{ route('threads.moderate', $thread) }}">
                @csrf
                <input type="hidden" name="action" value="move">
                <div class="block-head"><h2>{{ __('Move thread') }}</h2></div>
                <div class="block-body">
                    <div class="field">
                        <label for="move-node">{{ __('Destination forum') }}</label>
                        <select class="input" id="move-node" name="node_id" required>
                            @foreach ($moveTargets as $item)
                                <option value="{{ $item['node']->id }}" @selected($item['node']->id === $node->id)>{{ str_repeat('— ', $item['depth']) }}{{ $item['node']->title }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-actions">
                        <button class="btn btn-primary" type="submit">{{ __('Move thread') }}</button>
                        <button class="btn" type="button" data-dialog-close>{{ __('Cancel') }}</button>
                    </div>
                </div>
            </form>
        </dialog>
    @endif

    @if ($similar->isNotEmpty())
        <section class="block" style="margin-top: 16px">
            <div class="block-head"><x-icon name="list" :size="16" /><h2>{{ __('Similar threads') }}</h2></div>
            <ul class="side-list">
                @foreach ($similar as $other)
                    <li>
                        <div class="meta" style="flex: 1; min-width: 0">
                            <a class="thread-title" href="{{ route('threads.show', $other) }}">{{ $other->title }}</a>
                            <div class="muted small">{{ $other->node?->title }} · {{ trans_choice(':count reply|:count replies', $other->reply_count, ['count' => forum_number($other->reply_count)]) }} · <x-time :at="$other->last_post_at" /></div>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
@endsection
