<?php

namespace App\Http\Controllers;

use App\Services\Notificacoes\NotificacoesUsuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Sino do topo: as notificações do usuário logado, em JSON (o navegador consulta a cada minuto). */
class NotificacaoController extends Controller
{
    public function index(Request $request, NotificacoesUsuario $notificacoes): JsonResponse
    {
        $itens = $notificacoes->para($request->user());

        return response()->json(['total' => count($itens), 'itens' => $itens])->header('Cache-Control', 'no-store');
    }
}
