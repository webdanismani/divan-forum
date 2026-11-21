<?php

namespace App\Http\Controllers;

use App\Forum\BbCode\Renderer;
use App\Forum\Community;
use App\Forum\Inbox;
use App\Forum\Privacy;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\User;
use App\Notifications\ForumAlert;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConversationController extends Controller
{
    public function __construct(protected Renderer $renderer) {}

    public function index(Request $request)
    {
        $search = mb_substr(trim((string) $request->query('q')), 0, 60);
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';

        $conversations = Conversation::query()
            ->select('conversations.*', 'p.last_read_at')
            ->join('conversation_participants as p', 'p.conversation_id', '=', 'conversations.id')
            ->where('p.user_id', $request->user()->id)
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('conversations.title', 'like', $like)
                ->orWhereExists(fn ($m) => $m->selectRaw('1')->from('conversation_messages')->whereColumn('conversation_messages.conversation_id', 'conversations.id')->where('conversation_messages.body', 'like', $like))
                ->orWhereExists(fn ($m) => $m->selectRaw('1')->from('conversation_participants as op')->join('users as ou', 'ou.id', '=', 'op.user_id')->whereColumn('op.conversation_id', 'conversations.id')->where('ou.id', '!=', $request->user()->id)->where('ou.username', 'like', $like))))
            ->with(['participants.group', 'lastMessageUser'])
            ->orderByDesc('conversations.last_message_at')
            ->paginate(20)->withQueryString();

        $inbox = app(Inbox::class);

        return view('conversations.index', compact('conversations', 'search') + [
            'quota' => $inbox->quota($request->user()),
            'used' => $inbox->used($request->user()),
            'percent' => $inbox->percent($request->user()),
        ]);
    }

    /** The latest conversations with a line of the last message, for the pop-over in the header. */
    public function panel(Request $request)
    {
        $conversations = Conversation::query()
            ->select('conversations.*', 'p.last_read_at')
            ->join('conversation_participants as p', 'p.conversation_id', '=', 'conversations.id')
            ->where('p.user_id', $request->user()->id)
            ->with(['participants', 'lastMessageUser'])
            ->orderByDesc('conversations.last_message_at')
            ->limit(6)->get();

        $last = ConversationMessage::whereIn('id', ConversationMessage::selectRaw('max(id)')->whereIn('conversation_id', $conversations->pluck('id'))->groupBy('conversation_id'))
            ->get(['id', 'conversation_id', 'user_id', 'body'])
            ->each(fn (ConversationMessage $message) => $message->snippet = $this->renderer->plain($message->body, 90))
            ->keyBy('conversation_id');

        $inbox = app(Inbox::class);

        return response()->view('conversations._panel', compact('conversations', 'last') + [
            'quota' => $inbox->quota($request->user()),
            'used' => $inbox->used($request->user()),
            'percent' => $inbox->percent($request->user()),
        ])->header('Cache-Control', 'no-store');
    }

    public function create(Request $request)
    {
        forum_authorize('conversation.start');

        return view('conversations.create', ['to' => (string) $request->query('to', ''), 'full' => app(Inbox::class)->isFull($request->user())]);
    }

    public function store(Request $request)
    {
        forum_authorize('conversation.start');
        $sender = $request->user();

        $data = $request->validate([
            'recipients' => ['required', 'string', 'max:400'],
            'title' => ['required', 'string', 'min:3', 'max:150'],
            'body' => ['required', 'string', 'min:2', 'max:20000'],
        ]);

        $names = collect(explode(',', $data['recipients']))
            ->map(fn ($name) => trim($name))
            ->filter()
            ->reject(fn ($name) => $name === $sender->username)
            ->unique()
            ->take(10);

        $recipients = User::whereIn('username', $names)->get()->reject(fn (User $u) => $u->isBanned());

        $community = app(Community::class);
        if (! $sender->group?->is_staff && $recipients->contains(fn (User $u) => $community->ignores($u, $sender->id))) {
            throw ValidationException::withMessages(['recipients' => __('One of these members does not accept messages from you.')]);
        }

        // Members may limit who can start a conversation with them.
        $privacy = app(Privacy::class);
        if ($recipients->contains(fn (User $u) => ! $privacy->canMessage($sender, $u))) {
            throw ValidationException::withMessages(['recipients' => __('One of these members does not accept messages from you.')]);
        }

        if ($names->isEmpty() || $recipients->count() !== $names->count()) {
            throw ValidationException::withMessages(['recipients' => __('One or more recipients could not be found.')]);
        }

        // Inbox quota: a full inbox neither starts nor receives new conversations. Messages from the staff always arrive.
        $inbox = app(Inbox::class);
        if ($inbox->isFull($sender)) {
            throw ValidationException::withMessages(['recipients' => __('Your inbox is full. Leave old conversations to make room before starting a new one.')]);
        }
        if (! $sender->group?->is_staff && ! $sender->isAdmin() && ($full = $recipients->first(fn (User $u) => $inbox->isFull($u)))) {
            $inbox->warnFull($full, $sender);
            throw ValidationException::withMessages(['recipients' => __('The inbox of :name is full, so they cannot receive new conversations at the moment.', ['name' => $full->username])]);
        }

        $conversation = DB::transaction(function () use ($data, $sender, $recipients) {
            $conversation = Conversation::create(['title' => $data['title'], 'user_id' => $sender->id]);
            $conversation->participants()->attach($sender->id);
            $conversation->participants()->attach($recipients->pluck('id'));
            $this->addMessage($conversation, $sender, $data['body']);

            return $conversation;
        });

        return redirect()->route('conversations.show', $conversation);
    }

    public function show(Request $request, Conversation $conversation)
    {
        $this->participantOrFail($conversation, $request->user());

        $messages = $conversation->messages()->with('user.group')->orderBy('id')->paginate((int) setting('board.posts_per_page'));

        $this->markRead($conversation, $request->user());

        $conversation->load('participants.group');
        // "Seen": the others who opened the conversation after the member's own last message.
        $mine = $conversation->messages()->where('user_id', $request->user()->id)->latest('id')->first();
        $seenBy = $mine ? $conversation->participants->filter(fn (User $p) => $p->id !== $request->user()->id && $p->pivot->last_read_at && $p->pivot->last_read_at >= $mine->created_at)->values() : collect();

        return view('conversations.show', ['conversation' => $conversation, 'messages' => $messages, 'seenAfter' => $mine?->id, 'seenBy' => $seenBy]);
    }

    public function reply(Request $request, Conversation $conversation)
    {
        $this->participantOrFail($conversation, $request->user());
        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:20000']]);

        $this->addMessage($conversation, $request->user(), $data['body']);
        $lastPage = (int) ceil($conversation->message_count / (int) setting('board.posts_per_page'));

        return redirect()->route('conversations.show', ['conversation' => $conversation] + ($lastPage > 1 ? ['page' => $lastPage] : []));
    }

    /** Back to "new": the conversation shows as unread again. */
    public function unread(Request $request, Conversation $conversation)
    {
        $this->participantOrFail($conversation, $request->user());
        DB::table('conversation_participants')->where('conversation_id', $conversation->id)->where('user_id', $request->user()->id)->update(['last_read_at' => null]);

        return redirect()->route('conversations.index')->with('success', __('The conversation has been marked as unread.'));
    }

    /** One action for all the ticked conversations of the list. */
    public function bulk(Request $request)
    {
        $data = $request->validate([
            'action' => ['required', 'in:read,unread,leave'],
            'ids' => ['required', 'array', 'max:100'],
            'ids.*' => ['integer'],
        ], ['ids.required' => __('Tick at least one conversation first.')]);

        $user = $request->user();
        $mine = Conversation::whereIn('id', $data['ids'])->whereHas('participants', fn ($q) => $q->where('users.id', $user->id))->get();

        foreach ($mine as $conversation) {
            if ($data['action'] === 'leave') {
                $conversation->participants()->detach($user->id);
                if (! $conversation->participants()->exists()) {
                    $conversation->delete();
                }
            } elseif ($data['action'] === 'read') {
                $this->markRead($conversation, $user);
            } else {
                DB::table('conversation_participants')->where('conversation_id', $conversation->id)->where('user_id', $user->id)->update(['last_read_at' => null]);
            }
        }

        return back()->with('success', match ($data['action']) {
            'leave' => __('You have left :count conversations.', ['count' => $mine->count()]),
            'read' => __(':count conversations were marked as read.', ['count' => $mine->count()]),
            default => __(':count conversations were marked as unread.', ['count' => $mine->count()]),
        });
    }

    public function leave(Request $request, Conversation $conversation)
    {
        $this->participantOrFail($conversation, $request->user());
        $conversation->participants()->detach($request->user()->id);

        if (! $conversation->participants()->exists()) {
            $conversation->delete();
        }

        return redirect()->route('conversations.index')->with('success', __('You have left the conversation.'));
    }

    protected function participantOrFail(Conversation $conversation, User $user): void
    {
        abort_unless($conversation->participants()->where('users.id', $user->id)->exists(), 404);
    }

    protected function markRead(Conversation $conversation, User $user): void
    {
        DB::table('conversation_participants')
            ->where('conversation_id', $conversation->id)->where('user_id', $user->id)
            ->update(['last_read_at' => now()->addSecond()]);

        $user->unreadNotifications()
            ->where('data', 'like', '%"conversation_id":'.$conversation->id.'}%')
            ->update(['read_at' => now()]);
    }

    protected function addMessage(Conversation $conversation, User $sender, string $body): void
    {
        $conversation->messages()->create([
            'user_id' => $sender->id,
            'body' => $body,
            'body_html' => $this->renderer->render($body),
        ]);

        $conversation->update([
            'message_count' => $conversation->messages()->count(),
            'last_message_at' => now(),
            'last_message_user_id' => $sender->id,
        ]);

        $this->markRead($conversation, $sender);

        foreach ($conversation->participants()->where('users.id', '!=', $sender->id)->get() as $participant) {
            $pending = $participant->unreadNotifications()
                ->where('data', 'like', '%"conversation_id":'.$conversation->id.'}%')
                ->exists();

            if (! $pending) {
                $participant->notify(new ForumAlert('conversation', $sender, route('conversations.show', $conversation), [
                    'title' => $conversation->title,
                    'conversation_id' => $conversation->id,
                ]));
            }
        }
    }
}
