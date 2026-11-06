<?php

namespace Tests\Feature;

use Tests\TestCase;

class DocumentarBancoTest extends TestCase
{
    public function test_gera_documentacao_com_diagrama_chaves_e_origem_e_preserva_o_trecho_manual(): void
    {
        $arquivo = 'storage/framework/testing/banco-de-dados.md';
        @mkdir(base_path(dirname($arquivo)), 0775, true);
        file_put_contents(base_path($arquivo), "x\n<!-- manual:inicio -->\nDecisão de teste\n<!-- manual:fim -->\n");

        $this->artisan('banco:documentar', ['--saida' => $arquivo])->assertSuccessful();
        $md = file_get_contents(base_path($arquivo));

        $this->assertStringContainsString('```mermaid', $md);
        $this->assertStringContainsString('linhas ||--o{ maquinas : "linha_id"', $md);
        $this->assertStringContainsString('centros_custo |o..o{ maquinas', $md); // ligação por código, sem FK
        $this->assertStringContainsString('| `entra_oid` | `nvarchar(36)` | não |', $md); // tipos do SQL Server
        $this->assertStringContainsString('`grupo_id, usuario_id`', $md); // PK composta
        $this->assertStringContainsString('Protheus — sincronizada de', $md);
        $this->assertStringContainsString('Decisão de teste', $md);
        $this->assertStringNotContainsString('criado_por_id"', $md); // auditoria fora do diagrama

        @unlink(base_path($arquivo));
    }
}
