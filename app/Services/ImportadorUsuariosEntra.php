<?php

namespace App\Services;

use App\Models\Usuario;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Cadastra no portal todas as contas do Entra ID que ainda não existem, já BLOQUEADAS (inativas e sem perfil): quem
 * tentar entrar recebe "acesso desativado" até um Administrador de Tecnologia ativar e dar os perfis.
 *
 * Lê o Microsoft Graph com o token do PRÓPRIO aplicativo (client credentials), que precisa da permissão de APLICATIVO
 * "User.Read.All" com consentimento do administrador no registro do aplicativo (veja o README).
 * Usuário que já existe no portal NUNCA é alterado. Só entram membros da organização com conta habilitada (sem
 * convidados #EXT#), e os e-mails de config('oee.admins') ficam de fora (nascem administradores no primeiro login).
 */
class ImportadorUsuariosEntra
{
    private const GRAPH = 'https://graph.microsoft.com/v1.0';

    private const CAMPOS = 'id,displayName,mail,userPrincipalName,accountEnabled,userType,department,jobTitle,officeLocation,mobilePhone,employeeId';

    /** @return array{lidos: int, criados: int, existentes: int, ignorados: int} */
    public function importar(): array
    {
        $http = $this->http($this->tokenDoAplicativo());
        $existentes = Usuario::pluck('entra_oid')->map(fn ($oid) => Str::lower((string) $oid))->flip();
        $admins = array_flip(config('oee.admins'));
        $resumo = ['lidos' => 0, 'criados' => 0, 'existentes' => 0, 'ignorados' => 0];

        $url = self::GRAPH.'/users?'.http_build_query(['$select' => self::CAMPOS, '$top' => 999]);
        $paginas = 0;
        while ($url && $paginas++ < 200) {
            $resposta = $http->get($url);
            if ($resposta->status() === 403) {
                throw new RuntimeException('O aplicativo não tem a permissão "User.Read.All" (de aplicativo, com consentimento do administrador) no Entra ID.');
            }
            $dados = $resposta->throw()->json();

            foreach ($dados['value'] ?? [] as $conta) {
                $resumo['lidos']++;
                $oid = Str::lower((string) ($conta['id'] ?? ''));
                $upn = (string) ($conta['userPrincipalName'] ?? '');
                $email = Str::lower(trim((string) ($conta['mail'] ?? '') ?: $upn));
                if (isset($existentes[$oid])) {
                    $resumo['existentes']++;

                    continue;
                }
                if ($oid === '' || $email === '' || ($conta['accountEnabled'] ?? true) === false
                    || ($conta['userType'] ?? 'Member') !== 'Member' || str_contains($upn, '#EXT#') || isset($admins[$email])) {
                    $resumo['ignorados']++;

                    continue;
                }

                $texto = fn (string $campo, int $max = 120) => mb_substr(trim((string) ($conta[$campo] ?? '')), 0, $max) ?: null;
                $usuario = new Usuario([
                    'entra_oid' => $oid,
                    'entra_tenant_id' => config('services.azure.tenant'),
                    'nome' => mb_substr(trim((string) ($conta['displayName'] ?? '')) ?: $email, 0, 255),
                    'email' => mb_substr($email, 0, 255),
                    'perfil' => 'sem_acesso',
                    'ativo' => false, // bloqueado até a liberação
                    'setor' => $texto('department'),
                    'cargo' => $texto('jobTitle'),
                    'departamento' => $texto('department'),
                    'local_escritorio' => $texto('officeLocation'),
                    'celular' => $texto('mobilePhone'),
                ]);
                $usuario->matricula = $texto('employeeId', 60);
                $usuario->save();
                $existentes[$oid] = true;
                $resumo['criados']++;
            }
            $url = $dados['@odata.nextLink'] ?? null;
        }

        return $resumo;
    }

    private function tokenDoAplicativo(): string
    {
        $tenant = (string) config('services.azure.tenant');
        if ($tenant === '' || ! config('services.azure.client_id') || ! config('services.azure.client_secret')) {
            throw new RuntimeException('Entra ID não configurado (ENTRA_TENANT_ID, ENTRA_CLIENT_ID e ENTRA_CLIENT_SECRET no .env).');
        }
        $resposta = $this->http()->asForm()->post("https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token", [
            'grant_type' => 'client_credentials',
            'client_id' => config('services.azure.client_id'),
            'client_secret' => config('services.azure.client_secret'),
            'scope' => 'https://graph.microsoft.com/.default',
        ]);
        $token = $resposta->json('access_token');
        if (! $resposta->successful() || ! is_string($token)) {
            throw new RuntimeException('O Entra ID recusou o token do aplicativo: '.Str::limit((string) ($resposta->json('error_description') ?? $resposta->status()), 200));
        }

        return $token;
    }

    private function http(?string $token = null)
    {
        $http = Http::acceptJson()->timeout(30);
        if ($token) {
            $http = $http->withToken($token);
        }
        if ($proxy = config('services.azure.proxy')) {
            $http = $http->withOptions(['proxy' => $proxy]);
        }

        return $http;
    }
}
