<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeçalhos de segurança em todas as respostas do portal (o portal é público na internet):
 * - nenhum outro site põe as telas num iframe (clickjacking em "Trocar OP", aprovar SS...);
 * - o navegador não "adivinha" o tipo dos arquivos; o endereço completo não vaza para outros sites;
 * - HSTS quando a conexão é HTTPS (o navegador passa a usar sempre HTTPS).
 */
class CabecalhosSeguranca
{
    public function handle(Request $request, Closure $next): Response
    {
        $resposta = $next($request);
        $h = $resposta->headers;
        $h->set('X-Frame-Options', 'SAMEORIGIN', false);
        $h->set('Content-Security-Policy', "frame-ancestors 'self'; base-uri 'self'; object-src 'none'", false);
        $h->set('X-Content-Type-Options', 'nosniff', false);
        $h->set('Referrer-Policy', 'strict-origin-when-cross-origin', false);
        $h->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()', false);
        if ($request->isSecure()) {
            $h->set('Strict-Transport-Security', 'max-age=31536000', false);
        }
        $h->remove('X-Powered-By');

        return $resposta;
    }
}
