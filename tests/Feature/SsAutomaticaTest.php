<?php

namespace Tests\Feature;

use App\Models\ApontamentoEstado;
use App\Models\CentroCusto;
use App\Models\Legenda;
use App\Models\Linha;
use App\Models\Manutencao\AreaExecutante;
use App\Models\Manutencao\OrdemServico;
use App\Models\Manutencao\SolicitacaoServico;
use App\Models\Maquina;
use App\Models\Planta;
use App\Models\Usuario;
use App\Services\Manutencao\ChamadoDaParada;
use App\Services\Manutencao\SsAutomatica;
use App\Support\AcessoOs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\IotDeTeste;
use Tests\TestCase;

/** Legenda que "abre SS", SS automática pelo apontamento e o aviso de risco à integridade. */
class SsAutomaticaTest extends TestCase
{
    use IotDeTeste;
    use RefreshDatabase;

    private Maquina $maquina;

    private AreaExecutante $mecanica;

    private AreaExecutante $ti;

    private Legenda $quebra;

    protected function setUp(): void
    {
        parent::setUp();
        AcessoOs::limparCache();
        CentroCusto::create(['ctt_custo' => '1001', 'ctt_desc01' => 'INJECAO']);
        $linha = Linha::create(['planta_id' => Planta::create(['codigo' => 'P', 'nome' => 'Planta'])->id, 'codigo' => 'L', 'nome' => 'Linha']);
        $this->maquina = Maquina::create(['codigo' => 'INJ-12', 'nome' => 'Injetora 12', 'linha_id' => $linha->id, 'ativo' => false,
            'centro_custo' => '1001'] + $this->iotGravado());
        $this->mecanica = AreaExecutante::create(['codigo' => 'MEC', 'nome' => 'Mecânica', 'unidade' => 'manutencao', 'ativo' => true]);
        $this->ti = AreaExecutante::create(['codigo' => 'TI', 'nome' => 'Suporte TI', 'unidade' => 'tecnologia', 'ativo' => true]);
        $this->quebra = Legenda::create(['codigo' => '10', 'descricao' => 'Quebra de molde', 'tipo' => 'parada', 'cor' => '#d97706']);
    }

    private function usuario(array $perfis, string $email): Usuario
    {
        $u = Usuario::create(['entra_oid' => "oid-{$email}", 'nome' => ucfirst(strtok($email, '@')), 'email' => $email]);
        $u->definirPerfis($perfis);

        return $u->fresh();
    }

    public function test_cadastro_da_legenda_com_abre_ss(): void
    {
        $this->actingAs($this->usuario(['admin_producao'], 'adm@empresa.com'));
        $dados = ['unidade' => 'manutencao', 'codigo' => '10', 'descricao' => 'Quebra de molde', 'tipo' => 'parada', 'cor' => '#d97706',
            'ordem' => 1, 'ativo' => 1, 'exige_op' => 0];
        $this->get("/admin/legendas/{$this->quebra->id}/editar")->assertOk()->assertSee('Abre SS')->assertSee('Mecânica')
            ->assertDontSee('<option value="tecnologia"', false); // só as unidades do login
        // Área de outra unidade é recusada; unidade sem área também.
        $this->put("/admin/legendas/{$this->quebra->id}", $dados + ['abre_ss_unidade' => 'manutencao', 'abre_ss_area_executante_id' => $this->ti->id])
            ->assertSessionHasErrors('abre_ss_area_executante_id');
        $this->put("/admin/legendas/{$this->quebra->id}", $dados + ['abre_ss_unidade' => 'manutencao'])->assertSessionHasErrors('abre_ss_area_executante_id');
        $this->put("/admin/legendas/{$this->quebra->id}", $dados + ['abre_ss_unidade' => 'manutencao', 'abre_ss_area_executante_id' => $this->mecanica->id])
            ->assertSessionHasNoErrors();
        $this->assertSame($this->mecanica->id, (int) $this->quebra->fresh()->abre_ss_area_executante_id);
        $this->get('/admin/legendas')->assertOk()->assertSee('Manutenção · Mecânica');
        // Sem unidade: deixa de abrir SS.
        $this->put("/admin/legendas/{$this->quebra->id}", $dados + ['abre_ss_unidade' => ''])->assertSessionHasNoErrors();
        $this->assertNull($this->quebra->fresh()->abre_ss_area_executante_id);
    }

    public function test_apontamento_da_legenda_abre_ss_automatica_uma_vez_e_o_admin_avalia(): void
    {
        $this->quebra->forceFill(['abre_ss_unidade' => 'manutencao', 'abre_ss_area_executante_id' => $this->mecanica->id])->save();
        $ap = ApontamentoEstado::create(['maquina_id' => $this->maquina->id, 'legenda_id' => $this->quebra->id, 'inicio' => now()->subHour()]);

        $ss = SolicitacaoServico::sole();
        $iot = SsAutomatica::usuarioIot();
        $this->assertFalse($iot->ativo);
        $this->assertSame(['manutencao', $iot->id, '1001', 'pendente', $ap->id], [$ss->unidade, $ss->solicitante_id, $ss->centro_custo, $ss->status, $ss->apontamento_estado_id]);
        $this->assertTrue($ss->automatica);
        $this->assertTrue($ss->maquina_parada);
        $this->assertSame('OS automática — 10 Quebra de molde — INJ-12', $ss->titulo);
        $this->assertSame('IoT IOT-T1 / chd1_value', $ss->origem_iot); // sem origem no apontamento: o canal IoT da máquina
        $this->assertSame(1, $ss->historico()->where('acao', 'aberta')->count());

        // Outro apontamento da mesma legenda com a SS ainda pendente: não abre outra.
        ApontamentoEstado::create(['maquina_id' => $this->maquina->id, 'legenda_id' => $this->quebra->id, 'inicio' => now()->subMinutes(10)]);
        $this->assertSame(1, SolicitacaoServico::count());

        // Aprovada: a OS também é automática; quem avalia é o administrador da unidade (o IoT não entra no portal).
        $adm = $this->usuario(['admin_producao'], 'adm@empresa.com');
        $this->actingAs($adm)->get("/ordens-de-servico/solicitacoes/{$ss->id}")->assertOk()->assertSee('OS automática');
        $this->post("/ordens-de-servico/solicitacoes/{$ss->id}/aprovar")->assertRedirect();
        $os = OrdemServico::where('solicitacao_id', $ss->id)->firstOrFail();
        $this->assertTrue($os->automatica);
        $this->get("/ordens-de-servico/ordens/{$os->id}")->assertOk()->assertSee('Automática · IoT IOT-T1 / chd1_value');
        $os->forceFill(['status' => 'concluida', 'concluida_em' => now()])->save();
        $this->post("/ordens-de-servico/ordens/{$os->id}/avaliar", ['avaliacao' => 'boa'])->assertRedirect();
        $this->assertSame('encerrada', $os->fresh()->status);

        // Com a OS fechada, um novo apontamento da legenda abre uma SS nova.
        ApontamentoEstado::create(['maquina_id' => $this->maquina->id, 'legenda_id' => $this->quebra->id, 'inicio' => now()]);
        $this->assertSame(2, SolicitacaoServico::count());
    }

    public function test_apontado_no_portal_o_solicitante_e_quem_apontou_e_ele_avalia(): void
    {
        $this->quebra->forceFill(['abre_ss_unidade' => 'manutencao', 'abre_ss_area_executante_id' => $this->mecanica->id])->save();
        $lider = $this->usuario(['admin_producao', 'solicitante_manutencao'], 'lider@empresa.com');
        $operador = $this->usuario(['operador', 'solicitante_manutencao'], 'operador@empresa.com');
        $this->actingAs($operador);
        ApontamentoEstado::create(['maquina_id' => $this->maquina->id, 'legenda_id' => $this->quebra->id, 'inicio' => now()->subMinutes(5)]);

        $ss = SolicitacaoServico::sole();
        $this->assertSame($operador->id, $ss->solicitante_id);
        $this->assertNull($ss->origem_iot);
        $this->assertStringContainsString('apontado por Operador', $ss->historico()->value('observacao'));

        // Timeline: máquina parada mostra a SS no lugar da meta.
        $this->maquina->forceFill(['situacao' => 'parada', 'situacao_desde' => now()->subMinutes(5), 'ativo' => true])->save();
        $this->assertSame($ss->numeroFormatado(), ChamadoDaParada::da($this->maquina->fresh())['numero']);

        // A OS é avaliada pelo próprio operador (solicitante), não pelo administrador.
        $this->actingAs($lider)->post("/ordens-de-servico/solicitacoes/{$ss->id}/aprovar")->assertRedirect();
        $os = OrdemServico::where('solicitacao_id', $ss->id)->firstOrFail();
        $os->forceFill(['status' => 'concluida', 'concluida_em' => now()])->save();
        $this->post("/ordens-de-servico/ordens/{$os->id}/avaliar", ['avaliacao' => 'boa'])->assertForbidden();
        $this->actingAs($operador)->post("/ordens-de-servico/ordens/{$os->id}/avaliar", ['avaliacao' => 'boa'])->assertRedirect();
        $this->assertSame('encerrada', $os->fresh()->status);
    }

    public function test_legenda_sem_abre_ss_nao_abre(): void
    {
        ApontamentoEstado::create(['maquina_id' => $this->maquina->id, 'legenda_id' => $this->quebra->id, 'inicio' => now()]);
        $this->assertSame(0, SolicitacaoServico::count());
    }

    public function test_risco_a_integridade_vai_da_ss_para_a_os(): void
    {
        $sol = $this->usuario(['solicitante_manutencao'], 'sol@empresa.com');
        $this->actingAs($sol)->get('/ordens-de-servico/solicitacoes/nova')->assertOk()->assertSee('Oferece risco à integridade')
            ->assertSee('name="unidade"', false);
        $this->post('/ordens-de-servico/solicitacoes', ['unidade' => 'manutencao', 'titulo' => 'Fio exposto', 'tipo' => 'corretiva',
            'area_executante_id' => $this->mecanica->id, 'prioridade' => 'alta', 'risco_integridade' => '1', 'descricao' => 'Fio exposto no painel',
            'centro_custo' => '1001'])->assertRedirect()->assertSessionHasNoErrors();
        $ss = SolicitacaoServico::sole();
        $this->assertTrue($ss->risco_integridade);
        $this->assertFalse($ss->maquina_parada);

        $adm = $this->usuario(['admin_producao'], 'adm@empresa.com');
        $this->actingAs($adm)->post("/ordens-de-servico/solicitacoes/{$ss->id}/aprovar")->assertRedirect();
        $os = OrdemServico::where('solicitacao_id', $ss->id)->firstOrFail();
        $this->assertTrue($os->risco_integridade);
        $this->get("/ordens-de-servico/ordens/{$os->id}")->assertSee('Risco à integridade');
    }

    public function test_informar_motivo_na_timeline_abre_a_ss_da_legenda(): void
    {
        $this->quebra->forceFill(['abre_ss_unidade' => 'manutencao', 'abre_ss_area_executante_id' => $this->mecanica->id])->save();
        $semMotivo = Legenda::create(['codigo' => '98', 'descricao' => 'Parada sem motivo', 'tipo' => 'parada', 'cor' => '#ff0000']);
        $ap = ApontamentoEstado::create(['maquina_id' => $this->maquina->id, 'legenda_id' => $semMotivo->id, 'inicio' => now()->subMinutes(5),
            'observacao' => ApontamentoEstado::MARCA_IOT.' IoT']);
        $this->assertSame(0, SolicitacaoServico::count());

        $operador = $this->usuario(['operador', 'solicitante_manutencao', 'admin_producao'], 'operador@empresa.com');
        $this->actingAs($operador)->postJson("/producao/apontamentos/{$ap->id}/informar-motivo", ['legenda_id' => $this->quebra->id])
            ->assertOk()->assertJsonPath('mensagem', 'SS automática '.SolicitacaoServico::sole()->numeroFormatado().' aberta para Mecânica.');
        $this->assertSame(1, SolicitacaoServico::count());

        // Outra parada sem motivo na mesma máquina: com a SS automática ainda pendente, não abre outra e a tela diz por quê.
        $ap2 = ApontamentoEstado::create(['maquina_id' => $this->maquina->id, 'legenda_id' => $semMotivo->id, 'inicio' => now()->subMinute()]);
        $this->postJson("/producao/apontamentos/{$ap2->id}/informar-motivo", ['legenda_id' => $this->quebra->id])->assertOk()
            ->assertJsonPath('mensagem', 'SS automática da legenda 10 NÃO aberta: já existe a SS '.SolicitacaoServico::sole()->numeroFormatado()
                .' automática em aberto desta máquina para Mecânica (uma por vez).');
        $this->assertSame(1, SolicitacaoServico::count());
    }
}
