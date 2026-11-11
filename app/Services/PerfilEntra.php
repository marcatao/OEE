<?php

namespace App\Services;

use App\Models\Usuario;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Lê do Microsoft Graph (/me) os dados de perfil do funcionário: setor, cargo, e-mail, celular, matrícula e foto, e os
 * dados da organização (departamento, local de escritório = centro de custo e gerente responsável).
 * Usa o token do próprio login (permissão delegada User.Read, que o aplicativo já tem; o gerente pode precisar de
 * User.ReadBasic.All, ver o README). Qualquer falha aqui é registrada no log e ignorada: nunca impede o login.
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

    /** Dados da organização: campo do Graph => coluna. Somente leitura no portal: sempre valem os do Entra ID. */
    private const ORGANIZACAO = [
        'department' => 'departamento',
        'officeLocation' => 'local_escritorio',
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
                ->get(self::GRAPH.'/me', ['$select' => 'department,jobTitle,mail,mobilePhone,employeeId,officeLocation'])
                ->throw()
                ->json();

            foreach (self::CAMPOS as $campoGraph => $coluna) {
                $valor = trim((string) ($dados[$campoGraph] ?? ''));
                if ($valor !== '' && ($sobrescrever || blank($usuario->{$coluna})) && $usuario->{$coluna} !== $valor) {
                    $usuario->{$coluna} = mb_substr($valor, 0, $coluna === 'email_contato' ? 320 : 120);
                    $alterados[] = $coluna;
                }
            }

            // Departamento e local de escritório (centro de custo): sempre os do Entra ID, mesmo vazios.
            foreach (self::ORGANIZACAO as $campoGraph => $coluna) {
                $valor = mb_substr(trim((string) ($dados[$campoGraph] ?? '')), 0, 120) ?: null;
                if ($valor !== $usuario->{$coluna}) {
                    $usuario->{$coluna} = $valor;
                    $alterados[] = $coluna;
                }
            }

            // Gerente responsável (/me/manager). Sem gerente no Entra ID (404) fica vazio; sem permissão (403) não mexe.
            $gerente = $this->gerente($token);
            if ($gerente !== false) {
                foreach (['gerente_nome' => $gerente['nome'] ?? null, 'gerente_email' => $gerente['email'] ?? null] as $coluna => $valor) {
                    if ($valor !== $usuario->{$coluna}) {
                        $usuario->{$coluna} = $valor;
                        $alterados[] = $coluna;
                    }
                }
            }

            // A matrícula é somente leitura no portal: vale sempre o que está no Entra ID.
            $matricula = trim((string) ($dados['employeeId'] ?? '')) ?: null;
            if ($matricula !== $usuario->matricula) {
                $usuario->matricula = $matricula ? mb_substr($matricula, 0, 60) : null;
                $alterados[] = 'matricula';
            }

            // Busca a foto se não houver, ou se o arquivo sumiu (ex.: troca de servidor mantendo o banco).
            if ($sobrescrever || ! $usuario->temFoto()) {
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

    /**
     * Gerente do funcionário no Entra ID: ['nome' => ..., 'email' => ...], [] se não tiver gerente, ou false se não
     * deu para ler (ex.: o aplicativo não tem a permissão para ler o gerente). Nunca lança erro.
     *
     * @return array{nome?: ?string, email?: ?string}|false
     */
    public function gerente(string $token): array|false
    {
        try {
            $resposta = $this->http($token)->get(self::GRAPH.'/me/manager', ['$select' => 'displayName,mail,userPrincipalName']);
            if ($resposta->status() === 404) {
                return [];
            }
            if (! $resposta->successful()) {
                Log::info('Gerente não lido no Microsoft Graph', ['status' => $resposta->status()]);

                return false;
            }
            $g = $resposta->json();

            return [
                'nome' => mb_substr(trim((string) ($g['displayName'] ?? '')), 0, 200) ?: null,
                'email' => mb_substr(trim((string) (($g['mail'] ?? '') ?: ($g['userPrincipalName'] ?? ''))), 0, 320) ?: null,
            ];
        } catch (Throwable $e) {
            Log::info('Gerente não lido no Microsoft Graph', ['erro' => $e->getMessage()]);

            return false;
        }
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
