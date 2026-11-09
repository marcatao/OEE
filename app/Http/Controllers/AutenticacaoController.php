<?php

namespace App\Http\Controllers;

use App\Services\EntraId;
use App\Services\PerfilEntra;
use App\Services\RegistroDeLogin;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Login pelo Microsoft Entra ID. O Entra ID autentica; o portal grava o usuário e o login no banco.
 */
class AutenticacaoController extends Controller
{
    public function login(Request $request, EntraId $entra): Response
    {
        // O retorno do Entra ID vem para o endereço de ENTRA_REDIRECT_URI. Se o portal foi aberto por outro endereço
        // (ex.: 127.0.0.1 em vez de localhost), o cookie da sessão não volta junto e o login falha. Então primeiro
        // levamos o navegador para o mesmo endereço do retorno.
        // Compara só o nome (host): atrás de proxy a porta e o http/https da requisição podem ser outros. O parâmetro
        // "redirecionado" evita voltar aqui em laço se o proxy ainda entregar outro nome.
        $base = $this->baseDoRetorno();
        $hostDoRetorno = strtolower((string) parse_url((string) $base, PHP_URL_HOST));
        if ($base && $hostDoRetorno !== strtolower($request->getHost()) && ! $request->boolean('redirecionado')) {
            // Configurado só para "localhost", mas acessado por outra máquina: "localhost" lá é a própria máquina
            // do usuário, então o redirecionamento cairia numa página que não existe. Explica o que configurar.
            if ($this->ehLocal(parse_url($base, PHP_URL_HOST)) && ! $this->ehLocal($request->getHost())) {
                return $this->erro(
                    "Este portal está configurado para funcionar só no próprio servidor ({$base}). Para entrar a partir "
                    .'de outras máquinas, o administrador precisa configurar APP_URL e ENTRA_REDIRECT_URI com o endereço '
                    .'de rede do servidor, em HTTPS (veja a seção "Acessar de outras máquinas" do README).',
                    500,
                );
            }

            return redirect()->away($base.'/auth/login?redirecionado=1');
        }

        return $entra->redirecionarParaLogin();
    }

    public function callback(Request $request, EntraId $entra, RegistroDeLogin $registro, PerfilEntra $perfil): Response
    {
        if ($request->filled('error')) {
            $registro->falha(null, 'Entra ID: '.$request->string('error'));

            return $this->erro($request->string('error_description')->limit(300)->toString());
        }

        try {
            $identidade = $entra->usuarioAutenticado();
        } catch (Throwable $e) {
            [$motivo, $mensagem] = $this->diagnosticar($e);
            Log::warning('Falha no login Entra ID: '.$motivo, ['erro' => $e->getMessage(), 'tipo' => $e::class]);
            $registro->falha(null, $motivo);

            return $this->erro($mensagem);
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

        // Preenche setor, cargo, celular, matrícula e foto com o que o Entra ID tiver (só campos vazios).
        // O token fica na sessão, criptografado, para o botão "Atualizar com o Entra ID" do perfil.
        $perfil->sincronizar($usuario, $identidade->token);
        if ($identidade->token) {
            $request->session()->put('entra_token', Crypt::encryptString($identidade->token));
            $request->session()->put('entra_token_expira', $identidade->tokenExpiraEm ?? time() + 3000);
        }

        return redirect()->intended(route('inicio')); // "intended" só aceita URLs do próprio portal
    }

    public function logout(Request $request, EntraId $entra): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Volta para a página inicial com barra no final (ex.: https://www.alumbra.com.br/oee/): esse endereço precisa
        // estar cadastrado igualzinho nas URIs de redirecionamento do registro do aplicativo no Entra ID.
        return redirect()->away($entra->urlDeLogout(rtrim(url('/'), '/').'/'));
    }

    /**
     * Traduz a falha do retorno do Entra ID num motivo curto (gravado no histórico) e numa mensagem que diz o que
     * fazer. Nunca inclui segredos: só o código AADSTS e a causa provável.
     *
     * @return array{0: string, 1: string}
     */
    private function diagnosticar(Throwable $e): array
    {
        if ($e instanceof InvalidStateException) {
            return ['sessão do login perdida (state inválido)',
                'A sessão do login se perdeu no caminho. Abra o portal sempre pelo mesmo endereço configurado em '
                .'ENTRA_REDIRECT_URI ('.($this->baseDoRetorno() ?: 'APP_URL').'), confira se o navegador aceita cookies '
                .'e tente de novo.'];
        }

        $corpo = $e instanceof RequestException ? (string) $e->getResponse()?->getBody() : '';
        $retorno = json_decode($corpo, true) ?: [];
        $descricao = (string) ($retorno['error_description'] ?? '');
        if (preg_match('/AADSTS\d+/', $descricao, $m)) {
            $codigo = $m[0];
            $dica = match ($codigo) {
                'AADSTS7000215' => 'O segredo do cliente está errado: em ENTRA_CLIENT_SECRET use o "Valor" do segredo, não o "ID do segredo".',
                'AADSTS7000222' => 'O segredo do cliente expirou: crie um novo em Certificados e segredos e atualize ENTRA_CLIENT_SECRET.',
                'AADSTS700016' => 'O ID do aplicativo (ENTRA_CLIENT_ID) não foi encontrado neste locatário.',
                'AADSTS90002' => 'O locatário (ENTRA_TENANT_ID) não foi encontrado. Use o GUID do "ID do diretório (locatário)".',
                'AADSTS50011' => 'A URI de redirecionamento não confere com a cadastrada no registro do aplicativo.',
                'AADSTS501481' => 'O código PKCE não confere. Abra o portal pelo mesmo endereço de ENTRA_REDIRECT_URI e tente de novo.',
                'AADSTS54005', 'AADSTS70008' => 'O código de login expirou ou já foi usado. Tente entrar de novo.',
                default => strtok($descricao, "\r\n"),
            };

            return ["Entra ID: {$codigo}", "O Entra ID recusou o login ({$codigo}). {$dica}"];
        }

        if (str_contains($e->getMessage(), 'cURL error 60') || str_contains($e->getMessage(), 'SSL certificate')) {
            return ['falha de certificado SSL ao falar com a Microsoft',
                'O PHP deste servidor não conseguiu validar o certificado da Microsoft (cURL error 60). Configure '
                .'curl.cainfo e openssl.cafile no php.ini apontando para um arquivo cacert.pem (veja o README).'];
        }

        if ($e instanceof ConnectException) {
            return ['sem conexão com a Microsoft',
                'O servidor do portal não conseguiu se conectar à Microsoft (login.microsoftonline.com / graph.microsoft.com). '
                .'Verifique a internet, o proxy ou o firewall.'];
        }

        return ['falha ao validar o retorno do Entra ID',
            'Não foi possível concluir o login. Tente novamente. Se continuar, o administrador encontra o detalhe em '
            .'storage/logs/laravel.log.'];
    }

    /**
     * Endereço do portal a partir de ENTRA_REDIRECT_URI, mantendo a subpasta:
     * "https://www.alumbra.com.br/oee/auth/callback" → "https://www.alumbra.com.br/oee".
     */
    private function baseDoRetorno(): ?string
    {
        $url = parse_url((string) config('services.azure.redirect'));
        if (empty($url['scheme']) || empty($url['host'])) {
            return null;
        }
        $caminho = preg_replace('#/auth/callback/?$#', '', rtrim($url['path'] ?? '', '/'));

        return $url['scheme'].'://'.$url['host'].(isset($url['port']) ? ':'.$url['port'] : '').$caminho;
    }

    private function ehLocal(?string $host): bool
    {
        return in_array(strtolower((string) $host), ['localhost', '127.0.0.1', '::1', '[::1]'], true);
    }

    private function erro(string $mensagem, int $status = 401): Response
    {
        return response()->view('erro', ['mensagem' => $mensagem], $status);
    }
}
