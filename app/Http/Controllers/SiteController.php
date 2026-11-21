<?php

namespace App\Http\Controllers;

use App\Forum\Addons\Hooks;
use App\Forum\BbCode\Renderer;
use App\Forum\NodeTree;
use App\Forum\Spam\Guard;
use App\Http\Controllers\Concerns\ChecksVisibility;
use App\Models\ContactMessage;
use App\Models\Node;
use App\Models\Page;
use App\Models\Tag;
use App\Models\Thread;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** Pages, the contact form, and what search engines and feed readers ask for. */
class SiteController extends Controller
{
    use ChecksVisibility;

    public function page(Page $page)
    {
        abort_unless($page->is_published || auth()->user()?->isAdmin(), 404);

        return view('pages.show', ['page' => $page]);
    }

    public function contact()
    {
        return view('pages.contact');
    }

    public function sendContact(Request $request, Guard $guard)
    {
        $guard->check($request);
        $user = $request->user();

        $data = $request->validate([
            'name' => [$user ? 'nullable' : 'required', 'string', 'max:80'],
            'email' => [$user ? 'nullable' : 'required', 'email', 'max:255'],
            'subject' => ['required', 'string', 'min:3', 'max:150'],
            'body' => ['required', 'string', 'min:10', 'max:5000'],
        ]);

        ContactMessage::create([
            'user_id' => $user?->id,
            'name' => $user?->username ?? $data['name'],
            'email' => $user?->email ?? $data['email'],
            'subject' => $data['subject'],
            'body' => $data['body'],
            'ip' => $request->ip(),
        ]);

        return redirect()->route('contact')->with('success', __('Thank you, your message has been sent to the administrators.'));
    }

    public function robots()
    {
        $lines = ['User-agent: *', 'Disallow: /admin', 'Disallow: /search', 'Disallow: /login', 'Disallow: /register', 'Disallow: /account', 'Disallow: /ads/', '', 'Sitemap: '.route('sitemap')];
        $lines = (array) app(Hooks::class)->filter('robots.lines', $lines);

        return response(implode('
', $lines).'
')->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    /** Everything a guest can read, for search engines. */
    public function sitemap(NodeTree $tree)
    {
        $xml = Cache::remember('forum.sitemap', 1800, function () use ($tree) {
            $nodes = $tree->viewable(null);
            $urls = [[route('forum.index'), null]];

            foreach ($nodes as $node) {
                if (! $node->isLink()) {
                    $urls[] = [route('nodes.show', $node), $node->last_post_at];
                }
            }
            foreach (Page::where('is_published', true)->get() as $page) {
                $urls[] = [route('pages.show', $page), $page->updated_at];
            }
            foreach (Thread::whereIn('node_id', $nodes->keys())->where('state', 'visible')->orderByDesc('last_post_at')->limit(5000)->get(['id', 'title', 'last_post_at']) as $thread) {
                $urls[] = [route('threads.show', $thread), $thread->last_post_at];
            }
            if ((int) setting('posting.max_tags') > 0) {
                $open = fn ($q) => $q->whereIn('node_id', $nodes->keys())->where('state', 'visible');
                foreach (Tag::whereHas('threads', $open)->orderByDesc('use_count')->limit(300)->get() as $tag) {
                    $urls[] = [route('tags.show', $tag), null];
                }
            }

            $out = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
            foreach ($urls as [$location, $modified]) {
                $out .= '  <url><loc>'.e($location).'</loc>'.($modified ? '<lastmod>'.$modified->toAtomString().'</lastmod>' : '').'</url>'."\n";
            }

            return $out.'</urlset>'."\n";
        });

        return response($xml)->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    /** RSS 2.0 of the newest threads guests can read, for the whole board or one forum. */
    public function feed(NodeTree $tree, Renderer $renderer, ?Node $node = null)
    {
        $viewable = $tree->viewable(null);
        if ($node) {
            abort_unless($viewable->has($node->id) && $node->isForum(), 404);
        }

        $threads = Thread::with(['firstPost', 'user', 'node'])
            ->whereIn('node_id', $node ? [$node->id] : $viewable->keys())
            ->where('state', 'visible')
            ->latest('id')->limit(30)->get();

        $title = ($node ? $node->title.' - ' : '').setting('board.name');
        $link = $node ? route('nodes.show', $node) : route('forum.index');

        $out = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<rss version="2.0" xmlns:dc="http://purl.org/dc/elements/1.1/"><channel>'."\n"
            .'<title>'.e($title).'</title><link>'.e($link).'</link><description>'.e((string) ($node?->description ?: setting('board.description'))).'</description>'
            .'<language>'.e(app()->getLocale()).'</language>'."\n";

        foreach ($threads as $thread) {
            $out .= '<item><title>'.e($thread->title).'</title><link>'.e(route('threads.show', $thread)).'</link>'
                .'<guid isPermaLink="true">'.e(route('threads.show', $thread)).'</guid>'
                .'<pubDate>'.$thread->created_at->toRssString().'</pubDate>'
                .'<dc:creator>'.e((string) $thread->user?->username).'</dc:creator>'
                .'<category>'.e((string) $thread->node?->title).'</category>'
                .'<description>'.e($renderer->plain((string) $thread->firstPost?->body, 400)).'</description></item>'."\n";
        }

        return response($out.'</channel></rss>'."\n")->header('Content-Type', 'application/rss+xml; charset=UTF-8');
    }
}
