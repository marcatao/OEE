<?php

namespace App\Services;

use App\Models\Usuario;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Lê do Microsoft Graph (/me) os dados de perfil do funcionário: setor, cargo, e-mail, celular, matrícula e foto.
 * Usa o token do próprio login (permissão delegada User.Read, que o aplicativo já tem). Qualquer falha aqui é
 * registrada no log e ignorada: nunca impede o login.
 */
class PerfilEntra
{
    private const GRAPH = 'https://graph.microsoft.com/v1.0';

    /** Campo do Graph => coluna em "usuarios". */
    private const CAMPOS = [
        'department' => 'setor',
        'jobTitle' => 'cargo',
        'mail' => 'email_contato',
        'mobilePhone' => 'celular',
    ];

    /**
     * Copia para o usuário o que o Entra ID tiver. Por padrão só preenche campos vazios (não apaga o que o
     * funcionário digitou); com $sobrescrever = true troca tudo pelo que está no Entra ID.
     *
     * @return list<string> nomes dos campos atualizados
     */
    public function sincronizar(Usuario $usuario, ?string $token, bool $sobrescrever = false): array
    {
        if (! $token) {
            return [];
        }

        $alterados = [];
        try {
            $dados = $this->http($token)
                ->get(self::GRAPH.'/me', ['$select' => 'department,jobTitle,mail,mobilePhone,employeeId'])
                ->throw()
                ->json();

            foreach (self::CAMPOS as $campoGraph => $coluna) {
                $valor = trim((string) ($dados[$campoGraph] ?? ''));
                if ($valor !== '' && ($sobrescrever || blank($usuario->{$coluna})) && $usuario->{$coluna} !== $valor) {
                    $usuario->{$coluna} = mb_substr($valor, 0, $coluna === 'email_contato' ? 320 : 120);
                    $alterados[] = $coluna;
                }
            }

            // A matrícula é somente leitura no portal: vale sempre o que está no Entra ID.
            $matricula = trim((string) ($dados['employeeId'] ?? '')) ?: null;
            if ($matricula !== $usuario->matricula) {
                $usuario->matricula = $matricula ? mb_substr($matricula, 0, 60) : null;
                $alterados[] = 'matricula';
            }

            if ($sobrescrever || ! $usuario->foto) {
                $foto = $this->foto($token);
                if ($foto !== null) {
                    $this->guardarFoto($usuario, $foto, 'jpg');
                    $alterados[] = 'foto';
                }
            }

            $usuario->perfil_entra_em = now();
            $usuario->save();
        } catch (Throwable $e) {
            Log::warning('Não foi possível ler o perfil no Microsoft Graph', ['erro' => $e->getMessage(), 'usuario' => $usuario->id]);
        }

        return $alterados;
    }

    /** Foto do Entra ID (JPEG) ou null se o funcionário não tiver foto. */
    public function foto(string $token): ?string
    {
        foreach (['/me/photos/240x240/$value', '/me/photo/$value'] as $caminho) {
            $resposta = $this->http($token)->get(self::GRAPH.$caminho);
            if ($resposta->successful() && $resposta->body() !== '') {
                return $resposta->body();
            }
        }

        return null;
    }

    /** Grava a foto em storage/app/private/fotos e aponta o usuário para ela. */
    public function guardarFoto(Usuario $usuario, string $conteudo, string $extensao): void
    {
        $disco = Storage::disk('local');
        if ($usuario->foto && $disco->exists($usuario->foto)) {
            $disco->delete($usuario->foto);
        }

        $caminho = "fotos/usuario-{$usuario->id}-".now()->format('YmdHis').'.'.$extensao;
        $disco->put($caminho, $conteudo);
        $usuario->foto = $caminho;
        $usuario->foto_atualizada_em = now();
    }

    public function removerFoto(Usuario $usuario): void
    {
        if ($usuario->foto) {
            Storage::disk('local')->delete($usuario->foto);
        }
        $usuario->foto = null;
        $usuario->foto_atualizada_em = now();
    }

    private function http(string $token)
    {
        $http = Http::withToken($token)->acceptJson()->timeout(10);
        if ($proxy = config('services.azure.proxy')) {
            $http = $http->withOptions(['proxy' => $proxy]);
        }

        return $http;
    }
}
