<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Portal numa subpasta do IIS (APP_URL=https://www.alumbra.com.br/oee): aberto SEM a barra no final
 * (".../oee"), o Laravel não percebe a subpasta e procura uma página "/oee", que não existe ("Página não
 * encontrada"). Aqui esse endereço é levado para ".../oee/". Os links do portal para o Início também saem sem a
 * barra, então passam por aqui.
 */
class RaizDaSubpasta
{
    public function handle(Request $request, Closure $next): Response
    {
        $subpasta = rtrim((string) parse_url((string) config('app.url'), PHP_URL_PATH), '/');

        if ($subpasta !== '' && $request->getBaseUrl() === '' && rtrim($request->getPathInfo(), '/') === $subpasta) {
            $destino = rtrim((string) config('app.url'), '/').'/';
            $consulta = $request->getQueryString();

            return redirect()->to($destino.($consulta ? '?'.$consulta : ''), 301);
        }

        return $next($request);
    }
}
