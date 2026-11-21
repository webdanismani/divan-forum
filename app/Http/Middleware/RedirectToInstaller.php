<?php

namespace App\Http\Middleware;

use App\Forum\Setup\WebInstaller;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A freshly uploaded copy without an .env file goes to the web installer instead of failing. */
class RedirectToInstaller
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! WebInstaller::configured() && ! $request->is('install', 'install/*', 'up') && ! app()->runningUnitTests()) {
            return redirect('install');
        }

        return $next($request);
    }
}
