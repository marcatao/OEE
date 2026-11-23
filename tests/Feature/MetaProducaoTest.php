<?php

namespace Tests\Feature;

use App\Models\EstruturaProduto;
use App\Models\Ferramenta;
use App\Models\Legenda;
use App\Models\Linha;
use App\Models\Maquina;
use App\Models\OrdemProducao;
use App\Models\Planta;
use App\Models\ProdutoProtheus;
use App\Models\RoteiroOperacao;
use App\Models\Usuario;
use App\Services\Aplicativo\MaquinasAplicativo;
use App\Services\CalculadoraOee;
use App\Services\MetaProducao;
use App\Services\PanoramaMaquinas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CalendarioDeTeste;
use Tests\Concerns\IotDeTeste;
use Tests\TestCase;

/**
 * Meta de produção em pç/min: item MO da estrutura da OP (G1_QUANT em horas centesimais por peça ÷ B1_QB), fixa
 * mesmo com cavidades desativadas (molde indicado para conserto), e performance do OEE pelo IoT.
 */
class MetaProducaoTest extends TestCase
{
    use CalendarioDeTeste;
    use IotDeTeste;
    use RefreshDatabase;

    private Maquina $maquina;

    private Legenda $produzindo;

    private OrdemProducao $op;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-17 10:00', config('oee.fuso_exibicao'))->utc());
        $planta = Planta::create(['codigo' => 'P', 'nome' => 'Planta']);
        $linha = Linha::create(['planta_id' => $planta->id, 'codigo' => 'L', 'nome' => 'Linha 1']);
        $this->maquina = Maquina::create(['codigo' => 'INJ-01', 'nome' => 'Injetora 01', 'linha_id' => $linha->id, 'ativo' => true]
            + $this->jornadaGravada() + $this->iotGravado());
        $this->produzindo = Legenda::create(['codigo' => '1', 'descricao' => 'Produzindo', 'tipo' => 'produzindo', 'cor' => '#2e7d32']);

        $produto = fn ($cod, $tipo, $extra = []) => ProdutoProtheus::forceCreate(['b1_filial' => '', 'b1_cod' => $cod, 'b1_desc' => $cod,
            'b1_tipo' => $tipo, 'd_e_l_e_t_' => ' '] + $extra);
        $produto('0130066', 'PA');
        $produto('MP-01', 'MP');
        $produto('MO-1135', 'MO');
        $produto('MO-9999', 'MO');
        $comp = fn ($c, $q, $trt = '') => EstruturaProduto::forceCreate(['g1_filial' => '', 'g1_cod' => '0130066', 'g1_comp' => $c,
            'g1_trt' => $trt, 'g1_quant' => $q, 'd_e_l_e_t_' => ' ']);
        $comp('MP-01', 0.012);
        $comp('MO-1135', 0.00034, '001');
        $comp('MO-9999', 0.5, '002'); // segundo MO: não soma, vale o primeiro

        $this->op = OrdemProducao::create(['numero' => '00010001001', 'c2_num' => '000100', 'c2_item' => '01', 'c2_sequen' => '001',
            'c2_produto' => '0130066', 'c2_datrf' => '', 'status' => 'aberta', 'd_e_l_e_t_' => ' ', 'r_e_c_n_o_' => 1, 'c2_quant' => 1000]);
        $this->maquina->update(['ordem_producao_id' => $this->op->id, 'situacao' => 'produzindo']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): Usuario
    {
        return Usuario::create(['entra_oid' => 'adm', 'nome' => 'Admin', 'email' => 'adm@alumbra.com.br', 'perfil' => 'admin']);
    }

    private function molde(int $cavidades, int $ativas): void
    {
        Ferramenta::forceCreate(['h4_filial' => '', 'h4_codigo' => 'MOLD1', 'h4_descri' => 'MOLDE TAMPA', 'h4_qtdcav' => $cavidades,
            'h4_qtdcavativas' => $ativas, 'd_e_l_e_t_' => ' ']);
        RoteiroOperacao::forceCreate(['g2_filial' => '', 'g2_produto' => '0130066', 'g2_codigo' => '01', 'g2_operac' => '10',
            'g2_ctrab' => 'INJ-01', 'g2_ferram' => 'MOLD1', 'd_e_l_e_t_' => ' ']);
    }

    /** Apontamento de produção gravado como o integrador (horário local de hoje). */
    private function apontamento(string $inicio, ?string $fim, int $ciclos, int $cavidades, int $cicloMedio): void
    {
        $fuso = config('oee.fuso_exibicao');
        DB::table('apontamentos_estado')->insert([
            'maquina_id' => $this->maquina->id, 'legenda_id' => $this->produzindo->id, 'ordem_producao_id' => $this->op->id,
            'inicio' => Carbon::parse('2026-11-17 '.$inicio, $fuso)->utc(), 'fim' => $fim ? Carbon::parse('2026-11-17 '.$fim, $fuso)->utc() : null,
            'ciclos' => $ciclos, 'cavidades' => $cavidades, 'pecas' => $ciclos * $cavidades, 'ciclo_medio' => $cicloMedio,
            'observacao' => '[IoT] x', 'criado_em' => now(), 'atualizado_em' => now(),
        ]);
    }

    public function test_meta_vem_do_primeiro_item_mo_da_estrutura_em_horas_centesimais(): void
    {
        // 0,00034 h × 60 = 0,0204 min por peça → 49,02 pç/min (o 2º MO não soma).
        $meta = app(MetaProducao::class)->daOp($this->op);
        $this->assertSame('MO-1135', $meta['item']);
        $this->assertSame(49.02, $meta['por_minuto']);

        // Quantidade base do produto (B1_QB): 0,00034 h para 10 peças → 490,2 pç/min.
        $this->assertSame(490.2, MetaProducao::calcular(0.00034, 10)['por_minuto']);
        $this->assertNull(MetaProducao::calcular(0));

        // Sem item MO na estrutura: sem meta.
        $sem = OrdemProducao::create(['numero' => '00020001001', 'c2_num' => '000200', 'c2_item' => '01', 'c2_sequen' => '001',
            'c2_produto' => 'OUTRO', 'c2_datrf' => '', 'status' => 'aberta', 'd_e_l_e_t_' => ' ', 'r_e_c_n_o_' => 2]);
        $this->assertNull(app(MetaProducao::class)->porMinuto($sem));
        $this->assertNull(app(MetaProducao::class)->porMinuto(null));
    }

    public function test_timeline_e_producao_por_hora_mostram_a_meta_e_o_molde_para_conserto(): void
    {
        // Molde de 4 cavidades com 2 ativas: 60 ÷ 3 s × 2 = 40 pç/min < 49,02 → indicar para conserto (a meta não cai).
        $this->molde(4, 2);
        $this->apontamento('09:00:00', null, 1200, 2, 3);

        $resposta = $this->actingAs($this->admin())->get(route('producao-hora'))->assertOk()
            ->assertSee('meta 49,0')->assertSee('indicar para conserto');
        $dados = $resposta->viewData('maquinas')[0];
        $this->assertSame(49.02, $dados['metaPorMinuto']);
        $this->assertSame(40.0, $dados['pecasPorMinuto']);
        $this->assertSame(['codigo' => 'MOLD1', 'descricao' => 'MOLDE TAMPA', 'ativas' => 2, 'total' => 4], $dados['moldeConserto']);
        $this->assertEquals(2941, collect($dados['horas'])->firstWhere('hora', '06:00')['meta']); // 49,02 × 60
        $this->assertSame(1, $resposta->viewData('geral')['consertos']);

        $this->get(route('timeline', ['parcial' => 1]))->assertOk()->assertSee('meta 49,02')->assertSee('molde p/ conserto');

        // App: meta e molde para conserto no detalhe da máquina.
        $producao = app(MaquinasAplicativo::class)->detalhe($this->maquina->fresh())['producao'];
        $this->assertSame(49.02, $producao['meta_por_minuto']);
        $this->assertSame('MOLD1', $producao['molde_conserto']['codigo']);
    }

    public function test_molde_completo_ou_producao_na_meta_nao_indica_conserto(): void
    {
        $this->molde(4, 4);
        $this->apontamento('09:00:00', null, 1200, 4, 6); // 60 ÷ 6 × 4 = 40 < meta, mas o molde está completo
        $dados = $this->actingAs($this->admin())->get(route('producao-hora'))->viewData('maquinas')[0];
        $this->assertNull($dados['moldeConserto']);

        Ferramenta::query()->update(['h4_qtdcavativas' => 3]);
        $this->assertNull(MetaProducao::moldeParaConserto([['codigo' => 'MOLD1', 'ferramenta' => Ferramenta::first()]], 50.0, 49.02));
        $this->assertNotNull(MetaProducao::moldeParaConserto([['codigo' => 'MOLD1', 'ferramenta' => Ferramenta::first()]], 30.0, 49.02));
        $this->assertNull(MetaProducao::moldeParaConserto([['codigo' => 'MOLD1', 'ferramenta' => Ferramenta::first()]], 30.0, null));
    }

    public function test_performance_do_oee_pelo_iot_usa_a_meta(): void
    {
        // 1 hora produzindo: meta 49,02 × 60 = 2.941,2 peças; o IoT contou 2.400 → performance 81,6%.
        $this->apontamento('08:00:00', '08:59:59', 2400, 1, 2);
        $fuso = config('oee.fuso_exibicao');
        $panorama = app(PanoramaMaquinas::class)->montar(Maquina::whereKey($this->maquina->id)->get(),
            Carbon::parse('2026-11-17 06:00', $fuso)->utc(), Carbon::parse('2026-11-17 10:00', $fuso)->utc());
        $d = $panorama['maquinas'][$this->maquina->id];
        $this->assertSame(2400, $d['pecas']);
        $this->assertSame(2941, $d['pecasNaMeta']);
        $this->assertEqualsWithDelta(0.816, $d['performance'], 0.001);
        $this->assertEqualsWithDelta(0.816, $d['oeeIot'], 0.001); // disponibilidade 100%

        $this->assertNull(CalculadoraOee::performancePelaMeta(10, 0));
        $this->assertSame(1.2, CalculadoraOee::performancePelaMeta(120, 100)); // mostrado acima de 100%...
        $this->assertSame(0.9, CalculadoraOee::oeeIot(0.9, 1.2));              // ...mas no OEE limitado a 100%
        $this->assertSame(98.04, round(CalculadoraOee::pecasNaMeta(49.02, 120), 2));
    }
}
