<?php

namespace App\Http\Middleware;

use App\Forum\Setup\WebInstaller;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers only under the board's own address and sends the browser the usual protective headers.
 *
 * Links in pages and e-mails are built from the address in APP_URL (see AppServiceProvider). A request that
 * arrives under another name (an alias, the bare IP address, a made-up Host header) is sent to the same page
 * at the real address, so that nothing is ever generated, cached or mailed for a host somebody else chose.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($canonical = self::canonicalRoot()) {
            $host = (string) parse_url($canonical, PHP_URL_HOST);
            if (strcasecmp($request->getHost(), $host) !== 0 && ! $request->is('up')) {
                abort_unless($request->isMethod('GET') || $request->isMethod('HEAD'), 400);

                // getRequestUri() already carries the folder of a board installed below the web root.
                $origin = parse_url($canonical, PHP_URL_SCHEME).'://'.$host.(($port = parse_url($canonical, PHP_URL_PORT)) ? ':'.$port : '');

                return redirect()->away($origin.$request->getRequestUri(), 301);
            }
        }

        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), usb=(), bluetooth=()',
            // No other site may frame the forum, plant a <base> or load plug-ins. Scripts are not restricted here:
            // the administrator's own HTML and ad codes have to keep working.
            'Content-Security-Policy' => "frame-ancestors 'self'; base-uri 'self'; object-src 'none'",
        ];
        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000';
        }
        foreach ($headers as $name => $value) {
            // A response that already decided for itself (attachments, embedded pages) keeps its own value.
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    /** The board's address without a trailing slash, or null where it is not known for sure (not installed, local work, tests). */
    public static function canonicalRoot(): ?string
    {
        if (! config('forum.force_host', true) || app()->environment('local', 'testing') || ! WebInstaller::configured()) {
            return null;
        }

        $root = rtrim((string) config('app.url'), '/');
        $host = (string) parse_url($root, PHP_URL_HOST);

        return $host === '' || in_array($host, ['localhost', '127.0.0.1', '[::1]'], true) ? null : $root;
    }
}
