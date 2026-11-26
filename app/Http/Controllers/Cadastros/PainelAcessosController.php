<?php

namespace App\Http\Controllers\Cadastros;

use App\Http\Controllers\Controller;
use App\Services\Seguranca\PainelAcessos;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Segurança › Painel de logins (permissão `seguranca_painel`). */
class PainelAcessosController extends Controller
{
    public const PERIODOS = [7, 30, 90];

    public function __invoke(Request $request, PainelAcessos $painel): View
    {
        $dias = in_array((int) $request->query('dias'), self::PERIODOS, true) ? (int) $request->query('dias') : 30;
        $semUso = (int) $request->query('sem_uso', config('oee.acessos_paginas.dias_sem_uso', 60));
        $semUso = max(1, min(3650, $semUso));
        $mostrar = in_array($request->query('mostrar'), ['nunca', 'sem_uso', 'hoje'], true) ? $request->query('mostrar') : null;

        $r = $painel->resumo($dias, $semUso);

        return view('cadastros.painel-acessos', $r + [
            'dias' => $dias,
            'semUsoDias' => $semUso,
            'mostrar' => $mostrar,
            'lista' => $mostrar ? $r['usuarios']->where('situacao', $mostrar)->values() : $r['usuarios'],
            'periodos' => self::PERIODOS,
            'rotulosPaginas' => self::rotulosPaginas(),
        ]);
    }

    /** Nome amigável das rotas mais comuns (o resto mostra o nome da rota). */
    public static function rotulosPaginas(): array
    {
        return [
            'inicio' => 'Página inicial', 'timeline' => 'Timeline', 'dashboard.maquinas' => 'Dashboard por máquina',
            'producao-hora' => 'Produção por hora', 'perfil' => 'Meu perfil',
            'cadastros.usuarios.index' => 'Segurança › Usuários', 'cadastros.acessos' => 'Segurança › Histórico de logins',
            'cadastros.painel-acessos' => 'Segurança › Painel de logins', 'cadastros.acessos-paginas' => 'Segurança › Acesso às páginas',
            'cadastros.sessoes' => 'Segurança › Sessões ativas',
        ];
    }
}
