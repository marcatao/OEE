<?php

namespace Tests\Feature;

use App\Models\Linha;
use App\Models\Manutencao\ApontamentoHoras;
use App\Models\Manutencao\AreaExecutante;
use App\Models\Manutencao\ChecklistOs;
use App\Models\Manutencao\HistoricoOs;
use App\Models\Manutencao\JustificativaSetor;
use App\Models\Manutencao\Mantenedor;
use App\Models\Manutencao\MaterialOs;
use App\Models\Manutencao\OrdemServico;
use App\Models\Manutencao\PlanoPreventiva;
use App\Models\Manutencao\PlanoPreventivaItem;
use App\Models\Manutencao\SolicitacaoServico;
use App\Models\Manutencao\SugestaoDescricao;
use App\Models\Maquina;
use App\Models\Planta;
use App\Models\Usuario;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Estrutura do módulo Ordens de serviço: tabelas, relações, escopo de visibilidade e índices únicos. */
class OrdensServicoBancoTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $solicitante;

    private Maquina $maquina;

    protected function setUp(): void
    {
        parent::setUp();
        $planta = Planta::create(['codigo' => 'P', 'nome' => 'Planta']);
        $linha = Linha::create(['planta_id' => $planta->id, 'codigo' => 'L', 'nome' => 'Linha']);
        $this->maquina = Maquina::create(['linha_id' => $linha->id, 'codigo' => 'INJ-01', 'nome' => 'Injetora 01', 'ativo' => true]);
        $this->solicitante = Usuario::create(['entra_oid' => 'oid-sol', 'nome' => 'Solicitante', 'email' => 'sol@empresa.com']);
    }

    public function test_fluxo_completo_grava_e_relaciona_todas_as_tabelas(): void
    {
        $this->actingAs($this->solicitante);

        $area = AreaExecutante::create(['codigo' => 'MEC', 'nome' => 'Mecânica']);
        $mantenedor = Mantenedor::create(['nome' => 'João', 'usuario_id' => $this->solicitante->id, 'area_executante_id' => $area->id]);
        SugestaoDescricao::create(['texto' => 'Vazamento de óleo']);
        $this->assertSame('manutencao', $area->fresh()->unidade);
        $this->assertSame('12345', $mantenedor->fresh()->dias_semana);
        $this->assertTrue($mantenedor->trabalhaNoDia(1));
        $this->assertFalse($mantenedor->trabalhaNoDia(7));
        $this->assertTrue($mantenedor->usuario->is($this->solicitante));
        $this->assertTrue($mantenedor->areaExecutante->is($area));

        $ss = SolicitacaoServico::create([
            'unidade' => SolicitacaoServico::unidadePara($area, $this->maquina), 'solicitante_id' => $this->solicitante->id,
            'tipo' => 'corretiva', 'maquina_id' => $this->maquina->id, 'area_executante_id' => $area->id,
            'maquina_parada' => true, 'descricao' => 'Vazamento de óleo no cilindro',
        ])->fresh();
        $this->assertSame('pendente', $ss->status);
        $this->assertSame('media', $ss->prioridade);
        $this->assertSame('SS '.str_pad((string) $ss->id, 6, '0', STR_PAD_LEFT), $ss->numeroFormatado());
        $this->assertTrue($ss->maquina->is($this->maquina));
        $this->assertSame($this->solicitante->id, $ss->criado_por_id);

        $plano = PlanoPreventiva::create(['codigo' => 'PRV-01', 'descricao' => 'Lubrificação', 'maquina_id' => $this->maquina->id,
            'area_executante_id' => $area->id, 'responsavel_id' => $mantenedor->id, 'periodicidade' => 'meses', 'meses' => '100100100100']);
        $item = PlanoPreventivaItem::create(['plano_preventiva_id' => $plano->id, 'ordem' => 1, 'descricao' => 'Verificar nível de óleo']);
        $this->assertTrue($plano->temNoMes(1));
        $this->assertFalse($plano->temNoMes(2));
        $this->assertTrue($plano->temNoMes(4));
        $this->assertCount(1, $plano->itens);
        $this->assertTrue($item->plano->is($plano));

        $os = OrdemServico::create([
            'unidade' => $ss->unidade, 'solicitacao_id' => $ss->id, 'tipo' => $ss->tipo, 'maquina_id' => $ss->maquina_id,
            'area_executante_id' => $area->id, 'prioridade' => $ss->prioridade, 'maquina_parada' => true,
            'descricao' => $ss->descricao, 'solicitante_id' => $this->solicitante->id, 'aprovado_por_id' => $this->solicitante->id,
            'responsavel_id' => $mantenedor->id,
        ])->fresh();
        $this->assertSame('aberta', $os->status);
        $this->assertSame(0, $os->total_minutos);
        $this->assertTrue($ss->ordemServico->is($os));
        $this->assertTrue($os->solicitacao->is($ss));
        $this->assertTrue($os->responsavel->is($mantenedor));
        $this->assertTrue($os->aprovadoPor->is($this->solicitante));
        $this->assertTrue($os->areaExecutante->is($area));

        $inicio = Carbon::parse('2026-11-19 10:00:00');
        ApontamentoHoras::create(['ordem_servico_id' => $os->id, 'mantenedor_id' => $mantenedor->id, 'inicio' => $inicio,
            'fim' => $inicio->copy()->addMinutes(90), 'minutos_normais' => 60, 'minutos_extras' => 30, 'descricao' => 'Troca da vedação']);
        $aberto = ApontamentoHoras::create(['ordem_servico_id' => $os->id, 'mantenedor_id' => $mantenedor->id, 'inicio' => now()->subMinutes(15)]);
        $this->assertTrue($aberto->aberto());
        $this->assertSame(15, $aberto->minutosTotais());
        $this->assertSame(90, $os->apontamentos()->whereNotNull('fim')->first()->minutosTotais());
        $this->assertSame(90, $os->recalcularTotalMinutos());
        $this->assertSame(90, $os->fresh()->total_minutos);
        $this->assertTrue($aberto->mantenedor->is($mantenedor));
        $this->assertTrue($aberto->lancadoPor->is($this->solicitante));

        $material = MaterialOs::create(['ordem_servico_id' => $os->id, 'descricao' => 'Vedação 40 mm', 'quantidade' => 2, 'um' => 'PC']);
        $this->assertSame('requisitado', $material->fresh()->status);
        $this->assertSame('2.0000', $material->fresh()->quantidade);

        HistoricoOs::create(['ordem_servico_id' => $os->id, 'acao' => 'status', 'status_anterior' => 'aberta', 'status_novo' => 'em_execucao']);
        HistoricoOs::create(['solicitacao_id' => $ss->id, 'acao' => 'aprovada', 'status_anterior' => 'pendente', 'status_novo' => 'aprovada']);
        $this->assertSame($this->solicitante->id, $os->historico->first()->usuario_id);
        $this->assertNotNull($os->historico->first()->criado_em);
        $this->assertCount(1, $ss->historico);

        ChecklistOs::create(['ordem_servico_id' => $os->id, 'plano_item_id' => $item->id, 'ordem' => 1, 'descricao' => $item->descricao, 'resposta' => 'ok']);
        $this->assertTrue($os->checklist->first()->planoItem->is($item));
        $this->assertCount(1, $os->materiais);

        $j = JustificativaSetor::create(['unidade' => 'manutencao', 'centro_custo' => 'Injeção', 'competencia' => '2026-11-01', 'texto' => 'Parada geral']);
        $this->assertSame('2026-11-01', $j->fresh()->competencia->toDateString());
    }

    public function test_unidade_vem_da_area_depois_da_maquina(): void
    {
        $ti = new AreaExecutante(['unidade' => 'tecnologia']);
        $ambos = new AreaExecutante(['unidade' => 'ambos']);
        $this->assertSame('tecnologia', OrdemServico::unidadePara($ti, $this->maquina));
        $this->assertSame('manutencao', OrdemServico::unidadePara($ambos, $this->maquina));
        $this->assertSame('tecnologia', OrdemServico::unidadePara($ambos, new Maquina(['unidade' => 'tecnologia'])));
        $this->assertSame('manutencao', SolicitacaoServico::unidadePara($ambos, new Maquina(['unidade' => 'ambos'])));
        $this->assertSame('manutencao', OrdemServico::unidadePara(null, null));
    }

    public function test_solicitante_sempre_ve_as_proprias_e_o_resto_so_da_sua_unidade(): void
    {
        $area = AreaExecutante::create(['codigo' => 'TI', 'nome' => 'TI', 'unidade' => 'tecnologia']);
        $base = ['tipo' => 'corretiva', 'area_executante_id' => $area->id, 'descricao' => 'x'];
        $outro = Usuario::create(['entra_oid' => 'oid-outro', 'nome' => 'Outro', 'email' => 'outro@empresa.com']);
        $propria = SolicitacaoServico::create($base + ['unidade' => 'tecnologia', 'solicitante_id' => $this->solicitante->id]);
        $alheia = SolicitacaoServico::create($base + ['unidade' => 'tecnologia', 'solicitante_id' => $outro->id]);

        $producao = Usuario::create(['entra_oid' => 'oid-prod', 'nome' => 'Prod', 'email' => 'prod@empresa.com']);
        $producao->definirPerfis(['mantenedor_producao']);
        $this->assertSame([], SolicitacaoServico::visiveisPara($producao->fresh())->pluck('id')->all());
        $this->assertSame([$propria->id], SolicitacaoServico::visiveisPara($this->solicitante)->pluck('id')->all());

        $ti = Usuario::create(['entra_oid' => 'oid-ti', 'nome' => 'TI', 'email' => 'ti@empresa.com']);
        $ti->definirPerfis(['mantenedor_tecnologia']);
        $this->assertEqualsCanonicalizing([$propria->id, $alheia->id], SolicitacaoServico::visiveisPara($ti->fresh())->pluck('id')->all());

        $os = OrdemServico::create($base + ['unidade' => 'tecnologia', 'prioridade' => 'alta', 'solicitante_id' => $this->solicitante->id]);
        $this->assertSame([$os->id], OrdemServico::visiveisPara($this->solicitante)->pluck('id')->all());
        $this->assertSame([], OrdemServico::visiveisPara($producao->fresh())->pluck('id')->all());
    }

    public function test_uma_os_por_solicitacao_e_por_plano_e_data(): void
    {
        $area = AreaExecutante::create(['codigo' => 'MEC', 'nome' => 'Mecânica']);
        $plano = PlanoPreventiva::create(['codigo' => 'PRV-01', 'descricao' => 'Lubrificação', 'area_executante_id' => $area->id, 'periodicidade' => 'semanal']);
        $base = ['unidade' => 'manutencao', 'tipo' => 'preventiva', 'area_executante_id' => $area->id, 'prioridade' => 'media', 'descricao' => 'x'];

        // Sem SS e sem plano: várias OS convivem (NULL não colide).
        OrdemServico::create($base);
        OrdemServico::create($base);
        OrdemServico::create($base + ['plano_preventiva_id' => $plano->id, 'prevista_para' => '2026-12-01']);

        $this->expectException(QueryException::class);
        OrdemServico::create($base + ['plano_preventiva_id' => $plano->id, 'prevista_para' => '2026-12-01']);
    }
}
