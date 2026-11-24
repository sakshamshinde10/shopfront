<?php

namespace App\Http\Middleware;

use App\Models\Site;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a request through only if it carries the site's edit token.
 * A wrong token gets the same 404 as a site that does not exist, so nobody
 * can use this API to find out which sites exist.
 */
class EnsureSiteOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        $site = $request->route('site');
        if (! $site instanceof Site) {
            $site = Site::find($site);
        }

        $token = (string) $request->bearerToken();

        if (! $site || $token === '' || ! hash_equals($site->edit_token, $token)) {
            abort(404, 'Site not found');
        }

        $request->route()->setParameter('site', $site);

        return $next($request);
    }
}
