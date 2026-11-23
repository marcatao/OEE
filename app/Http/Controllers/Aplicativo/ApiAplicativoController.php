<?php

namespace App\Http\Controllers\Aplicativo;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Services\Aplicativo\LoginAplicativo;
use App\Services\Aplicativo\VersoesAplicativo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** API do aplicativo Android: login (troca do código), dados do usuário e logout. */
class ApiAplicativoController extends Controller
{
    public function token(Request $request, LoginAplicativo $login): JsonResponse
    {
        $dados = $request->validate([
            'codigo' => ['required', 'string', 'max:100'],
            'verificador' => ['required', 'string', 'min:43', 'max:128'],
            'dispositivo' => ['nullable', 'string', 'max:200'],
            'versao' => ['nullable', 'string', 'max:40'],
        ]);
        $resultado = $login->trocarCodigo($dados['codigo'], $dados['verificador'], $dados['dispositivo'] ?? null, $dados['versao'] ?? null);
        if (! $resultado) {
            return response()->json(['erro' => 'codigo_invalido', 'mensagem' => 'O login expirou. Toque em Entrar de novo.'], 422);
        }

        return response()->json(['token' => $resultado['token'], ...$this->dadosUsuario($resultado['usuario'])]);
    }

    public function eu(Request $request): JsonResponse
    {
        return response()->json($this->dadosUsuario($request->user()));
    }

    public function sair(Request $request): JsonResponse
    {
        $request->attributes->get('token_aplicativo')->forceFill(['revogado_em' => now()])->save();

        return response()->json(['ok' => true]);
    }

    /** Versão mais nova do app no portal (?instalado=build do celular): se há nova e se é obrigatória. */
    public function versao(Request $request, VersoesAplicativo $versoes): JsonResponse
    {
        $instalado = $request->filled('instalado') ? max(0, (int) $request->query('instalado')) : null;

        return response()->json($versoes->paraOApp($instalado));
    }

    /** O APK da versão mais nova (o app confere o SHA-256 e pede a instalação). */
    public function apk(VersoesAplicativo $versoes): StreamedResponse
    {
        $atual = $versoes->atual();
        abort_unless($atual !== null, 404, 'Nenhuma versão publicada.');

        return Storage::disk('local')->download($atual->arquivo, $atual->nomeArquivo(), [
            'Content-Type' => 'application/vnd.android.package-archive',
            'X-Versao-Codigo' => (string) $atual->codigo,
            'X-Sha256' => $atual->sha256,
        ]);
    }

    /** O que o app precisa saber do usuário: quem é, perfis, áreas e quais menus mostra (mesmas permissões do portal). */
    private function dadosUsuario(Usuario $usuario): array
    {
        $perfis = Usuario::perfisDisponiveis();
        $permissoes = array_values(array_filter(array_keys(config('oee.permissoes')), fn ($p) => $usuario->pode($p)));

        return [
            'servidor_agora' => now()->toIso8601String(), // UTC: o app compara com o relógio do celular
            'horas_offline' => (int) config('oee.app.horas_offline', 48),
            'usuario' => [
                'id' => $usuario->id,
                'nome' => $usuario->nome,
                'primeiro_nome' => $usuario->primeiroNome(),
                'email' => $usuario->email,
                'setor' => $usuario->setor,
                'perfis' => array_map(fn ($p) => ['chave' => $p, 'rotulo' => $perfis[$p]['rotulo'] ?? $p], $usuario->perfisAcesso()),
                'unidades' => $usuario->unidades(),
                'permissoes' => $permissoes,
                // Máquinas: só o perfil Operador; Ordens de serviço: só os perfis de Mantenedor (a API confere igual).
                'funcoes' => [
                    'operador' => $usuario->temPerfil('operador') && $usuario->pode('apontamentos'),
                    'timeline' => $usuario->pode('timeline'),
                    'mantenedor' => $usuario->temPerfil(...array_values(config('oee.mantenedores_area'))),
                    'aprovar_os' => $usuario->pode('os_aprovar'),
                    'solicitar_os' => $usuario->pode('os_solicitar'),
                ],
            ],
        ];
    }
}
