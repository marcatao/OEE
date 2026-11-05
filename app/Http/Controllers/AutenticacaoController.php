<?php

namespace App\Http\Controllers;

use App\Services\EntraId;
use App\Services\RegistroDeLogin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Login pelo Microsoft Entra ID. O Entra ID autentica; o portal grava o usuário e o login no banco.
 */
class AutenticacaoController extends Controller
{
    public function login(EntraId $entra): Response
    {
        return $entra->redirecionarParaLogin();
    }

    public function callback(Request $request, EntraId $entra, RegistroDeLogin $registro): Response
    {
        if ($request->filled('error')) {
            $registro->falha(null, 'Entra ID: '.$request->string('error'));

            return $this->erro($request->string('error_description')->limit(300)->toString());
        }

        try {
            $identidade = $entra->usuarioAutenticado();
        } catch (Throwable $e) {
            Log::warning('Falha no login Entra ID', ['erro' => $e->getMessage()]);
            $registro->falha(null, 'falha ao validar o retorno do Entra ID');

            return $this->erro('Não foi possível concluir o login. Tente novamente.');
        }

        $tenant = Str::lower((string) config('services.azure.tenant'));
        if ($identidade->oid === '' || (Str::isUuid($tenant) && Str::lower((string) $identidade->tenantId) !== $tenant)) {
            $registro->falha($identidade->email, 'token de outro locatário ou sem oid');
            abort(403, 'Sua conta não pertence à organização deste portal.');
        }

        $usuario = $registro->sucesso($identidade);
        abort_if($usuario === null, 403, 'Seu acesso ao portal está desativado.');

        Auth::login($usuario);
        $request->session()->regenerate(); // nova sessão após autenticar (evita fixação de sessão)

        return redirect()->intended(route('inicio')); // "intended" só aceita URLs do próprio portal
    }

    public function logout(Request $request, EntraId $entra): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->away($entra->urlDeLogout(route('inicio')));
    }

    private function erro(string $mensagem): Response
    {
        return response()->view('erro', ['mensagem' => $mensagem], 401);
    }
}
