<?php

namespace Tests\Feature;

use App\Models\Apontamento;
use App\Models\ApontamentoEstado;
use App\Models\DispositivoIot;
use App\Models\Grupo;
use App\Models\Legenda;
use App\Models\Linha;
use App\Models\Maquina;
use App\Models\Planta;
use App\Models\Usuario;
use App\Services\PanoramaMaquinas;
use App\Services\SituacaoMaquina;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\IotDeTeste;
use Tests\TestCase;

class PainelTest extends TestCase
{
    use IotDeTeste;
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

        return Maquina::create(['linha_id' => $linha->id, 'codigo' => 'INJ-01', 'nome' => 'Injetora 01', 'ciclo_ideal_s' => 1] + $this->iotGravado());
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
            ->assertSee('100,0%')->assertSee('Tempo operando')->assertSee('2 h 00 min')
            ->assertSee('Produção por hora — hoje')->assertSee('Peças no turno')->assertSee('pç/min agora'); // cartão da Produção por hora
        $this->get("/producao/dashboard/{$maquina->id}?periodo=ontem")->assertOk()->assertSee('sem contagem de peças')
            ->assertSee('Produção por hora — 04/11/2026'); // período passado: o turno do último dia dele

        Carbon::setTestNow();
    }

    public function test_panorama_pelos_apontamentos_reais_so_com_iot_ativo(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-05 15:00', 'UTC')); // 12:00 em São Paulo
        $maquina = $this->maquina();
        $semIot = Maquina::create(['linha_id' => $maquina->linha_id, 'codigo' => 'TOR-01', 'nome' => 'Torno sem IoT', 'ativo' => true]);
        $iotInativo = Maquina::create(['linha_id' => $maquina->linha_id, 'codigo' => 'PRE-01', 'nome' => 'Prensa IoT desligado', 'ativo' => true] + $this->iotGravado());
        DispositivoIot::whereKey($iotInativo->iot1_dispositivo_id)->update(['ativo' => false]);
        $produz = Legenda::create(['codigo' => '1', 'descricao' => 'Produzindo', 'tipo' => 'produzindo', 'cor' => '#2e7d32']);
        $parada = Legenda::create(['codigo' => '31', 'descricao' => 'Manutenção corretiva', 'tipo' => 'parada', 'cor' => '#c62828']);
        // 09:00–10:30 produzindo, 10:30–11:00 parada, 11:00–agora (12:00) produzindo: 2h30 produzindo, 30 min parada.
        foreach ([['12:00', '13:30', $produz], ['13:30', '14:00', $parada], ['14:00', null, $produz]] as [$ini, $fim, $leg]) {
            $a = ApontamentoEstado::create(['maquina_id' => $maquina->id, 'legenda_id' => $leg->id,
                'inicio' => Carbon::parse("2026-11-05 {$ini}", 'UTC'), 'fim' => $fim ? Carbon::parse("2026-11-05 {$fim}", 'UTC') : null]);
            app(SituacaoMaquina::class)->aplicar($a);
        }

        $this->actingAs($this->usuario('operador', 'op@empresa.com', ['dashboard_maquina']));
        $this->get('/producao/dashboard')->assertOk()
            ->assertSee('Injetora 01')->assertDontSee('Torno sem IoT')->assertDontSee('Prensa IoT desligado')
            ->assertSee('class="pm-oee', false) // OEE de cada máquina no cartão
            ->assertSee('Produzindo agora')->assertSee('83,3%')->assertSee('2 h 30 min')->assertSee('Maior parada: 31');
        // Página inicial: o OEE de hoje vem dos apontamentos reais (sem meta nas OPs: mostra a disponibilidade).
        $this->get('/')->assertOk()->assertSee('OEE de hoje')->assertSee('disponibilidade 83%')->assertDontSee('sem apontamentos reais hoje')
            ->assertSee('Máquinas paradas agora');
        $this->get("/producao/dashboard/{$maquina->id}")->assertOk()
            ->assertSee('Apontamentos reais')->assertSee('Manutenção corretiva')->assertSee('em aberto')
            ->assertSee('Timeline — Hoje')->assertSee('class="tl-bloco', false)->assertSee('class="tl-marca', false) // timeline no período
            ->assertDontSee('Editar o cadastro da máquina'); // operador: sem a engrenagem
        $this->actingAs($this->usuario('admin', 'chefe@empresa.com'));
        $this->get("/producao/dashboard/{$maquina->id}")->assertOk()
            ->assertSee('href="'.route('admin.maquinas.edit', $maquina).'"', false)->assertSee('Editar o cadastro da máquina');
        $this->actingAs($this->usuario('operador', 'op2@empresa.com', ['dashboard_maquina']));

        // Fora de turno (tipo "desligada") não conta como parada: a máquina fica "Fora de turno" e a parada não muda.
        $foraTurno = Legenda::create(['codigo' => '96', 'descricao' => 'Fora de Turno', 'tipo' => 'desligada', 'cor' => '#eadfc4']);
        ApontamentoEstado::whereNull('fim')->update(['fim' => Carbon::parse('2026-11-05 14:30', 'UTC')]);
        $a = ApontamentoEstado::create(['maquina_id' => $maquina->id, 'legenda_id' => $foraTurno->id, 'inicio' => Carbon::parse('2026-11-05 14:30:01', 'UTC')]);
        app(SituacaoMaquina::class)->aplicar($a);
        $dados = app(PanoramaMaquinas::class)->montar(Maquina::whereKey($maquina->id)->get(),
            Carbon::parse('2026-11-05 03:00', 'UTC'), Carbon::parse('2026-11-06 03:00', 'UTC'));
        $this->assertSame(1800, $dados['maquinas'][$maquina->id]['segundos']['parada']);   // só os 30 min de manutenção
        $this->assertSame(['estado' => 'desligada', 'rotulo' => 'Fora de turno'], array_intersect_key($dados['situacoes'][$maquina->id], ['estado' => 1, 'rotulo' => 1]));
        $this->assertSame(['produzindo' => 0, 'parada' => 0, 'planejada' => 0, 'desligada' => 1, 'sem' => 0], $dados['contagem']);
        $this->get('/producao/dashboard')->assertSee('Fora de turno');

        Carbon::setTestNow();
    }

    public function test_legenda_com_exibir_em_dashboards_nao_fica_fora_dos_dashboards_e_dos_calculos(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-05 15:00', 'UTC'));
        $maquina = $this->maquina();
        $produz = Legenda::create(['codigo' => '1', 'descricao' => 'Produzindo', 'tipo' => 'produzindo', 'cor' => '#2e7d32']);
        $ferramentaria = Legenda::create(['codigo' => '9', 'descricao' => 'Ferramentaria', 'tipo' => 'parada', 'cor' => '#6d4c41']);
        $this->assertTrue($ferramentaria->fresh()->exibir_dashboards); // toda legenda nasce "Sim"
        // 2 h produzindo + 1 h na Ferramentaria (parada): disponibilidade 66,7% com ela; 100% sem ela.
        foreach ([['12:00', '13:59:59', $produz], ['14:00', null, $ferramentaria]] as [$ini, $fim, $leg]) {
            ApontamentoEstado::create(['maquina_id' => $maquina->id, 'legenda_id' => $leg->id,
                'inicio' => Carbon::parse("2026-11-05 {$ini}", 'UTC'), 'fim' => $fim ? Carbon::parse("2026-11-05 {$fim}", 'UTC') : null]);
        }
        $montar = fn () => app(PanoramaMaquinas::class)->montar(Maquina::whereKey($maquina->id)->get(),
            Carbon::parse('2026-11-05 03:00', 'UTC'), Carbon::parse('2026-11-06 03:00', 'UTC'));
        $this->assertEqualsWithDelta(2 / 3, $montar()['maquinas'][$maquina->id]['disponibilidade'], 0.001);

        // Cadastro: "Exibir em dashboards" = Não.
        $this->actingAs($this->usuario('admin', 'adm@empresa.com'));
        $this->put("/admin/legendas/{$ferramentaria->id}", ['codigo' => '9', 'descricao' => 'Ferramentaria', 'tipo' => 'parada',
            'cor' => '#6d4c41', 'ativo' => '1', 'exige_op' => '0', 'exibir_dashboards' => '0'])->assertSessionHasNoErrors();
        $this->assertFalse($ferramentaria->fresh()->exibir_dashboards);

        $dados = $montar();
        $this->assertEqualsWithDelta(1.0, $dados['maquinas'][$maquina->id]['disponibilidade'], 0.001); // fora da conta
        $this->assertSame(0, $dados['maquinas'][$maquina->id]['segundos']['parada']);
        $this->assertNull($dados['maquinas'][$maquina->id]['maiorParada']);
        $this->assertSame(['1'], array_map(fn ($t) => $t['legenda']->codigo, $dados['geral']['totais']));
        $tela = $this->get("/producao/dashboard/{$maquina->id}")->assertOk();
        $this->assertSame(['1'], array_map(fn ($t) => $t['legenda']->codigo, $tela->viewData('panorama')['geral']['totais']));
        $this->assertSame(['1'], $tela->viewData('apontamentosReais')->map(fn ($a) => $a->legenda->codigo)->all());
        // A Timeline continua mostrando.
        $this->get('/dashboards/timeline?parcial=1')->assertOk()->assertSee('<b>9</b>', false);

        Carbon::setTestNow();
    }
}
