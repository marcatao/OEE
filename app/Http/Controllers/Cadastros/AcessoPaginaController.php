<?php

namespace App\Http\Controllers\Cadastros;

use App\Http\Controllers\Controller;
use App\Models\AcessoPagina;
use App\Models\Usuario;
use App\Services\Seguranca\PainelAcessos;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Segurança › Acesso às páginas (permissão `seguranca_paginas`): quem abriu qual tela e quando (`acessos_paginas`).
 * Sem período informado, mostra os últimos 7 dias (a consulta sempre usa o índice por data).
 */
class AcessoPaginaController extends Controller
{
    public function __invoke(Request $request): View
    {
        $f = [
            'de' => (string) $request->query('de', now(config('oee.fuso_exibicao'))->subDays(6)->format('Y-m-d')),
            'ate' => (string) $request->query('ate'),
            'usuario' => (int) $request->query('usuario') ?: null,
            'pagina' => trim((string) $request->query('pagina')),
            'ip' => trim((string) $request->query('ip')),
        ];
        [$inicio, $fim] = PainelAcessos::periodo($f['de'], $f['ate']);
        $inicio ??= PainelAcessos::inicioDosDias(7);

        $acessos = AcessoPagina::with('usuario:id,nome,email')
            ->where('data_hora', '>=', $inicio)
            ->when($fim, fn ($q) => $q->where('data_hora', '<', $fim))
            ->when($f['usuario'], fn ($q) => $q->where('usuario_id', $f['usuario']))
            ->when($f['pagina'] !== '', fn ($q) => $q->where(fn ($w) => $w->where('caminho', 'like', '%'.$f['pagina'].'%')
                ->orWhere('rota', 'like', '%'.$f['pagina'].'%')))
            ->when($f['ip'] !== '', fn ($q) => $q->where('ip', 'like', $f['ip'].'%'))
            ->orderByDesc('data_hora')->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('cadastros.acessos-paginas', [
            'acessos' => $acessos,
            'f' => $f,
            'usuarios' => Usuario::orderBy('nome')->get(['id', 'nome', 'email']),
            'retencao' => (int) config('oee.acessos_paginas.dias_retencao', 180),
            'rotulos' => PainelAcessosController::rotulosPaginas(),
        ]);
    }
}
