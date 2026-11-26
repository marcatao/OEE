<?php

namespace App\Http\Controllers\Cadastros;

use App\Http\Controllers\Controller;
use App\Models\Aplicativo\TokenAplicativo;
use App\Services\Seguranca\SessoesAtivas;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Segurança › Sessões ativas: quem está conectado no portal e no app, com "Desconectar". Permissão `seguranca_sessoes`. */
class SessaoController extends Controller
{
    public function index(Request $request, SessoesAtivas $sessoes): View
    {
        return view('cadastros.sessoes', [
            'portalDisponivel' => $sessoes->portalDisponivel(),
            'portal' => $sessoes->portal($request->session()->getId()),
            'celulares' => $sessoes->celulares(),
            'minutos' => (int) config('session.lifetime', 120),
        ]);
    }

    public function encerrar(Request $request, string $chave, SessoesAtivas $sessoes): RedirectResponse
    {
        return match ($sessoes->encerrar($chave, $request->session()->getId())) {
            'ok' => back()->with('ok', 'Sessão desconectada: o navegador vai pedir login de novo.'),
            'propria' => back()->with('erro', 'Esta é a sua própria sessão: use "Sair" no topo.'),
            'indisponivel' => back()->with('erro', 'As sessões do portal não ficam no banco (SESSION_DRIVER diferente de database).'),
            default => back()->with('erro', 'A sessão já tinha terminado.'),
        };
    }

    /** Desconecta um celular (o app pede login de novo). */
    public function revogarCelular(int $token): RedirectResponse
    {
        TokenAplicativo::whereKey($token)->whereNull('revogado_em')->firstOrFail()->forceFill(['revogado_em' => now()])->save();

        return back()->with('ok', 'Celular desconectado: o aplicativo vai pedir login de novo.');
    }
}
