<?php

namespace Tests\Feature;

use App\Models\Apontamento;
use App\Models\DispositivoIot;
use App\Models\Grupo;
use App\Models\Linha;
use App\Models\Maquina;
use App\Models\Planta;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PainelTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(string $perfil, string $email, array $permissoes = []): Usuario
    {
        $u = Usuario::create(['entra_oid' => "oid-{$email}", 'nome' => ucfirst(strtok($email, '@')).' Silva', 'email' => $email, 'perfil' => $perfil]);
        if ($permissoes) {
            $grupo = Grupo::create(['nome' => 'Grupo '.$email, 'ativo' => true]);
            $grupo->definirPermissoes($permissoes);
            $grupo->usuarios()->attach($u->id);
        }

        return $u;
    }

    private function maquina(): Maquina
    {
        $planta = Planta::create(['codigo' => 'MATRIZ', 'nome' => 'Planta Matriz']);
        $linha = Linha::create(['planta_id' => $planta->id, 'codigo' => 'L1', 'nome' => 'Linha 1']);

        return Maquina::create(['linha_id' => $linha->id, 'codigo' => 'INJ-01', 'nome' => 'Injetora 01', 'ciclo_ideal_s' => 1]);
    }

    public function test_inicio_tem_logo_menu_recolhivel_e_atalhos_do_usuario(): void
    {
        $this->actingAs($this->usuario('operador', 'op@empresa.com', ['calculadora']))
            ->get('/')->assertOk()
            ->assertSee('img/alumbra-logo.svg')->assertSee('img/alumbra-icone.svg')
            ->assertSee('Recolher menu')->assertSee('Acesso rápido')->assertSee('Calculadora de OEE')
            ->assertDontSee('Dashboard por máquina')->assertDontSee('Plantas e linhas');
    }

    public function test_menu_do_admin_tem_producao_e_administracao(): void
    {
        $this->actingAs($this->usuario('admin', 'admin@empresa.com'))->get('/')->assertOk()
            ->assertSeeInOrder(['Dashboards', 'Dashboard por máquina', 'Timeline', 'Produção', 'Apontamento real', 'Apontamentos OP', 'Ordens de produção',
                'Legendas', 'Calculadora de OEE', 'Administração', 'Máquinas', 'Plantas e linhas', 'Dispositivos IoT',
                'Segurança', 'Usuários', 'Grupos', 'Histórico de acessos', 'Banco de dados']);
    }

    public function test_quem_nao_e_admin_nao_acessa_os_cadastros_novos(): void
    {
        $maquina = $this->maquina();
        $this->actingAs($this->usuario('operador', 'op@empresa.com'));

        foreach (['/admin/maquinas', '/admin/maquinas/nova', "/admin/maquinas/{$maquina->id}/editar", '/admin/plantas-e-linhas',
            '/admin/dispositivos-iot', '/admin/dispositivos-iot/novo'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post('/admin/maquinas', [])->assertForbidden();
        $this->post('/admin/dispositivos-iot', [])->assertForbidden();
    }

    public function test_operador_sem_o_grupo_nao_abre_o_dashboard(): void
    {
        $maquina = $this->maquina();
        $this->actingAs($this->usuario('operador', 'op@empresa.com', ['calculadora']));

        $this->get('/producao/dashboard')->assertForbidden();
        $this->get("/producao/dashboard/{$maquina->id}")->assertForbidden();
    }

    public function test_admin_cadastra_planta_linha_maquina_e_dispositivo_iot(): void
    {
        $this->actingAs($this->usuario('admin', 'admin@empresa.com'));

        $this->post('/admin/plantas', ['codigo' => 'MATRIZ', 'nome' => 'Planta Matriz'])->assertRedirect('/admin/plantas-e-linhas');
        $planta = Planta::sole();
        $this->post('/admin/linhas', ['planta_id' => $planta->id, 'codigo' => 'L1', 'nome' => 'Linha 1'])->assertRedirect();
        $linha = Linha::sole();
        $this->get('/admin/plantas-e-linhas')->assertOk()->assertSee('Planta Matriz')->assertSee('Linha 1');

        $this->post('/admin/maquinas', ['linha_id' => $linha->id, 'codigo' => 'INJ-01', 'nome' => 'Injetora 01',
            'ciclo_ideal_s' => '12.5', 'ativo' => '0'])->assertRedirect('/admin/maquinas');
        $maquina = Maquina::sole();
        $this->assertSame(12.5, $maquina->ciclo_ideal_s);
        $this->post('/admin/maquinas', ['linha_id' => $linha->id, 'codigo' => 'INJ-01', 'nome' => 'Outra', 'ativo' => '1'])
            ->assertSessionHasErrors('codigo');

        $this->post('/admin/dispositivos-iot', ['codigo' => 'SENS-01', 'nome' => 'Contador INJ-01', 'tipo' => 'contador',
            'protocolo' => 'mqtt', 'endereco' => '192.168.100.50', 'porta' => '1883', 'topico' => 'alumbra/inj01/pecas',
            'maquina_id' => $maquina->id, 'ativo' => '1'])->assertRedirect('/admin/dispositivos-iot');
        $iot = DispositivoIot::sole();
        $this->assertSame($maquina->id, $iot->maquina_id);
        $this->assertSame('nunca', $iot->situacao());
        $this->get('/admin/dispositivos-iot')->assertOk()->assertSee('Contador INJ-01')->assertSee('Nunca conectou');
        $this->get('/admin/maquinas')->assertOk()->assertSee('Injetora 01')->assertSee('Linha 1 · Planta Matriz');

        $this->put("/admin/maquinas/{$maquina->id}", ['linha_id' => $linha->id, 'codigo' => 'INJ-01', 'nome' => 'Injetora 01',
            'ativo' => '0'])->assertRedirect();
        $this->assertFalse($maquina->refresh()->ativo);
    }

    public function test_dashboard_mostra_o_oee_da_maquina(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-05 15:00', 'UTC')); // 12:00 em São Paulo
        $maquina = $this->maquina();
        // 2 h produzindo sem parar, ciclo ideal 1 s, 7.200 peças boas: OEE 100%
        Apontamento::create(['maquina_id' => $maquina->id, 'inicio' => Carbon::parse('2026-11-05 11:00', 'UTC'),
            'fim' => Carbon::parse('2026-11-05 13:00', 'UTC'), 'ciclo_ideal_s' => 1, 'total_produzido' => 7200, 'pecas_boas' => 7200]);

        $this->actingAs($this->usuario('operador', 'op@empresa.com', ['dashboard_maquina']));
        $this->get('/producao/dashboard')->assertOk()->assertSee('Injetora 01')->assertSee('100%');
        $this->get("/producao/dashboard/{$maquina->id}?periodo=hoje")->assertOk()
            ->assertSee('100,0%')->assertSee('Tempo operando')->assertSee('2 h 00 min');
        $this->get("/producao/dashboard/{$maquina->id}?periodo=ontem")->assertOk()->assertSee('Sem apontamentos');

        Carbon::setTestNow();
    }
}
