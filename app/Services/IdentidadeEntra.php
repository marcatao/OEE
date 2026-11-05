<?php

namespace App\Services;

/** Dados do usuário autenticado pelo Entra ID. */
final readonly class IdentidadeEntra
{
    public function __construct(
        public string $oid,
        public ?string $tenantId,
        public ?string $nome,
        public ?string $email,
        public ?string $token = null,       // token de acesso ao Microsoft Graph (User.Read), para ler o perfil
        public ?int $tokenExpiraEm = null,  // timestamp
    ) {}
}
