<?php

namespace App\Http\Controllers;

use App\Forum\Integrations\Push;
use App\Forum\Theme;
use App\Forum\Themes;
use Illuminate\Http\Request;

/** "Install as an app" (web manifest, service worker, icons) and browser notifications. */
class AppController extends Controller
{
    public function manifest(Theme $theme)
    {
        abort_unless(setting('pwa.enabled'), 404);
        $name = (string) setting('board.name');

        return response()->json([
            'name' => $name,
            'short_name' => mb_strimwidth($name, 0, 12, ''),
            'description' => (string) setting('board.description'),
            'start_url' => route('forum.index', [], false) ?: '/',
            'scope' => rtrim(route('forum.index', [], false), '/').'/',
            'display' => 'standalone',
            'background_color' => '#ffffff',
            'theme_color' => $theme->colors()['accent'] ?? '#1f6feb',
            'lang' => app()->getLocale(),
            'icons' => [
                ['src' => route('pwa.icon', 192, false), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
                ['src' => route('pwa.icon', 512, false), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'public, max-age=3600']);
    }

    /** A font or picture that an installed theme brought along for its stylesheet. */
    public function themeAsset(string $vendor, string $name, string $file, Themes $themes)
    {
        $path = $themes->asset($vendor.'/'.$name, $file);
        abort_unless($path, 404);

        return response()->file($path, [
            'Content-Type' => Themes::ASSET_TYPES[strtolower(pathinfo($file, PATHINFO_EXTENSION))],
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** A speech bubble on the board's accent colour, drawn on demand: no image files to maintain. */
    public function icon(int $size, Theme $theme)
    {
        abort_unless(in_array($size, [180, 192, 512], true) && function_exists('imagecreatetruecolor'), 404);

        $accent = ltrim((string) ($theme->colors()['accent'] ?? '#1f6feb'), '#');
        if (strlen($accent) === 3) {
            $accent = $accent[0].$accent[0].$accent[1].$accent[1].$accent[2].$accent[2];
        }
        $path = storage_path('framework/cache/app-icon-'.$size.'-'.$accent.'.png');

        if (! is_file($path)) {
            // Drawn four times too large and scaled down, which gives smooth edges.
            $big = $size * 4;
            $canvas = imagecreatetruecolor($big, $big);
            [$r, $g, $b] = array_map('hexdec', str_split($accent, 2));
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, $r, $g, $b));
            $white = imagecolorallocate($canvas, 255, 255, 255);

            imagefilledellipse($canvas, (int) ($big * 0.5), (int) ($big * 0.46), (int) ($big * 0.56), (int) ($big * 0.44), $white);
            imagefilledpolygon($canvas, [
                (int) ($big * 0.33), (int) ($big * 0.58),
                (int) ($big * 0.30), (int) ($big * 0.76),
                (int) ($big * 0.48), (int) ($big * 0.64),
            ], $white);
            $dot = imagecolorallocate($canvas, $r, $g, $b);
            foreach ([0.39, 0.5, 0.61] as $x) {
                imagefilledellipse($canvas, (int) ($big * $x), (int) ($big * 0.46), (int) ($big * 0.065), (int) ($big * 0.065), $dot);
            }

            $icon = imagecreatetruecolor($size, $size);
            imagecopyresampled($icon, $canvas, 0, 0, 0, 0, $size, $size, $big, $big);
            imagepng($icon, $path);
        }

        return response()->file($path, ['Content-Type' => 'image/png', 'Cache-Control' => 'public, max-age=604800']);
    }

    /**
     * The service worker deliberately does very little: it never caches pages
     * (a stale forum is worse than no forum), it only shows the offline page
     * when the network is gone and turns pushes into notifications.
     */
    public function worker(Push $push)
    {
        abort_unless(setting('pwa.enabled'), 404);

        $config = json_encode([
            'offline' => route('pwa.offline', [], false),
            'latest' => route('push.latest', [], false),
            'icon' => route('pwa.icon', 192, false),
            'fallback' => $push->latestFor(null),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);

        $script = <<<JS
        const CONFIG = $config;
        const CACHE = 'forum-offline-v1';

        self.addEventListener('install', (event) => {
            event.waitUntil(caches.open(CACHE).then((cache) => cache.add(CONFIG.offline)).catch(() => {}).then(() => self.skipWaiting()));
        });

        self.addEventListener('activate', (event) => {
            event.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key)))).then(() => self.clients.claim()));
        });

        self.addEventListener('fetch', (event) => {
            if (event.request.mode !== 'navigate') {
                return;
            }
            event.respondWith(fetch(event.request).catch(() => caches.match(CONFIG.offline).then((page) => page || Response.error())));
        });

        self.addEventListener('push', (event) => {
            event.waitUntil(
                fetch(CONFIG.latest, { credentials: 'include', headers: { Accept: 'application/json' } })
                    .then((response) => (response.ok ? response.json() : CONFIG.fallback))
                    .catch(() => CONFIG.fallback)
                    .then((alert) => self.registration.showNotification(alert.title, { body: alert.body, tag: alert.tag, icon: CONFIG.icon, badge: CONFIG.icon, data: { url: alert.url } }))
            );
        });

        self.addEventListener('notificationclick', (event) => {
            event.notification.close();
            const url = (event.notification.data || {}).url || '/';
            event.waitUntil(
                self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
                    for (const client of windows) {
                        if ('focus' in client && 'navigate' in client) {
                            return client.navigate(url).then((opened) => (opened || client).focus());
                        }
                    }
                    return self.clients.openWindow(url);
                })
            );
        });
        JS;

        return response($script, 200, ['Content-Type' => 'application/javascript; charset=utf-8', 'Cache-Control' => 'no-cache', 'Service-Worker-Allowed' => '/']);
    }

    public function offline()
    {
        return response()->view('pwa.offline')->header('Cache-Control', 'public, max-age=3600');
    }

    // ---- Browser notifications -------------------------------------------------------------

    public function latest(Request $request, Push $push)
    {
        return response()->json($push->latestFor($request->user()))->header('Cache-Control', 'no-store');
    }

    public function subscribe(Request $request, Push $push)
    {
        abort_unless($push->enabled(), 404);
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:1000']]);

        abort_unless($push->subscribe($request->user(), $data['endpoint']), 422, __('This browser cannot receive notifications from the forum.'));

        return response()->json(['ok' => true]);
    }

    public function unsubscribe(Request $request, Push $push)
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:1000']]);
        $push->unsubscribe($request->user(), $data['endpoint']);

        return response()->json(['ok' => true]);
    }
}
