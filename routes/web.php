<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AdController;
use App\Http\Controllers\Admin;
use App\Http\Controllers\AppController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookmarkController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\ForumController;
use App\Http\Controllers\LiveController;
use App\Http\Controllers\MarketController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\ModerationController;
use App\Http\Controllers\MyController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PollController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\PromotionController;
use App\Http\Controllers\SavedReplyController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\ThreadController;
use App\Http\Controllers\TwoFactorController;
use App\Http\Controllers\UpgradeController;
use App\Http\Controllers\VerificationController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\WarningController;
use App\Http\Controllers\WelcomeController;
use Illuminate\Support\Facades\Route;

// ---- Forum ---------------------------------------------------------------
Route::get('/', [ForumController::class, 'index'])->name('forum.index');
Route::get('/forums/{node}', [ForumController::class, 'show'])->name('nodes.show');
Route::get('/whats-new', [ForumController::class, 'whatsNew'])->name('whats-new');
Route::get('/search', SearchController::class)->name('search');
Route::get('/search/suggest', [SearchController::class, 'suggest'])->name('search.suggest')->middleware('throttle:90,1');

Route::get('/threads/{thread}', [ThreadController::class, 'show'])->name('threads.show');
Route::get('/threads/{thread}/unread', [ThreadController::class, 'unread'])->name('threads.unread');
Route::get('/threads/{thread}/preview', [ThreadController::class, 'preview'])->name('threads.preview')->middleware('throttle:120,1');
Route::get('/threads/{thread}/since/{after}', [ThreadController::class, 'since'])->name('threads.since')->whereNumber('after')->middleware('throttle:120,1');
Route::get('/posts/{post}', [PostController::class, 'show'])->name('posts.show')->whereNumber('post');

Route::get('/members', [MemberController::class, 'index'])->name('members.index');
Route::get('/members/suggest', [MemberController::class, 'suggest'])->name('members.suggest')->middleware(['auth', 'throttle:120,1']);
Route::get('/members/{user}', [MemberController::class, 'show'])->name('members.show');
Route::get('/members/{user}/card', [MemberController::class, 'card'])->name('members.card')->middleware('throttle:120,1');

Route::get('/advertise', [AdController::class, 'index'])->name('ads.index');
Route::get('/ads/{ad}/go', [AdController::class, 'click'])->name('ads.click')->whereNumber('ad');
// PayTR notification URL: called by PayTR's servers, so it is exempt from CSRF (bootstrap/app.php).
Route::post('/ads/payment/paytr', [PaymentController::class, 'paytr'])->name('ads.paytr');

Route::get('/attachments/{attachment}/{name?}', [AttachmentController::class, 'show'])->name('attachments.show')->whereNumber('attachment');
Route::get('/upgrades', [UpgradeController::class, 'index'])->name('upgrades.index');
Route::get('/tags', [TagController::class, 'index'])->name('tags.index');
Route::get('/pages/{page}', [SiteController::class, 'page'])->name('pages.show');
Route::get('/contact', [SiteController::class, 'contact'])->name('contact');
Route::post('/contact', [SiteController::class, 'sendContact'])->middleware('throttle:5,10');
Route::get('/robots.txt', [SiteController::class, 'robots'])->name('robots');
Route::get('/sitemap.xml', [SiteController::class, 'sitemap'])->name('sitemap');
Route::get('/rss', [SiteController::class, 'feed'])->name('feed');
Route::get('/forums/{node}/rss', [SiteController::class, 'feed'])->name('nodes.feed');
Route::get('/tags/{tag}', [TagController::class, 'show'])->name('tags.show');

Route::get('/market', [MarketController::class, 'index'])->name('market.index');
Route::get('/members/{user}/feedback', [MarketController::class, 'feedback'])->name('members.feedback');
Route::get('/shoutbox', [LiveController::class, 'shouts'])->name('shoutbox.index');
Route::get('/similar-threads', [LiveController::class, 'similar'])->name('threads.similar')->middleware('throttle:60,1');

// Install as an app, browser notifications.
Route::get('/manifest.webmanifest', [AppController::class, 'manifest'])->name('pwa.manifest');
Route::get('/sw.js', [AppController::class, 'worker'])->name('pwa.sw');
Route::get('/offline', [AppController::class, 'offline'])->name('pwa.offline');
Route::get('/app-icon-{size}.png', [AppController::class, 'icon'])->name('pwa.icon')->whereNumber('size');
Route::get('/theme-assets/{vendor}/{name}/{file}', [AppController::class, 'themeAsset'])->name('themes.asset')
    ->where(['vendor' => '[A-Za-z][A-Za-z0-9]*', 'name' => '[A-Za-z][A-Za-z0-9]*', 'file' => '[A-Za-z0-9][A-Za-z0-9._-]*']);
Route::get('/push/latest', [AppController::class, 'latest'])->name('push.latest');
Route::get('/email/verify/{id}/{hash}', [VerificationController::class, 'verify'])->name('verification.verify')->middleware(['signed', 'throttle:12,1'])->whereNumber('id');

// ---- Guests only ---------------------------------------------------------
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::get('/login/two-factor', [AuthController::class, 'showChallenge'])->name('two-factor.challenge');
    Route::post('/login/two-factor', [AuthController::class, 'challenge'])->middleware('throttle:8,1');
    Route::get('/auth/{provider}', [AuthController::class, 'socialRedirect'])->name('social.redirect')->whereIn('provider', ['google', 'github']);
    Route::get('/auth/{provider}/callback', [AuthController::class, 'socialCallback'])->name('social.callback')->whereIn('provider', ['google', 'github']);
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:6,1');

    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'email'])->name('password.email')->middleware('throttle:5,1');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'reset'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->name('password.update')->middleware('throttle:10,10');
});

// ---- Members -------------------------------------------------------------
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::post('/mark-read', [ForumController::class, 'markRead'])->name('forum.mark-read');
    Route::get('/my/{tab?}', MyController::class)->name('my')->where('tab', '[a-z]+');

    Route::get('/forums/{node}/new-thread', [ThreadController::class, 'create'])->name('threads.create');
    Route::post('/forums/{node}/new-thread', [ThreadController::class, 'store'])->name('threads.store');
    Route::post('/threads/{thread}/reply', [PostController::class, 'store'])->name('posts.store');
    Route::post('/threads/{thread}/watch', [ThreadController::class, 'watch'])->name('threads.watch');
    Route::post('/threads/{thread}/moderate', [ThreadController::class, 'moderate'])->name('threads.moderate');

    Route::get('/posts/{post}/edit', [PostController::class, 'edit'])->name('posts.edit');
    Route::put('/posts/{post}', [PostController::class, 'update'])->name('posts.update');
    Route::delete('/posts/{post}', [PostController::class, 'destroy'])->name('posts.destroy');
    Route::post('/posts/{post}/moderate', [PostController::class, 'moderate'])->name('posts.moderate');
    Route::post('/posts/{post}/react', [PostController::class, 'react'])->name('posts.react');
    Route::post('/posts/{post}/report', [PostController::class, 'report'])->name('posts.report');
    Route::get('/posts/{post}/quote', [PostController::class, 'quote'])->name('posts.quote');

    Route::get('/account', [AccountController::class, 'edit'])->name('account.edit');
    Route::put('/account/profile', [AccountController::class, 'profile'])->name('account.profile');
    Route::post('/account/avatar', [AccountController::class, 'avatar'])->name('account.avatar');
    Route::delete('/account/avatar', [AccountController::class, 'removeAvatar'])->name('account.avatar.remove');
    Route::post('/account/cover', [AccountController::class, 'cover'])->name('account.cover');
    Route::delete('/account/cover', [AccountController::class, 'removeCover'])->name('account.cover.remove');
    Route::put('/account/security', [AccountController::class, 'security'])->name('account.security')->middleware('throttle:10,10');
    Route::delete('/account/sessions', [AccountController::class, 'endOtherSessions'])->name('account.sessions.end-others')->middleware('throttle:10,10');
    Route::delete('/account/sessions/{key}', [AccountController::class, 'endSession'])->name('account.sessions.end')->where('key', '[a-f0-9]{32}');
    Route::put('/account/preferences', [AccountController::class, 'preferences'])->name('account.preferences');
    Route::get('/account/two-factor', [TwoFactorController::class, 'show'])->name('two-factor.show');
    Route::post('/account/two-factor', [TwoFactorController::class, 'start'])->name('two-factor.start')->middleware('throttle:10,10');
    Route::put('/account/two-factor', [TwoFactorController::class, 'confirm'])->name('two-factor.confirm')->middleware('throttle:10,1');
    Route::delete('/account/two-factor', [TwoFactorController::class, 'destroy'])->name('two-factor.destroy')->middleware('throttle:10,10');
    Route::post('/account/api-keys', [AccountController::class, 'createApiKey'])->name('account.api-keys.store')->middleware('throttle:10,1');
    Route::delete('/account/api-keys/{token}', [AccountController::class, 'deleteApiKey'])->name('account.api-keys.destroy')->whereNumber('token');
    Route::post('/account/phone', [AccountController::class, 'sendPhoneCode'])->name('account.phone.send')->middleware('throttle:6,10');
    Route::put('/account/phone', [AccountController::class, 'confirmPhone'])->name('account.phone.confirm')->middleware('throttle:12,10');
    Route::delete('/account/phone', [AccountController::class, 'removePhone'])->name('account.phone.remove')->middleware('throttle:6,10');
    Route::get('/live', [LiveController::class, 'poll'])->name('live.poll');
    Route::post('/shoutbox', [LiveController::class, 'shout'])->name('shoutbox.store')->middleware('throttle:15,1');
    Route::delete('/shoutbox/{shout}', [LiveController::class, 'deleteShout'])->name('shoutbox.destroy');
    Route::post('/threads/{thread}/summary', [LiveController::class, 'summarize'])->name('threads.summary')->middleware('throttle:6,1');
    Route::put('/threads/{thread}/listing', [MarketController::class, 'update'])->name('listings.update');
    Route::post('/threads/{thread}/feedback', [MarketController::class, 'rate'])->name('listings.rate')->middleware('throttle:10,1');
    Route::delete('/feedback/{feedback}', [MarketController::class, 'destroyFeedback'])->name('feedback.destroy');
    Route::post('/advertise/{ad}/renew', [AdController::class, 'renew'])->name('ads.renew')->whereNumber('ad')->middleware('throttle:10,1');
    Route::get('/advertise/{ad}/receipt', [AdController::class, 'receipt'])->name('ads.receipt')->whereNumber('ad');
    Route::get('/payments', [PaymentController::class, 'index'])->name('payments.index');
    Route::get('/payments/{payment}/receipt', [PaymentController::class, 'receipt'])->name('payments.receipt')->whereNumber('payment');
    Route::post('/push/subscription', [AppController::class, 'subscribe'])->name('push.subscribe')->middleware('throttle:20,1');
    Route::delete('/push/subscription', [AppController::class, 'unsubscribe'])->name('push.unsubscribe');

    Route::post('/members/{user}/follow', [MemberController::class, 'follow'])->name('members.follow');
    Route::post('/members/{user}/friend', [MemberController::class, 'friend'])->name('members.friend')->middleware('throttle:30,1');
    Route::post('/members/{user}/notes', [MemberController::class, 'storeNote'])->name('member-notes.store');
    Route::delete('/member-notes/{note}', [MemberController::class, 'destroyNote'])->name('member-notes.destroy')->whereNumber('note');
    Route::get('/friends', [MemberController::class, 'friends'])->name('friends.index');
    Route::get('/saved-replies', [SavedReplyController::class, 'index'])->name('replies.index');
    Route::post('/saved-replies', [SavedReplyController::class, 'store'])->name('replies.store');
    Route::put('/saved-replies/{reply}', [SavedReplyController::class, 'update'])->name('replies.update')->whereNumber('reply');
    Route::delete('/saved-replies/{reply}', [SavedReplyController::class, 'destroy'])->name('replies.destroy')->whereNumber('reply');
    Route::put('/account/privacy', [AccountController::class, 'privacy'])->name('account.privacy');
    Route::put('/account/alerts', [AccountController::class, 'alerts'])->name('account.alerts');
    Route::put('/account/username', [AccountController::class, 'rename'])->name('account.rename')->middleware('throttle:10,10');
    Route::post('/account/delete', [AccountController::class, 'requestDeletion'])->name('account.delete')->middleware('throttle:10,10');
    Route::delete('/account/delete', [AccountController::class, 'cancelDeletion'])->name('account.delete.cancel');
    Route::get('/welcome', [WelcomeController::class, 'show'])->name('welcome');
    Route::post('/welcome', [WelcomeController::class, 'save'])->name('welcome.save');
    Route::get('/welcome/profile', [WelcomeController::class, 'profile'])->name('welcome.profile');
    Route::post('/welcome/profile', [WelcomeController::class, 'saveProfile'])->name('welcome.profile.save');
    Route::get('/welcome/done', [WelcomeController::class, 'done'])->name('welcome.done');
    Route::post('/members/{user}/ignore', [MemberController::class, 'ignore'])->name('members.ignore');
    Route::post('/members/{user}/spam-clean', [MemberController::class, 'spamClean'])->name('members.spam-clean');
    Route::post('/members/{user}/profile-posts', [MemberController::class, 'storeProfilePost'])->name('profile-posts.store')->middleware('throttle:20,1');
    Route::delete('/profile-posts/{profilePost}', [MemberController::class, 'destroyProfilePost'])->name('profile-posts.destroy');

    Route::get('/conversations', [ConversationController::class, 'index'])->name('conversations.index');
    Route::get('/conversations/panel', [ConversationController::class, 'panel'])->name('conversations.panel');
    Route::post('/conversations/bulk', [ConversationController::class, 'bulk'])->name('conversations.bulk');
    Route::post('/conversations/{conversation}/unread', [ConversationController::class, 'unread'])->name('conversations.unread');
    Route::get('/conversations/new', [ConversationController::class, 'create'])->name('conversations.create');
    Route::post('/conversations', [ConversationController::class, 'store'])->name('conversations.store');
    Route::get('/conversations/{conversation}', [ConversationController::class, 'show'])->name('conversations.show');
    Route::post('/conversations/{conversation}/reply', [ConversationController::class, 'reply'])->name('conversations.reply');
    Route::delete('/conversations/{conversation}', [ConversationController::class, 'leave'])->name('conversations.leave');

    Route::get('/alerts', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/alerts/panel', [NotificationController::class, 'panel'])->name('notifications.panel');
    Route::get('/alerts/{id}', [NotificationController::class, 'open'])->name('notifications.open');
    Route::post('/alerts/read', [NotificationController::class, 'readAll'])->name('notifications.read-all');

    Route::post('/advertise', [AdController::class, 'store'])->name('ads.store')->middleware('throttle:10,1');
    Route::get('/advertise/my', [AdController::class, 'mine'])->name('ads.mine');
    Route::get('/advertise/{ad}', [AdController::class, 'show'])->name('ads.show')->whereNumber('ad');
    Route::post('/advertise/{ad}/transfer', [AdController::class, 'transfer'])->name('ads.transfer')->whereNumber('ad');
    Route::delete('/advertise/{ad}', [AdController::class, 'destroy'])->name('ads.destroy')->whereNumber('ad');
    Route::post('/advertise/{ad}/pay', [AdController::class, 'pay'])->name('ads.pay')->whereNumber('ad');

    Route::get('/email/verify', [VerificationController::class, 'notice'])->name('verification.notice');
    Route::post('/email/verify', [VerificationController::class, 'send'])->name('verification.send')->middleware('throttle:4,10');

    Route::post('/attachments', [AttachmentController::class, 'store'])->name('attachments.store')->middleware('throttle:60,1');
    Route::delete('/attachments/{attachment}', [AttachmentController::class, 'destroy'])->name('attachments.destroy')->whereNumber('attachment');

    Route::post('/members/{user}/warn', [WarningController::class, 'store'])->name('members.warn');
    Route::delete('/warnings/{warning}', [WarningController::class, 'destroy'])->name('warnings.destroy');

    Route::get('/wallet', [WalletController::class, 'index'])->name('wallet.index');
    Route::post('/wallet/top-up', [WalletController::class, 'topup'])->name('wallet.topup')->middleware('throttle:10,1');
    Route::get('/payments/{payment}', [PaymentController::class, 'show'])->name('payments.show')->whereNumber('payment');
    Route::post('/payments/{payment}/transfer', [PaymentController::class, 'transfer'])->name('payments.transfer')->whereNumber('payment');
    Route::delete('/payments/{payment}', [PaymentController::class, 'destroy'])->name('payments.destroy')->whereNumber('payment');
    Route::post('/upgrades/{upgrade}/buy', [UpgradeController::class, 'buy'])->name('upgrades.buy')->whereNumber('upgrade');
    Route::get('/threads/{thread}/promote', [PromotionController::class, 'show'])->name('threads.promote');
    Route::post('/threads/{thread}/promote', [PromotionController::class, 'buy'])->name('threads.promote.buy');

    Route::post('/forums/{node}/moderate', [ModerationController::class, 'threads'])->name('mod.threads');
    Route::post('/forums/{node}/watch', [ForumController::class, 'watch'])->name('nodes.watch');
    Route::post('/threads/{thread}/poll', [PollController::class, 'vote'])->name('polls.vote');
    Route::put('/threads/{thread}/poll', [PollController::class, 'update'])->name('polls.update');
    Route::post('/posts/{post}/solution', [PostController::class, 'solution'])->name('posts.solution');
    Route::get('/posts/{post}/history', [PostController::class, 'history'])->name('posts.history');
    Route::post('/posts/{post}/bookmark', [BookmarkController::class, 'toggle'])->name('posts.bookmark');
    Route::get('/bookmarks', [BookmarkController::class, 'index'])->name('bookmarks.index');
    Route::post('/preview', [PostController::class, 'preview'])->name('preview')->middleware('throttle:40,1');
    Route::post('/threads/{thread}/moderate-posts', [ModerationController::class, 'posts'])->name('mod.posts');

    Route::get('/moderation/reports', [ModerationController::class, 'reports'])->name('mod.reports');
    Route::put('/moderation/reports/{report}', [ModerationController::class, 'updateReport'])->name('mod.reports.update');
    Route::get('/moderation/queue', [ModerationController::class, 'queue'])->name('mod.queue');
});

// ---- Admin control panel ---------------------------------------------------
Route::middleware(['auth', 'can:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', Admin\DashboardController::class)->name('dashboard');

    Route::resource('nodes', Admin\NodeController::class)->except('show');
    Route::get('nodes/{node}/permissions', [Admin\NodeController::class, 'permissions'])->name('nodes.permissions');
    Route::put('nodes/{node}/permissions', [Admin\NodeController::class, 'updatePermissions'])->name('nodes.permissions.update');
    Route::post('nodes/{node}/moderators', [Admin\NodeController::class, 'addModerator'])->name('nodes.moderators.add');
    Route::delete('nodes/{node}/moderators/{user}', [Admin\NodeController::class, 'removeModerator'])->name('nodes.moderators.remove');

    Route::resource('groups', Admin\GroupController::class)->except('show');
    Route::resource('users', Admin\UserController::class)->only(['index', 'edit', 'update', 'destroy']);

    Route::get('settings', [Admin\SettingController::class, 'edit'])->name('settings.edit');
    Route::put('settings', [Admin\SettingController::class, 'update'])->name('settings.update');

    Route::get('appearance', [Admin\AppearanceController::class, 'edit'])->name('appearance.edit');
    Route::put('appearance', [Admin\AppearanceController::class, 'update'])->name('appearance.update');
    Route::delete('appearance', [Admin\AppearanceController::class, 'reset'])->name('appearance.reset');
    Route::post('appearance/extras', [Admin\CustomizeController::class, 'saveExtras'])->name('appearance.extras');
    Route::get('home', [Admin\HomeController::class, 'edit'])->name('home.edit');
    Route::put('home', [Admin\HomeController::class, 'update'])->name('home.update');
    Route::post('home/adopt', [Admin\HomeController::class, 'adopt'])->name('home.adopt');
    Route::get('phrases', [Admin\CustomizeController::class, 'phrases'])->name('phrases');
    Route::post('phrases', [Admin\CustomizeController::class, 'savePhrase'])->name('phrases.save');
    Route::get('templates', [Admin\CustomizeController::class, 'templates'])->name('templates');
    Route::get('templates/edit', [Admin\CustomizeController::class, 'editTemplate'])->name('templates.edit');
    Route::post('templates/edit', [Admin\CustomizeController::class, 'saveTemplate'])->name('templates.save');
    Route::get('addons', [Admin\AddonController::class, 'index'])->name('addons');
    Route::post('addons', [Admin\AddonController::class, 'toggle'])->name('addons.toggle');
    Route::post('addons/upload', [Admin\AddonController::class, 'upload'])->name('addons.upload');
    Route::get('addons/settings', [Admin\AddonController::class, 'settings'])->name('addons.settings');
    Route::post('addons/settings', [Admin\AddonController::class, 'saveSettings'])->name('addons.settings.save');

    Route::get('prefixes', [Admin\CatalogController::class, 'prefixes'])->name('prefixes');
    Route::post('prefixes', [Admin\CatalogController::class, 'savePrefix'])->name('prefixes.save');
    Route::delete('prefixes/{prefix}', [Admin\CatalogController::class, 'deletePrefix'])->name('prefixes.delete');
    Route::get('reactions', [Admin\CatalogController::class, 'reactions'])->name('reactions');
    Route::post('reactions', [Admin\CatalogController::class, 'saveReaction'])->name('reactions.save');
    Route::delete('reactions/{reaction}', [Admin\CatalogController::class, 'deleteReaction'])->name('reactions.delete');

    Route::resource('ads', Admin\AdController::class)->except('show');
    Route::post('ads/{ad}/action', [Admin\AdController::class, 'action'])->name('ads.action');
    Route::get('ad-zones', [Admin\AdController::class, 'zones'])->name('ad-zones.index');
    Route::get('ad-zones/{zone}', [Admin\AdController::class, 'editZone'])->name('ad-zones.edit');
    Route::put('ad-zones/{zone}', [Admin\AdController::class, 'updateZone'])->name('ad-zones.update');

    Route::get('payments', [Admin\PaymentController::class, 'index'])->name('payments.index');
    Route::post('payments/{payment}/action', [Admin\PaymentController::class, 'action'])->name('payments.action');
    Route::get('wallet', [Admin\PaymentController::class, 'wallet'])->name('wallet');
    Route::post('users/{user}/balance', [Admin\PaymentController::class, 'adjust'])->name('users.balance');

    Route::get('promotions', [Admin\CommerceController::class, 'promotions'])->name('promotions');
    Route::post('promotions', [Admin\CommerceController::class, 'savePromotion'])->name('promotions.save');
    Route::delete('promotions/{option}', [Admin\CommerceController::class, 'deletePromotion'])->name('promotions.delete');
    Route::get('upgrades', [Admin\CommerceController::class, 'upgrades'])->name('upgrades');
    Route::post('upgrades', [Admin\CommerceController::class, 'saveUpgrade'])->name('upgrades.save');
    Route::delete('upgrades/{upgrade}', [Admin\CommerceController::class, 'deleteUpgrade'])->name('upgrades.delete');
    Route::get('coupons', [Admin\CommerceController::class, 'coupons'])->name('coupons');
    Route::post('coupons', [Admin\CommerceController::class, 'saveCoupon'])->name('coupons.save');
    Route::delete('coupons/{coupon}', [Admin\CommerceController::class, 'deleteCoupon'])->name('coupons.delete');
    Route::get('warnings', [Admin\CommerceController::class, 'warnings'])->name('warnings');
    Route::post('warnings/types', [Admin\CommerceController::class, 'saveWarningType'])->name('warnings.types.save');
    Route::delete('warnings/types/{type}', [Admin\CommerceController::class, 'deleteWarningType'])->name('warnings.types.delete');
    Route::post('warnings/actions', [Admin\CommerceController::class, 'saveWarningAction'])->name('warnings.actions.save');
    Route::delete('warnings/actions/{action}', [Admin\CommerceController::class, 'deleteWarningAction'])->name('warnings.actions.delete');

    Route::post('settings/test-mail', [Admin\SettingController::class, 'testMail'])->name('settings.test-mail');
    Route::get('update', [Admin\UpdateController::class, 'index'])->name('update');
    Route::post('update/migrate', [Admin\UpdateController::class, 'migrate'])->name('update.migrate');
    Route::post('update/tasks', [Admin\UpdateController::class, 'tasks'])->name('update.tasks');
    Route::post('update/upload', [Admin\UpdateController::class, 'upload'])->name('update.upload');

    Route::get('search', Admin\SearchController::class)->name('search');
    Route::get('duplicates', [Admin\DuplicateController::class, 'index'])->name('duplicates');
    Route::get('backups', [Admin\BackupController::class, 'index'])->name('backups');
    Route::post('backups', [Admin\BackupController::class, 'store'])->name('backups.store');
    Route::post('backups/{name}', [Admin\BackupController::class, 'download'])->name('backups.download')->where('name', '[A-Za-z0-9.-]+');
    Route::delete('backups/{name}', [Admin\BackupController::class, 'destroy'])->name('backups.destroy')->where('name', '[A-Za-z0-9.-]+');
    Route::get('deletions', [Admin\DeletionController::class, 'index'])->name('deletions');
    Route::post('deletions/{user}', [Admin\DeletionController::class, 'decide'])->name('deletions.decide');
    Route::get('bans', [Admin\MemberToolsController::class, 'bans'])->name('bans');
    Route::post('bans', [Admin\MemberToolsController::class, 'saveBan'])->name('bans.save');
    Route::delete('bans/{ban}', [Admin\MemberToolsController::class, 'deleteBan'])->name('bans.delete');
    Route::get('profile-fields', [Admin\MemberToolsController::class, 'profile'])->name('profile');
    Route::post('profile-fields', [Admin\MemberToolsController::class, 'saveField'])->name('profile.fields.save');
    Route::delete('profile-fields/{field}', [Admin\MemberToolsController::class, 'deleteField'])->name('profile.fields.delete');
    Route::post('titles', [Admin\MemberToolsController::class, 'saveTitle'])->name('profile.titles.save');
    Route::delete('titles/{title}', [Admin\MemberToolsController::class, 'deleteTitle'])->name('profile.titles.delete');
    Route::get('rewards', [Admin\MemberToolsController::class, 'rewards'])->name('rewards');
    Route::post('badges', [Admin\MemberToolsController::class, 'saveBadge'])->name('rewards.badges.save');
    Route::delete('badges/{badge}', [Admin\MemberToolsController::class, 'deleteBadge'])->name('rewards.badges.delete');
    Route::post('users/{user}/badge', [Admin\MemberToolsController::class, 'toggleBadge'])->name('users.badge');
    Route::post('group-promotions', [Admin\MemberToolsController::class, 'savePromotion'])->name('rewards.promotions.save');
    Route::delete('group-promotions/{promotion}', [Admin\MemberToolsController::class, 'deletePromotion'])->name('rewards.promotions.delete');

    Route::get('pages', [Admin\ContentController::class, 'pages'])->name('pages');
    Route::get('pages/new', [Admin\ContentController::class, 'editPage'])->name('pages.create');
    Route::post('pages/new', [Admin\ContentController::class, 'savePage'])->name('pages.store');
    Route::get('pages/{page}/edit', [Admin\ContentController::class, 'editPage'])->name('pages.edit');
    Route::put('pages/{page}', [Admin\ContentController::class, 'savePage'])->name('pages.update');
    Route::delete('pages/{page}', [Admin\ContentController::class, 'deletePage'])->name('pages.delete');
    Route::get('notices', [Admin\ContentController::class, 'notices'])->name('notices');
    Route::post('notices', [Admin\ContentController::class, 'saveNotice'])->name('notices.save');
    Route::delete('notices/{notice}', [Admin\ContentController::class, 'deleteNotice'])->name('notices.delete');
    Route::post('nav-links', [Admin\ContentController::class, 'saveLink'])->name('links.save');
    Route::delete('nav-links/{link}', [Admin\ContentController::class, 'deleteLink'])->name('links.delete');
    Route::get('messages', [Admin\ContentController::class, 'messages'])->name('messages');
    Route::delete('messages/{message}', [Admin\ContentController::class, 'deleteMessage'])->name('messages.delete');
    Route::get('mail', [Admin\ContentController::class, 'mail'])->name('mail');
    Route::post('mail', [Admin\ContentController::class, 'sendMail'])->name('mail.send');

    Route::get('logs', [Admin\DashboardController::class, 'logs'])->name('logs');
    Route::get('market', [Admin\MarketController::class, 'index'])->name('market');
    Route::post('market/buy', [Admin\MarketController::class, 'buy'])->name('market.buy');
    Route::post('market/install', [Admin\MarketController::class, 'install'])->name('market.install');
    Route::post('market/core', [Admin\MarketController::class, 'updateCore'])->name('market.core');
    Route::post('market/theme', [Admin\MarketController::class, 'theme'])->name('market.theme');
    Route::post('market/theme/upload', [Admin\MarketController::class, 'uploadTheme'])->name('market.theme.upload');

    Route::get('integrations', [Admin\IntegrationController::class, 'index'])->name('integrations');
    Route::post('integrations/webhooks', [Admin\IntegrationController::class, 'saveWebhook'])->name('integrations.webhooks.save');
    Route::post('integrations/webhooks/{webhook}/test', [Admin\IntegrationController::class, 'testWebhook'])->name('integrations.webhooks.test');
    Route::delete('integrations/webhooks/{webhook}', [Admin\IntegrationController::class, 'deleteWebhook'])->name('integrations.webhooks.delete');
    Route::delete('integrations/tokens/{token}', [Admin\IntegrationController::class, 'deleteToken'])->name('integrations.tokens.delete');
    Route::get('import', [Admin\IntegrationController::class, 'import'])->name('import');
    Route::post('import', [Admin\IntegrationController::class, 'startImport'])->name('import.start');
    Route::post('import/{run}/advance', [Admin\IntegrationController::class, 'advanceImport'])->name('import.advance');
    Route::put('import/{run}', [Admin\IntegrationController::class, 'updateImport'])->name('import.update');

    Route::get('license', [Admin\LicenseController::class, 'index'])->name('license');
    Route::post('license', [Admin\LicenseController::class, 'activate'])->name('license.activate');
    Route::post('license/check', [Admin\LicenseController::class, 'check'])->name('license.check');
    Route::post('license/release', [Admin\LicenseController::class, 'release'])->name('license.release');
});
