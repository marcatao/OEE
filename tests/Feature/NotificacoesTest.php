<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Sino de notificações e botão de tema do topo (pedido do usuário em 26/11/2026). */
class NotificacoesTest extends TestCase
{
    use RefreshDatabase;

    public function test_topo_tem_tema_e_sino_e_a_rota_devolve_json_so_do_logado(): void
    {
        $u = Usuario::create(['entra_oid' => 'oid-a', 'nome' => 'Ana', 'email' => 'ana@empresa.com', 'perfil' => 'admin']);
        $this->actingAs($u->fresh())->get('/')->assertOk()
            ->assertSee('data-alternar-tema', false)->assertSee('data-notificacoes', false)
            ->assertSee("localStorage.getItem('oee-tema')", false)
            ->assertSee('Nenhuma notificação.');
        $this->getJson(route('notificacoes'))->assertOk()->assertExactJson(['total' => 0, 'itens' => []]);

        // Sem login: não abre.
        auth()->logout();
        $this->getJson(route('notificacoes'))->assertUnauthorized();
    }

    public function test_usuario_sem_perfil_tambem_tem_o_sino(): void
    {
        $u = Usuario::create(['entra_oid' => 'oid-b', 'nome' => 'Bia', 'email' => 'bia@empresa.com']);
        $this->actingAs($u->fresh())->getJson(route('notificacoes'))->assertOk()->assertJsonPath('total', 0);
    }
}
