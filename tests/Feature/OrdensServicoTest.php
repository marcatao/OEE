<?php

namespace Tests\Feature;

use App\Models\Linha;
use App\Models\Manutencao\ApontamentoHoras;
use App\Models\Manutencao\AreaExecutante;
use App\Models\Manutencao\Mantenedor;
use App\Models\Manutencao\OrdemServico;
use App\Models\Manutencao\PlanoPreventiva;
use App\Models\Manutencao\SolicitacaoServico;
use App\Models\Maquina;
use App\Models\Planta;
use App\Models\Usuario;
use App\Services\Manutencao\GeradorPreventivas;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fluxo das Ordens de serviço: SS → aprovação → OS → horas → conclusão → aceite; rejeição; unidades; PAMP; relatórios.
 */
class OrdensServicoTest extends TestCase
{
    use RefreshDatabase;

    private Maquina $injetora;

    private AreaExecutante $mecanica;

    private AreaExecutante $ti;

    protected function setUp(): void
    {
        parent::setUp();
        $planta = Planta::create(['codigo' => 'P', 'nome' => 'Planta']);
        $linha = Linha::create(['planta_id' => $planta->id, 'codigo' => 'L', 'nome' => 'Linha']);
        $this->injetora = Maquina::create(['linha_id' => $linha->id, 'codigo' => 'INJ-01', 'nome' => 'Injetora 01', 'ativo' => true, 'centro_custo' => '1001']);
        $this->mecanica = AreaExecutante::create(['codigo' => 'MEC', 'nome' => 'Mecânica', 'unidade' => 'manutencao']);
        $this->ti = AreaExecutante::create(['codigo' => 'TI', 'nome' => 'Suporte de TI', 'unidade' => 'tecnologia']);
    }

    private function usuario(array $perfis, string $email): Usuario
    {
        $u = Usuario::create(['entra_oid' => "oid-{$email}", 'nome' => ucfirst(strtok($email, '@')), 'email' => $email]);
        $u->definirPerfis($perfis);

        return $u->fresh();
    }

    private function abrirSs(Usuario $u, array $dados = []): SolicitacaoServico
    {
        $this->actingAs($u)->post('/ordens-de-servico/solicitacoes', $dados + [
            'tipo' => 'corretiva', 'area_executante_id' => $this->mecanica->id, 'maquina_id' => $this->injetora->id,
            'prioridade' => 'alta', 'maquina_parada' => '1', 'descricao' => 'Vazamento de óleo no cilindro',
        ])->assertRedirect();

        return SolicitacaoServico::latest('id')->firstOrFail();
    }

    public function test_fluxo_completo_da_corretiva(): void
    {
        $operador = $this->usuario(['operador'], 'operador@empresa.com');
        $lider = $this->usuario(['admin_producao'], 'lider@empresa.com');
        $mecanico = $this->usuario(['mantenedor_producao'], 'mecanico@empresa.com');
        $mantenedor = Mantenedor::create(['nome' => 'João Mecânico', 'usuario_id' => $mecanico->id, 'area_executante_id' => $this->mecanica->id]);

        $ss = $this->abrirSs($operador);
        $this->assertSame('pendente', $ss->status);
        $this->assertSame('manutencao', $ss->unidade);
        $this->assertSame('1001', $ss->centro_custo); // veio da máquina
        $this->get('/ordens-de-servico/solicitacoes')->assertOk()->assertSee($ss->numeroFormatado());

        // Operador não aprova.
        $this->post("/ordens-de-servico/solicitacoes/{$ss->id}/aprovar")->assertForbidden();

        // Líder aprova e distribui: a SS vira OS com os mesmos dados.
        $this->actingAs($lider)->post("/ordens-de-servico/solicitacoes/{$ss->id}/aprovar", ['responsavel_id' => $mantenedor->id])->assertRedirect();
        $os = OrdemServico::where('solicitacao_id', $ss->id)->firstOrFail();
        $this->assertSame('aberta', $os->status);
        $this->assertSame($lider->id, $ss->fresh()->analisado_por_id);
        $this->assertSame('Vazamento de óleo no cilindro', $os->descricao);
        $this->assertTrue($os->maquina_parada);

        // Mecânico inicia, requisita material e encerra o apontamento.
        $this->actingAs($mecanico)->get('/ordens-de-servico/minhas')->assertOk()->assertSee($os->numeroFormatado());
        $this->post("/ordens-de-servico/ordens/{$os->id}/apontamentos/iniciar")->assertRedirect();
        $this->assertSame('em_execucao', $os->fresh()->status);
        $this->post("/ordens-de-servico/ordens/{$os->id}/materiais", ['descricao' => 'Retentor 40mm', 'quantidade' => 2, 'um' => 'PC'])->assertRedirect();
        $ap = ApontamentoHoras::firstOrFail();
        $ap->forceFill(['inicio' => now()->startOfMinute()->subMinutes(90)])->save();
        $this->post("/ordens-de-servico/apontamentos/{$ap->id}/encerrar", ['descricao' => 'Trocado o retentor'])->assertRedirect();
        $ap->refresh();
        $this->assertSame(90, (int) $ap->minutos_normais + (int) $ap->minutos_extras);
        $this->assertSame(90, $os->fresh()->total_minutos);

        // Conclui: data do último apontamento.
        $this->post("/ordens-de-servico/ordens/{$os->id}/acao", ['acao' => 'concluir', 'observacao' => 'Retentor trocado e testado'])->assertRedirect();
        $os->refresh();
        $this->assertSame('concluida', $os->status);
        $this->assertEquals($ap->fim->getTimestamp(), $os->concluida_em->getTimestamp());

        // Solicitante vê a OS dele e dá o aceite.
        $this->actingAs($operador)->get("/ordens-de-servico/ordens/{$os->id}")->assertOk()->assertSee('Aceitar o serviço');
        $this->post("/ordens-de-servico/ordens/{$os->id}/aceite", ['resultado' => 'aceito'])->assertRedirect();
        $this->assertSame('encerrada', $os->fresh()->status);
        $this->assertSame($operador->id, $os->fresh()->aceite_por_id);
        $this->get("/ordens-de-servico/ordens/{$os->id}/imprimir")->assertOk()->assertSee('Solicitante');
        $this->assertGreaterThanOrEqual(5, $os->historico()->count());
    }

    public function test_rejeicao_volta_ao_solicitante_com_motivo(): void
    {
        $operador = $this->usuario(['operador'], 'operador@empresa.com');
        $lider = $this->usuario(['admin_producao'], 'lider@empresa.com');
        $ss = $this->abrirSs($operador);

        $this->actingAs($lider)->post("/ordens-de-servico/solicitacoes/{$ss->id}/rejeitar", [])->assertSessionHasErrors('motivo_rejeicao');
        $this->post("/ordens-de-servico/solicitacoes/{$ss->id}/rejeitar", ['motivo_rejeicao' => 'Já existe a SS 12'])->assertRedirect();
        $this->assertSame('rejeitada', $ss->fresh()->status);
        $this->assertSame(0, OrdemServico::count());
        $this->actingAs($operador)->get("/ordens-de-servico/solicitacoes/{$ss->id}")->assertOk()->assertSee('Já existe a SS 12');
    }

    public function test_cada_unidade_ve_a_sua_fila_e_o_solicitante_ve_as_suas(): void
    {
        $operador = $this->usuario(['operador'], 'operador@empresa.com');
        $outro = $this->usuario(['operador'], 'outro@empresa.com');
        $mantProducao = $this->usuario(['mantenedor_producao'], 'mp@empresa.com');
        $mantTi = $this->usuario(['mantenedor_tecnologia'], 'mt@empresa.com');

        $ssTi = $this->abrirSs($operador, ['area_executante_id' => $this->ti->id, 'maquina_id' => '', 'descricao' => 'Computador não liga']);
        $this->assertSame('tecnologia', $ssTi->unidade);

        $this->actingAs($mantTi)->get('/ordens-de-servico/solicitacoes?status=')->assertOk()->assertSee('Computador não liga');
        $this->actingAs($mantProducao)->get('/ordens-de-servico/solicitacoes?status=')->assertOk()->assertDontSee('Computador não liga');
        $this->actingAs($mantProducao)->get("/ordens-de-servico/solicitacoes/{$ssTi->id}")->assertNotFound();
        // O solicitante vê a dele (mesmo de outra unidade); outro operador não.
        $this->actingAs($operador)->get("/ordens-de-servico/solicitacoes/{$ssTi->id}")->assertOk();
        $this->actingAs($outro)->get("/ordens-de-servico/solicitacoes/{$ssTi->id}")->assertNotFound();
    }

    public function test_preventiva_gera_os_com_checklist_uma_vez_por_data(): void
    {
        $admin = $this->usuario(['admin_producao'], 'adm@empresa.com');
        $this->actingAs($admin)->post('/ordens-de-servico/preventivas', [
            'unidade' => 'manutencao', 'codigo' => 'prev-inj', 'descricao' => 'Lubrificação da injetora', 'maquina_id' => $this->injetora->id,
            'area_executante_id' => $this->mecanica->id, 'prioridade' => 'media', 'periodicidade' => 'meses', 'meses' => range(1, 12),
            'dia_mes' => 10, 'antecedencia_dias' => 7, 'ativo' => '1',
            'itens' => [['descricao' => 'Verificar nível de óleo'], ['descricao' => 'Lubrificar guias'], ['descricao' => '']],
        ])->assertRedirect('/ordens-de-servico/preventivas');
        $plano = PlanoPreventiva::where('codigo', 'PREV-INJ')->firstOrFail();
        $this->assertSame(2, $plano->itens()->count());

        $plano->forceFill(['proxima_em' => '2026-11-10'])->save();
        $gerador = app(GeradorPreventivas::class);
        $criadas = $gerador->gerar(CarbonImmutable::parse('2026-11-05', config('oee.fuso_exibicao')));
        $this->assertCount(1, $criadas);
        $this->assertSame(2, $criadas[0]->checklist()->count());
        $this->assertSame('2026-12-10', $plano->fresh()->proxima_em->format('Y-m-d'));
        // Rodar de novo no mesmo dia não duplica.
        $this->assertCount(0, $gerador->gerar(CarbonImmutable::parse('2026-11-05', config('oee.fuso_exibicao'))));

        $this->get('/ordens-de-servico/preventivas')->assertOk()->assertSee('PREV-INJ');
    }

    public function test_telas_de_relatorio_e_cadastros(): void
    {
        $admin = $this->usuario(['admin_producao'], 'adm@empresa.com');
        $this->actingAs($admin);
        foreach (['/ordens-de-servico/ordens', '/ordens-de-servico/materiais', '/ordens-de-servico/relatorios/horas', '/ordens-de-servico/relatorios/setores',
            '/ordens-de-servico/cadastros/areas', '/ordens-de-servico/cadastros/mantenedores', '/ordens-de-servico/cadastros/mantenedores/novo',
            '/ordens-de-servico/cadastros/sugestoes', '/ordens-de-servico/solicitacoes/nova', '/ordens-de-servico/preventivas/nova'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/ordens-de-servico/relatorios/horas?formato=csv')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->post('/ordens-de-servico/cadastros/mantenedores', ['unidade' => 'manutencao', 'nome' => 'Maria', 'jornada_inicio' => '06:00',
            'jornada_fim' => '14:00', 'intervalo_min' => 60, 'dias' => [1, 2, 3, 4, 5], 'ativo' => '1'])->assertRedirect();
        $this->assertSame('12345', Mantenedor::where('nome', 'Maria')->value('dias_semana'));

        // Operador não abre relatórios nem cadastros, mas vê o menu de OS.
        $this->actingAs($this->usuario(['operador'], 'op@empresa.com'));
        $this->get('/ordens-de-servico/relatorios/horas')->assertForbidden();
        $this->get('/ordens-de-servico/cadastros/areas')->assertForbidden();
        $this->get('/')->assertSee('Solicitações (SS)');
    }
}
