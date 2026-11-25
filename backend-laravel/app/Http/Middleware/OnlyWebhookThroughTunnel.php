<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * O túnel HTTPS (Cloudflare) deixa o Laravel acessível pela internet. Por ele, só o webhook do
 * EventSub pode passar; dashboard, API e login continuam só no localhost.
 *
 * Requisições que chegam pela Cloudflare trazem o cabeçalho CF-Ray (o túnel sempre adiciona).
 */
class OnlyWebhookThroughTunnel
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasHeader('CF-Ray') && ! $request->is('twitch/eventsub')) {
            abort(404);
        }

        return $next($request);
    }
}
