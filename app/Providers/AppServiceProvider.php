<?php

namespace App\Providers;

use App\Forum\Addons\Hooks;
use App\Forum\Addons\Manager as AddonManager;
use App\Forum\Ads;
use App\Forum\Community;
use App\Forum\Customizer;
use App\Forum\Edition;
use App\Forum\Inbox;
use App\Forum\Integrations\Push;
use App\Forum\Integrations\Webhooks;
use App\Forum\Mailer;
use App\Forum\Mailing;
use App\Forum\Market;
use App\Forum\Moderation\Censor;
use App\Forum\NodeTree;
use App\Forum\Payments\Payments;
use App\Forum\Permissions\PermissionResolver;
use App\Forum\Privacy;
use App\Forum\Rewards;
use App\Forum\Security\Bans;
use App\Forum\Settings;
use App\Forum\Site;
use App\Forum\Theme;
use App\Forum\Themes;
use App\Forum\Translation\OverridingLoader;
use App\Jobs\CheckSpam;
use App\Models\Ad;
use App\Models\AdZone;
use App\Models\Node;
use App\Models\NodePermission;
use App\Models\Post;
use App\Models\Thread;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Request scoped: they memoise database state.
        $this->app->scoped(Settings::class);
        $this->app->scoped(NodeTree::class);
        $this->app->scoped(PermissionResolver::class);
        $this->app->scoped(Edition::class);
        $this->app->scoped(Theme::class);
        $this->app->scoped(Ads::class);
        $this->app->scoped(Payments::class);
        $this->app->scoped(Community::class);
        $this->app->scoped(Rewards::class);
        $this->app->scoped(Bans::class);
        $this->app->scoped(Censor::class);
        $this->app->scoped(Site::class);
        $this->app->scoped(Customizer::class);
        $this->app->scoped(Push::class);
        $this->app->scoped(Market::class);
        $this->app->scoped(Privacy::class);
        $this->app->scoped(Inbox::class);
        $this->app->scoped(Themes::class);
        $this->app->scoped(\App\Forum\License\Verifier::class);
        $this->app->scoped(\App\Forum\License\Licenses::class);
        // One instance for the whole process: add-ons register on it while the application boots.
        $this->app->singleton(Hooks::class);
        $this->app->singleton(AddonManager::class);

        // Texts changed in the admin panel win over the language files.
        $this->app->extend('translation.loader', fn ($loader) => new OverridingLoader($loader));
    }

    public function boot(): void
    {
        // Every generated link (pages, e-mails, alerts, sitemap) carries the board's own address, never the Host
        // header of the request that happened to trigger it.
        if ($root = \App\Http\Middleware\SecurityHeaders::canonicalRoot()) {
            \Illuminate\Support\Facades\URL::forceRootUrl($root);
            if (str_starts_with($root, 'https://')) {
                \Illuminate\Support\Facades\URL::forceScheme('https');
            }
        }

        Paginator::defaultView('partials.pagination');
        Blade::directive('hook', fn (string $expression) => "<?php echo app(\\App\\Forum\\Addons\\Hooks::class)->render({$expression}); ?>");

        if (! config('forum.safe_mode')) {
            // Templates edited in the admin panel are looked up before the package's own.
            if (is_dir($overrides = storage_path('app/templates'))) {
                View::getFinder()->prependLocation($overrides);
            }
            $this->app->make(AddonManager::class)->bootEnabled();
        }

        Mailing::configure($this->app->make(Settings::class));

        Gate::define('admin', fn (User $user) => $this->app->make(PermissionResolver::class)->can($user, 'admin.access'));

        // Keep the in-memory caches honest when the underlying rows change.
        $flushPermissions = fn () => $this->app->make(PermissionResolver::class)->flush();
        UserGroup::saved($flushPermissions);
        UserGroup::deleted($flushPermissions);
        NodePermission::saved($flushPermissions);
        NodePermission::deleted($flushPermissions);

        $flushTree = function () use ($flushPermissions) {
            $this->app->make(NodeTree::class)->flush();
            $flushPermissions();
        };
        Node::saved($flushTree);
        Node::deleted($flushTree);

        $flushAds = fn () => $this->app->make(Ads::class)->flush();
        Ad::saved($flushAds);
        Ad::deleted($flushAds);
        AdZone::saved($flushAds);

        // Every sign-in notes the browser it came from (open sessions, "new device" alerts, shared accounts).
        // Never in the way of signing in: during an update the table may not be there yet.
        Event::listen(\Illuminate\Auth\Events\Login::class, function (\Illuminate\Auth\Events\Login $event) {
            try {
                if ($event->user instanceof User) {
                    $this->app->make(\App\Forum\Security\Devices::class)->remember($event->user, request());
                }
            } catch (\Throwable $problem) {
                report($problem);
            }
        });

        // Alerts are stored in the database; some of them also go out by e-mail.
        Event::listen(NotificationSent::class, [Mailer::class, 'alertStored']);
        // ... and wake the member's browsers, where they asked for that.
        Event::listen(NotificationSent::class, [Push::class, 'alertStored']);

        // AI spam check: the first posts of new members are looked at after they went out.
        $spamCheck = function (?Post $post) {
            $author = $post?->user;
            if ($post && $author && setting('ai.spam_check') && filled(setting('ai.api_key')) && ! $author->group?->is_staff && $author->post_count <= (int) setting('ai.spam_max_posts')) {
                CheckSpam::dispatch($post->id);
            }
        };
        Event::listen('forum.thread.created', fn (Thread $thread) => $spamCheck($thread->firstPost));
        Event::listen('forum.post.created', fn (Post $post) => $spamCheck($post));

        // Webhooks: other systems hear about new content and new members.
        Event::listen('forum.thread.created', fn (Thread $thread) => $this->app->make(Webhooks::class)->dispatch('thread.created', fn (Webhooks $hooks) => $hooks->threadData($thread)));
        Event::listen('forum.post.created', fn (Post $post) => $this->app->make(Webhooks::class)->dispatch('post.created', fn (Webhooks $hooks) => $hooks->postData($post)));
        Event::listen('forum.user.registered', fn (User $user) => $this->app->make(Webhooks::class)->dispatch('user.registered', fn (Webhooks $hooks) => $hooks->userData($user)));
        // Returning false stops the alert: nothing arrives from somebody the member ignores.
        Event::listen(NotificationSending::class, fn (NotificationSending $event) => $this->app->make(Community::class)->filterAlert($event));

        // Header counters for signed-in members.
        View::composer('layouts.app', function ($view) {
            $user = auth()->user();
            $view->with('unreadAlerts', $user ? $user->unreadNotifications()->count() : 0);
            $view->with('unreadConversations', $user ? DB::table('conversation_participants as p')
                ->join('conversations as c', 'c.id', '=', 'p.conversation_id')
                ->where('p.user_id', $user->id)
                ->where(fn ($q) => $q->whereNull('p.last_read_at')->orWhereColumn('p.last_read_at', '<', 'c.last_message_at'))
                ->count() : 0);
        });
    }
}
