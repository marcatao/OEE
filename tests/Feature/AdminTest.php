<?php

namespace Tests\Feature;

use App\Models\Planta;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(string $perfil, string $email): Usuario
    {
        return Usuario::create(['entra_oid' => "oid-{$email}", 'nome' => ucfirst(strtok($email, '@')), 'email' => $email, 'perfil' => $perfil]);
    }

    public function test_admin_ve_banco_e_usuarios(): void
    {
        $admin = $this->usuario('admin', 'admin@empresa.com');

        $this->actingAs($admin)->get('/admin/banco')->assertOk()->assertSee('Conexão OK')->assertSee('maquinas');
        $this->actingAs($admin)->get('/admin/usuarios')->assertOk()->assertSee('admin@empresa.com');
    }

    public function test_operador_e_supervisor_nao_acessam_admin(): void
    {
        foreach (['operador', 'supervisor'] as $perfil) {
            $this->actingAs($this->usuario($perfil, "{$perfil}@empresa.com"))->get('/admin/banco')->assertForbidden();
        }
    }

    public function test_admin_muda_perfil_e_desativa_usuario(): void
    {
        $admin = $this->usuario('admin', 'admin@empresa.com');
        $maria = $this->usuario('operador', 'maria@empresa.com');

        $this->actingAs($admin)->put("/admin/usuarios/{$maria->id}", ['perfil' => 'supervisor', 'ativo' => '0'])
            ->assertRedirect()->assertSessionHas('ok');

        $maria->refresh();
        $this->assertSame('supervisor', $maria->perfil);
        $this->assertFalse($maria->ativo);
    }

    public function test_admin_nao_tira_o_proprio_acesso(): void
    {
        $admin = $this->usuario('admin', 'admin@empresa.com');

        $this->actingAs($admin)->put("/admin/usuarios/{$admin->id}", ['perfil' => 'operador', 'ativo' => '1'])
            ->assertSessionHas('erro');
        $this->assertSame('admin', $admin->refresh()->perfil);
    }

    public function test_perfil_invalido_e_rejeitado(): void
    {
        $admin = $this->usuario('admin', 'admin@empresa.com');
        $maria = $this->usuario('operador', 'maria@empresa.com');

        $this->actingAs($admin)->put("/admin/usuarios/{$maria->id}", ['perfil' => 'dono', 'ativo' => '1'])
            ->assertSessionHasErrors('perfil');
    }

    public function test_alteracao_sem_token_csrf_e_bloqueada_fora_dos_testes(): void
    {
        // O Laravel desliga a checagem de CSRF nos testes; aqui só garantimos que a rota é PUT (não GET).
        $admin = $this->usuario('admin', 'admin@empresa.com');
        $this->actingAs($admin)->get("/admin/usuarios/{$admin->id}")->assertStatus(405);
    }

    public function test_auditoria_grava_quem_criou(): void
    {
        $admin = $this->usuario('admin', 'admin@empresa.com');
        $this->actingAs($admin);

        $planta = Planta::create(['codigo' => 'P1', 'nome' => 'Planta 1']);
        $this->assertSame($admin->id, $planta->criado_por_id);
        $this->assertNotNull($planta->criado_em);
    }
}
