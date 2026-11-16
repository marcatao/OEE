<?php

namespace App\Http\Controllers\Aplicativo;

use App\Http\Controllers\Controller;
use App\Services\Aplicativo\LoginAplicativo;
use App\Services\EntraId;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Login do aplicativo Android pelo portal (ver App\Services\Aplicativo\LoginAplicativo). O celular abre estas
 * páginas no navegador; o portal autentica pelo Entra ID e devolve um código para o app.
 * O primeiro acesso de um usuário tem de ser pelo portal: quem foi cadastrado agora, por este login, é recusado.
 */
class LoginAplicativoController extends Controller
{
    public function entrar(Request $request, EntraId $entra): Response
    {
        $dados = $request->validate(['desafio' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{43}$/']]);

        // Sempre um login novo: o celular pode ter sido usado por outra pessoa.
        if (Auth::check()) {
            Auth::logout();
        }
        $request->session()->put('app_login', ['desafio' => $dados['desafio'], 'inicio' => now()->getTimestamp()]);
        $request->session()->put('url.intended', route('app.entrar.concluir'));

        return $entra->redirecionarParaLogin(pedirSenha: true);
    }

    public function concluir(Request $request, LoginAplicativo $login): Response
    {
        $pedido = $request->session()->pull('app_login');
        $usuario = $request->user();

        // A sessão do navegador do celular não fica aberta: o app usa só o token.
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if (! $pedido) {
            return $this->erro('O pedido de login do aplicativo se perdeu. Volte ao aplicativo e toque em Entrar de novo.');
        }
        $inicio = Carbon::createFromTimestamp($pedido['inicio']);
        if ($usuario->criado_em && $usuario->criado_em->gte($inicio->copy()->subSeconds(5))) {
            return $this->erro('Seu primeiro acesso deve ser pelo portal '.config('oee.nome').' no computador. '
                .'Entre no portal, peça a liberação do seu acesso e depois entre no aplicativo.');
        }
        if (! $usuario->temAcesso()) {
            return $this->erro('Seu usuário ainda não tem acesso liberado no portal. Peça a liberação a um administrador.');
        }

        $codigo = $login->criarCodigo($usuario, $pedido['desafio']);

        return response()->view('aplicativo.voltar', [
            'endereco' => LoginAplicativo::ESQUEMA_RETORNO.'?codigo='.urlencode($codigo),
            'nome' => $usuario->primeiroNome(),
        ]);
    }

    private function erro(string $mensagem): Response
    {
        return response()->view('aplicativo.voltar', [
            'endereco' => LoginAplicativo::ESQUEMA_RETORNO.'?erro='.rawurlencode($mensagem),
            'erro' => $mensagem,
        ], 403);
    }
}
