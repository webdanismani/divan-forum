<?php

namespace App\Http\Controllers\Admin;

use App\Forum\BbCode\Renderer;
use App\Forum\Mailing;
use App\Forum\Site;
use App\Http\Controllers\Controller;
use App\Mail\AlertMail;
use App\Models\ContactMessage;
use App\Models\NavLink;
use App\Models\Notice;
use App\Models\Page;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Pages, notices, extra menu links, contact messages and announcement e-mails. */
class ContentController extends Controller
{
    public function __construct(protected Site $site) {}

    // ---- Pages -------------------------------------------------------------------

    public function pages()
    {
        return view('admin.content.pages', ['pages' => Page::orderBy('display_order')->get()]);
    }

    public function editPage(?Page $page = null)
    {
        return view('admin.content.page', ['page' => $page ?? new Page(['is_published' => true, 'show_in_footer' => true])]);
    }

    public function savePage(Request $request, Renderer $renderer, ?Page $page = null)
    {
        $page ??= new Page;

        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'slug' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9-]+$/', Rule::unique('pages', 'slug')->ignore($page->id)],
            'body' => ['required', 'string', 'max:100000'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        $page->fill([
            'title' => $data['title'],
            'slug' => filled($data['slug'] ?? null) ? $data['slug'] : ($page->slug ?: $this->freeSlug($data['title'])),
            'body' => $data['body'],
            'body_html' => $renderer->render($data['body']),
            'display_order' => $data['display_order'] ?? 0,
            'is_published' => $request->boolean('is_published'),
            'show_in_footer' => $request->boolean('show_in_footer'),
        ])->save();
        $this->site->flush();

        return redirect()->route('admin.pages')->with('success', __('The page has been saved.'));
    }

    public function deletePage(Page $page)
    {
        $page->delete();
        $this->site->flush();

        return back()->with('success', __('The page has been deleted.'));
    }

    // ---- Notices and menu links ---------------------------------------------------------

    public function notices()
    {
        return view('admin.content.notices', ['notices' => Notice::orderBy('display_order')->get(), 'links' => NavLink::orderBy('position')->orderBy('display_order')->get()]);
    }

    public function saveNotice(Request $request, Renderer $renderer)
    {
        $data = $request->validate([
            'id' => ['nullable', Rule::exists('notices', 'id')],
            'title' => ['nullable', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:2000'],
            'style' => ['required', Rule::in(['info', 'success', 'warning'])],
            'audience' => ['required', Rule::in(['all', 'guests', 'members'])],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        Notice::updateOrCreate(['id' => $data['id'] ?? null], [
            'title' => $data['title'] ?? null,
            'body' => $data['body'],
            'body_html' => $renderer->render($data['body']),
            'style' => $data['style'],
            'audience' => $data['audience'],
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'display_order' => $data['display_order'] ?? 0,
            'is_dismissible' => $request->boolean('is_dismissible'),
            'is_active' => $request->boolean('is_active'),
        ]);
        $this->site->flush();

        return back()->with('success', __('The notice has been saved.'));
    }

    public function deleteNotice(Notice $notice)
    {
        $notice->delete();
        $this->site->flush();

        return back()->with('success', __('The notice has been deleted.'));
    }

    public function saveLink(Request $request)
    {
        $data = $request->validate([
            'id' => ['nullable', Rule::exists('nav_links', 'id')],
            'title' => ['required', 'string', 'max:60'],
            // An address on this site ("/pages/kurallar") or a full http(s) address.
            'url' => ['required', 'string', 'max:255', 'regex:~^(/[^/\s][^\s]*|/|https?://[^\s]+)$~'],
            'position' => ['required', Rule::in(['header', 'footer'])],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);
        $data['display_order'] ??= 0;
        $data['new_tab'] = $request->boolean('new_tab');
        $data['is_active'] = $request->boolean('is_active');

        NavLink::updateOrCreate(['id' => $data['id'] ?? null], collect($data)->except('id')->all());
        $this->site->flush();

        return back()->with('success', __('The link has been saved.'));
    }

    public function deleteLink(NavLink $link)
    {
        $link->delete();
        $this->site->flush();

        return back()->with('success', __('The link has been deleted.'));
    }

    // ---- Contact messages ------------------------------------------------------------------

    public function messages()
    {
        $messages = ContactMessage::latest('id')->paginate(30);
        ContactMessage::whereIn('id', $messages->pluck('id'))->where('is_read', false)->update(['is_read' => true]);

        return view('admin.content.messages', compact('messages'));
    }

    public function deleteMessage(ContactMessage $message)
    {
        $message->delete();

        return back()->with('success', __('The message has been deleted.'));
    }

    // ---- Announcement e-mails --------------------------------------------------------------------

    public function mail()
    {
        return view('admin.content.mail', [
            'groups' => UserGroup::where(fn ($q) => $q->whereNull('system_key')->orWhere('system_key', '!=', 'guest'))->orderBy('priority')->get(),
            'enabled' => Mailing::enabled(),
            'recipients' => $this->recipients(null)->count(),
        ]);
    }

    public function sendMail(Request $request)
    {
        abort_unless(Mailing::enabled(), 403, __('Outgoing mail is switched off. Choose SMTP and save the settings first.'));

        $data = $request->validate([
            'subject' => ['required', 'string', 'min:3', 'max:150'],
            'body' => ['required', 'string', 'min:10', 'max:10000'],
            'group_id' => ['nullable', Rule::exists('user_groups', 'id')],
            'test' => ['nullable'],
        ]);

        $lines = array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/', str_replace("\r", '', $data['body'])))));
        $make = fn (User $user) => new AlertMail($data['subject'], __('Hello :name,', ['name' => $user->username]), $lines, (string) setting('board.name'), route('forum.index'));

        if ($request->boolean('test')) {
            Mail::to($request->user()->email)->send($make($request->user()));

            return back()->withInput()->with('success', __('A test copy has been sent to :email.', ['email' => $request->user()->email]));
        }

        $count = 0;
        $this->recipients($data['group_id'] ?? null)->chunkById(500, function ($users) use ($make, &$count) {
            foreach ($users as $user) {
                Mail::to($user->email)->queue($make($user));
                $count++;
            }
        });

        return redirect()->route('admin.mail')->with('success', __('The message has been queued for :count members and will go out in the background.', ['count' => $count]));
    }

    // ---------------------------------------------------------------------

    /** Members who may be written to: confirmed address, not banned, announcements not switched off. */
    protected function recipients(?int $groupId)
    {
        return User::query()
            ->whereNotNull('email_verified_at')
            ->where('is_banned', false)
            ->where('email_announcements', true)
            ->when($groupId, fn ($q) => $q->where(fn ($q) => $q->where('user_group_id', $groupId)->orWhereHas('groups', fn ($g) => $g->where('user_groups.id', $groupId))));
    }

    protected function freeSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'sayfa';
        $slug = $base;
        for ($i = 2; Page::where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
