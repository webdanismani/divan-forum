<?php

namespace App\Http\Controllers;

use App\Forum\Community;
use App\Forum\Moderation\Censor;
use App\Forum\Moderation\SpamCleaner;
use App\Forum\NodeTree;
use App\Forum\Privacy;
use App\Forum\Rewards;
use App\Models\Post;
use App\Models\ProfileField;
use App\Models\ProfilePost;
use App\Models\User;
use App\Notifications\ForumAlert;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MemberController extends Controller
{
    public function __construct(protected Community $community) {}

    public function index(Request $request)
    {
        forum_authorize('profile.view');

        $sort = in_array($request->query('sort'), ['posts', 'newest', 'reactions', 'online'], true) ? $request->query('sort') : 'posts';
        $search = trim((string) $request->query('q'));

        $members = User::with('group')
            ->when($search !== '', fn ($q) => $q->where('username', 'like', addcslashes($search, '%_\\').'%'))
            ->when($sort === 'posts', fn ($q) => $q->orderByDesc('post_count'))
            ->when($sort === 'newest', fn ($q) => $q->orderByDesc('id'))
            ->when($sort === 'reactions', fn ($q) => $q->orderByDesc('reaction_score'))
            ->when($sort === 'online', fn ($q) => $q->whereNotNull('last_seen_at')->orderByDesc('last_seen_at'))
            ->orderBy('id')
            ->paginate(30)
            ->withQueryString();

        return view('members.index', compact('members', 'sort', 'search'));
    }

    public function show(Request $request, User $user, Rewards $rewards)
    {
        forum_authorize('profile.view');
        $viewer = $request->user();
        $privacy = app(Privacy::class);
        $friendship = ['friendship' => $privacy->status($viewer, $user), 'canFriend' => $viewer && $privacy->canRequest($viewer, $user)];

        // The member decides who sees the profile; everybody else gets the name and a way to ask.
        if (! $privacy->canViewProfile($viewer, $user)) {
            return response()->view('members.private', ['member' => $user->load('group'), 'level' => $privacy->choice($user, 'profile')] + $friendship, 403);
        }

        $posts = Post::with('thread')
            ->where('posts.user_id', $user->id)
            ->where('posts.state', 'visible')
            ->whereHas('thread', fn ($q) => $q
                ->where('state', 'visible')
                ->whereIn('node_id', app(NodeTree::class)->viewableIds($viewer)))
            ->latest('id')
            ->limit(10)
            ->get();

        $values = DB::table('profile_field_values')->where('user_id', $user->id)->pluck('value', 'profile_field_id');

        return view('members.show', [
            'member' => $user->load('group', 'groups'),
            'posts' => $posts,
            'badges' => $rewards->badgesOf($user),
            'fields' => ProfileField::where('is_active', true)->orderBy('display_order')->get()
                ->map(fn (ProfileField $field) => [$field, $values[$field->id] ?? null])->filter(fn ($pair) => filled($pair[1]))->values(),
            'wall' => ProfilePost::with('author.group')->where('profile_user_id', $user->id)->latest('id')->paginate(10, ['*'], 'wall'),
            'following' => $this->community->follows($viewer, $user->id),
            'showOnline' => $privacy->showsOnline($viewer, $user),
            'canWrite' => $privacy->canWriteOnWall($viewer, $user),
            'canMessage' => $privacy->canMessage($viewer, $user),
            'friends' => $privacy->friendsOf($user, 12),
            'friendCount' => count($privacy->friendIds($user)),
            ...$friendship,
            'ignoring' => $this->community->ignores($viewer, $user->id),
        ]);
    }

    /** A note about a member that only the staff reads. */
    public function storeNote(Request $request, User $user)
    {
        abort_unless($request->user()->group?->is_staff || $request->user()->isAdmin(), 403);
        $data = $request->validate(['body' => ['required', 'string', 'min:3', 'max:1000']]);
        DB::table('user_notes')->insert(['user_id' => $user->id, 'author_id' => $request->user()->id, 'body' => $data['body'], 'created_at' => now(), 'updated_at' => now()]);

        return redirect()->to(route('members.show', $user).'#staff-notes')->with('success', __('The note has been added.'));
    }

    public function destroyNote(Request $request, int $note)
    {
        $row = DB::table('user_notes')->find($note);
        abort_unless($row, 404);
        abort_unless($request->user()->isAdmin() || ((int) $row->author_id === $request->user()->id && $request->user()->group?->is_staff), 403);
        DB::table('user_notes')->where('id', $note)->delete();

        return back()->with('success', __('The note has been deleted.'));
    }

    /** Names for the "@" list in the editor: members whose name starts with what was typed. */
    public function suggest(Request $request)
    {
        $term = mb_substr(trim((string) $request->query('q')), 0, 30);
        if (mb_strlen($term) < 2) {
            return response()->json(['members' => []]);
        }

        $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        return response()->json(['members' => User::with('group')->where('username', 'like', $like)->where('is_banned', false)
            ->orderByDesc('last_seen_at')->limit(8)->get()
            ->map(fn (User $member) => ['name' => $member->username, 'title' => $member->displayTitle(), 'avatar' => $member->avatarUrl(), 'initial' => $member->initial(), 'hue' => $member->avatarHue()])
            ->all()])->header('Cache-Control', 'private, max-age=30');
    }

    /** The small card shown when the pointer rests on a member's name. */
    public function card(Request $request, User $user)
    {
        abort_unless(forum_can('profile.view'), 403);
        $privacy = app(Privacy::class);
        $viewer = $request->user();
        $showOnline = $privacy->showsOnline($viewer, $user);

        return response()->view('members._card', [
            'member' => $user->load('group'),
            'visible' => $privacy->canViewProfile($viewer, $user),
            'online' => $showOnline && $user->isOnline(),
            'showSeen' => $showOnline,
            'canMessage' => $privacy->canMessage($viewer, $user),
        ])->header('Cache-Control', 'private, max-age=60');
    }

    /** Send or accept a friend request ("add"), or withdraw, decline or end one ("remove"). */
    public function friend(Request $request, User $user)
    {
        $me = $request->user();
        $privacy = app(Privacy::class);
        abort_if($user->is($me), 422);
        $action = $request->validate(['action' => ['required', 'in:add,remove']])['action'];
        $before = $privacy->status($me, $user);

        if ($action === 'remove') {
            $privacy->remove($me, $user);

            return back()->with('success', $before === 'friends' ? __('You are no longer friends.') : __('The friend request has been removed.'));
        }

        // Accepting a waiting request is always possible; a new one needs both sides to be allowed.
        abort_unless($before === 'received' || $privacy->canRequest($me, $user), 403, __('This member does not accept friend requests.'));
        abort_if($this->community->ignores($user, $me->id), 403, __('This member does not accept friend requests.'));

        $after = $privacy->request($me, $user);
        if ($after === 'sent' && $before === 'none') {
            $user->notify(new ForumAlert('friend_request', $me, route('friends.index')));
        } elseif ($after === 'friends' && $before === 'received') {
            $user->notify(new ForumAlert('friend_accept', $me, route('members.show', $me)));
        }

        return back()->with('success', $after === 'friends' ? __('You are now friends.') : __('Your friend request has been sent.'));
    }

    public function friends(Request $request)
    {
        $privacy = app(Privacy::class);

        return view('members.friends', [
            'friends' => $privacy->friendsOf($request->user()),
            'incoming' => $privacy->incoming($request->user()),
            'outgoing' => $privacy->outgoing($request->user()),
        ]);
    }

    public function follow(Request $request, User $user)
    {
        abort_if($user->is($request->user()), 422);
        abort_if($this->community->ignores($user, $request->user()->id), 403);

        $following = $this->community->toggleFollow($request->user(), $user);

        return back()->with('success', $following ? __('You are now following :name.', ['name' => $user->username]) : __('You no longer follow :name.', ['name' => $user->username]));
    }

    public function ignore(Request $request, User $user)
    {
        abort_if($user->is($request->user()), 422);
        abort_if($user->group?->is_staff, 403, __('Staff members cannot be ignored.'));

        $ignoring = $this->community->toggleIgnore($request->user(), $user);

        return back()->with('success', $ignoring ? __('You are now ignoring :name.', ['name' => $user->username]) : __('You no longer ignore :name.', ['name' => $user->username]));
    }

    /** A short public message on somebody's profile. */
    public function storeProfilePost(Request $request, User $user)
    {
        forum_authorize('profile.post');
        abort_if($user->isBanned() || $this->community->ignores($user, $request->user()->id), 403);
        abort_unless(app(Privacy::class)->canWriteOnWall($request->user(), $user), 403, __('This member limits who can write on their profile.'));

        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:1000']]);
        $recent = ProfilePost::where('user_id', $request->user()->id)->where('created_at', '>', now()->subSeconds(20))->exists();
        abort_if($recent, 429, __('Please wait a moment before posting again.'));

        ProfilePost::create(['profile_user_id' => $user->id, 'user_id' => $request->user()->id, 'body' => app(Censor::class)->clean($data['body'])]);

        if (! $user->is($request->user())) {
            $user->notify(new ForumAlert('profile_post', $request->user(), route('members.show', $user)));
        }

        return back()->with('success', __('Your message has been posted.'));
    }

    /** Ban a spammer and take their content off the board in one step. */
    public function spamClean(Request $request, User $user, SpamCleaner $cleaner)
    {
        forum_authorize('mod.spamClean');
        abort_if($user->is($request->user()) || $user->group?->is_staff || $user->isAdmin(), 403, __('Staff members cannot be cleaned as spammers.'));

        $done = $cleaner->clean($user, $request->user(), $request->boolean('ban_addresses'), $request->ip());

        return back()->with('success', __('The member was banned; :threads threads and :posts posts were removed.', ['threads' => $done['threads'], 'posts' => $done['posts']]));
    }

    public function destroyProfilePost(Request $request, ProfilePost $profilePost)
    {
        $me = $request->user();
        abort_unless($profilePost->user_id === $me->id || $profilePost->profile_user_id === $me->id || forum_can('mod.deleteAny'), 403);

        $profilePost->delete();

        return back()->with('success', __('The message has been deleted.'));
    }
}
