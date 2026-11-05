<?php

namespace App\Services;

use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Conversa com o Microsoft Entra ID (OpenID Connect, authorization code + PKCE, via Socialite).
 * O Entra ID só autentica: o portal usa dele apenas oid, tenant, nome e e-mail.
 */
class EntraId
{
    public function redirecionarParaLogin(): RedirectResponse
    {
        return $this->driver()->redirect();
    }

    /**
     * Troca o código de autorização pelos tokens (valida o "state") e devolve quem entrou.
     */
    public function usuarioAutenticado(): IdentidadeEntra
    {
        $usuario = $this->driver()->user();

        // O ID token veio direto do endpoint de token da Microsoft por TLS (OIDC Core 3.1.3.7);
        // usamos dele só a claim "tid" para conferir o locatário.
        $claims = $this->claimsDoIdToken($usuario->accessTokenResponseBody['id_token'] ?? null);

        return new IdentidadeEntra(
            oid: (string) $usuario->getId(),
            tenantId: $claims['tid'] ?? null,
            nome: $usuario->getName(),
            email: $usuario->user['mail'] ?? $usuario->getEmail(),
        );
    }

    public function urlDeLogout(string $voltarPara): string
    {
        $tenant = config('services.azure.tenant');

        return "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/logout?"
            .http_build_query(['post_logout_redirect_uri' => $voltarPara]);
    }

    private function driver()
    {
        return Socialite::driver('azure')->enablePKCE()->scopes(['openid', 'profile', 'email']);
    }

    private function claimsDoIdToken(?string $idToken): array
    {
        $partes = explode('.', (string) $idToken);
        if (count($partes) !== 3) {
            return [];
        }

        return json_decode(base64_decode(strtr($partes[1], '-_', '+/')), true) ?: [];
    }
}
