<?php

namespace Tests\Feature;

use App\Console\Commands\SubstituirLegendas;
use App\Models\ApontamentoEstado;
use App\Models\Legenda;
use App\Models\Linha;
use App\Models\Maquina;
use App\Models\OrdemProducao;
use App\Models\Planta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Comando legendas:substituir: troca todas as legendas pelas da Alumbra (apaga as antigas e os apontamentos delas). */
class SubstituirLegendasTest extends TestCase
{
    use RefreshDatabase;

    private ApontamentoEstado $apontamento;

    protected function setUp(): void
    {
        parent::setUp();
        $planta = Planta::create(['codigo' => 'P', 'nome' => 'Planta']);
        $linha = Linha::create(['planta_id' => $planta->id, 'codigo' => 'L', 'nome' => 'Linha']);
        $maquina = Maquina::create(['linha_id' => $linha->id, 'codigo' => 'INJ-01', 'nome' => 'Injetora', 'ativo' => true]);
        $antiga = Legenda::create(['codigo' => 'ANT', 'descricao' => 'Parada antiga', 'tipo' => 'parada', 'cor' => '#ff0000']);
        $op = OrdemProducao::create(['c2_num' => '509859', 'c2_item' => '01', 'c2_sequen' => '001', 'numero' => '50985901001',
            'status' => 'parada', 'c2_quant' => 10, 'c2_quje' => 0]);
        $this->apontamento = ApontamentoEstado::create(['maquina_id' => $maquina->id, 'legenda_id' => $antiga->id,
            'ordem_producao_id' => $op->id, 'inicio' => now()->subHour()]);
    }

    public function test_sem_confirmar_so_mostra(): void
    {
        $antes = Legenda::count();
        $this->artisan('legendas:substituir')->expectsOutputToContain('Nada foi alterado')->assertSuccessful();
        $this->assertSame($antes, Legenda::count());
        $this->assertSame(1, ApontamentoEstado::count());
    }

    public function test_confirmado_troca_todas_as_legendas_e_apaga_os_apontamentos(): void
    {
        $this->artisan('legendas:substituir --confirmar')->assertSuccessful();

        $this->assertSame(0, ApontamentoEstado::count());
        $this->assertSame(array_map('strval', array_keys(SubstituirLegendas::LEGENDAS)),
            Legenda::orderBy('ordem')->pluck('codigo')->all());
        $this->assertSame(['Produção Normal', 'produzindo', 'manutencao', true],
            [Legenda::where('codigo', '2')->value('descricao'), Legenda::where('codigo', '2')->value('tipo'),
                Legenda::where('codigo', '2')->value('unidade'), (bool) Legenda::where('codigo', '2')->value('ativo')]);
        $this->assertSame(0, Legenda::where('codigo', 'ANT')->count());
        // A OP não tem mais o apontamento de parada: o status volta a sair só do Protheus.
        $this->assertSame('aberta', OrdemProducao::sole()->status);
    }

    public function test_solicitacao_de_servico_perde_so_o_vinculo_com_a_parada(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('solicitacoes_servico')) {
            $this->markTestSkipped('Sem o módulo de ordens de serviço.');
        }
        $colunas = DB::getSchemaBuilder()->getColumnListing('solicitacoes_servico');
        $this->assertContains('apontamento_estado_id', $colunas);

        $this->artisan('legendas:substituir --confirmar')->assertSuccessful();
        $this->assertSame(0, DB::table('solicitacoes_servico')->whereNotNull('apontamento_estado_id')->count());
    }
}
