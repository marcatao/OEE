<?php

namespace Tests\Feature;

use App\Models\EstruturaProduto;
use App\Models\Ferramenta;
use App\Models\Legenda;
use App\Models\Linha;
use App\Models\MaoDeObra;
use App\Models\Maquina;
use App\Models\MetaHora;
use App\Models\MetaRegistrada;
use App\Models\OrdemProducao;
use App\Models\PerdaOp;
use App\Models\Planta;
use App\Models\ProdutoProtheus;
use App\Models\RoteiroOperacao;
use App\Models\Usuario;
use App\Services\Aplicativo\MaquinasAplicativo;
use App\Services\CalculadoraOee;
use App\Services\MetaPorHora;
use App\Services\MetaProducao;
use App\Services\PanoramaMaquinas;
use App\Services\ProducaoPorHora;
use Carbon\CarbonImmutable;
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
        $this->assertSame(490.21, MetaProducao::calcular(0.00034, 10)['por_minuto']); // (constante 1,6667 da fórmula)
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

        $this->get(route('timeline', ['parcial' => 1]))->assertOk()->assertSee('meta 49,02')
            ->assertSee('tl-meta-amarelo', false)->assertDontSee('molde p/ conserto'); // Timeline: cartão amarelo, sem o texto de conserto

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

        // Qualidade (24/11/2026): o IoT conta também as perdidas → (2.400 − 120 perdas) ÷ 2.400 = 95%; OEE = D × P × Q.
        PerdaOp::forceCreate(['ordem_producao_id' => $this->op->id, 'maquina_id' => $this->maquina->id, 'dia' => '2026-11-17', 'quantidade' => 100]);
        PerdaOp::forceCreate(['ordem_producao_id' => $this->op->id, 'maquina_id' => $this->maquina->id, 'dia' => '2026-11-17', 'quantidade' => 20]);
        PerdaOp::forceCreate(['ordem_producao_id' => $this->op->id, 'maquina_id' => $this->maquina->id, 'dia' => '2026-11-17', 'quantidade' => 500, 'excluido_em' => now()]); // excluída: não conta
        PerdaOp::forceCreate(['ordem_producao_id' => $this->op->id, 'maquina_id' => $this->maquina->id, 'dia' => '2026-11-16', 'quantidade' => 300]); // outro dia: fora do período
        $panorama = app(PanoramaMaquinas::class)->montar(Maquina::whereKey($this->maquina->id)->get(),
            Carbon::parse('2026-11-17 06:00', $fuso)->utc(), Carbon::parse('2026-11-17 10:00', $fuso)->utc());
        $d = $panorama['maquinas'][$this->maquina->id];
        $this->assertEquals(120, $d['perdas']);
        $this->assertEqualsWithDelta(0.95, $d['qualidade'], 0.0001);
        $this->assertEqualsWithDelta(0.816 * 0.95, $d['oeeIot'], 0.001);
        // Fábrica: pelas somas.
        $this->assertEqualsWithDelta(0.95, $panorama['geral']['qualidade'], 0.0001);
        $this->assertSame([2400, 2280], [$panorama['geral']['pecas'], $panorama['geral']['pecasBoas']]);
        $this->assertEqualsWithDelta(0.816 * 0.95, $panorama['geral']['oeeIot'], 0.001);

        $this->assertNull(CalculadoraOee::qualidadePelasPerdas(0, 5));
        $this->assertSame(0.0, CalculadoraOee::qualidadePelasPerdas(10, 50));  // perda maior que a contagem = 0%
        $this->assertSame(0.81, CalculadoraOee::oeeIot(0.9, 1.0, 0.9));
    }

    public function test_meta_registrada_nao_muda_para_tras_quando_a_estrutura_muda(): void
    {
        $metas = function () { // instância nova = requisição nova
            app()->forgetScopedInstances();

            return app(MetaProducao::class);
        };
        $this->assertSame(49.02, $metas()->porMinuto($this->op));
        $this->assertSame(1, MetaRegistrada::count());
        $this->assertSame(49.02, $metas()->porMinuto($this->op)); // sem mudança, não registra de novo
        $this->assertSame(1, MetaRegistrada::count());

        // No dia seguinte a estrutura muda (0,0005 h → 33,33 pç/min): registra a nova, o passado fica com a antiga.
        $antes = now()->subHour();
        Carbon::setTestNow(now()->addDay());
        EstruturaProduto::where('g1_comp', 'MO-1135')->update(['g1_quant' => 0.0005]);
        $this->assertSame(33.33, $metas()->porMinuto($this->op));
        $this->assertSame(2, MetaRegistrada::count());
        $this->assertSame(49.02, $metas()->porMinuto($this->op, $antes));
        $this->assertSame(49.02, $metas()->porMinuto($this->op, $antes->copy()->subYear())); // antes do 1º registro
        $this->assertSame(33.33, $metas()->porMinuto($this->op, now()));

        // Estrutura sem MO: a meta acaba a partir daqui (registro sem meta), mas o passado continua.
        Carbon::setTestNow(now()->addDay());
        EstruturaProduto::where('g1_comp', 'like', 'MO-%')->update(['d_e_l_e_t_' => '*']);
        $this->assertNull($metas()->porMinuto($this->op));
        $this->assertSame(3, MetaRegistrada::count());
        $this->assertSame(49.02, $metas()->porMinuto($this->op, $antes));

        // O agendador registra as metas das OPs atuais das máquinas.
        $this->artisan('metas:registrar')->assertSuccessful();
    }

    public function test_cor_da_barra_pelo_percentual_da_meta(): void
    {
        $nivel = fn ($pecas, $meta, $futura = false) => ProducaoPorHora::nivelMeta(['pecas' => $pecas, 'metaAteAgora' => $meta, 'futura' => $futura]);
        $this->assertSame('boa', $nivel(90, 100));
        $this->assertSame('media', $nivel(89, 100));
        $this->assertSame('media', $nivel(50, 100));
        $this->assertSame('ruim', $nivel(49, 100));
        $this->assertNull($nivel(10, null));
        $this->assertNull($nivel(0, 100, true));

        // Hora atual: compara com a meta só até agora (10:00 em ponto = hora 10h sem meta ainda; 09h inteira).
        $this->apontamento('09:00:00', '09:59:59', 1480, 1, 2); // 1.480 de 2.941 = 50,3%
        $dados = $this->actingAs($this->admin())->get(route('producao-hora'))->assertOk()
            ->assertSee('meta-media')->assertSee('meta-ruim')->viewData('maquinas')[0];
        $this->assertSame('media', ProducaoPorHora::nivelMeta(collect($dados['horas'])->firstWhere('hora', '09:00')));
        $this->assertSame('ruim', ProducaoPorHora::nivelMeta(collect($dados['horas'])->firstWhere('hora', '08:00')));
    }

    public function test_meta_de_cada_hora_segue_a_op_de_cada_momento_e_fica_gravada(): void
    {
        // OP 2: outro produto, MO de 0,001 h → 16,67 pç/min. Troca de OP às 08:30.
        ProdutoProtheus::forceCreate(['b1_filial' => '', 'b1_cod' => 'PB2', 'b1_desc' => 'PB2', 'b1_tipo' => 'PA', 'd_e_l_e_t_' => ' ']);
        EstruturaProduto::forceCreate(['g1_filial' => '', 'g1_cod' => 'PB2', 'g1_comp' => 'MO-1135', 'g1_trt' => '', 'g1_quant' => 0.001, 'd_e_l_e_t_' => ' ']);
        $op2 = OrdemProducao::create(['numero' => '00020001001', 'c2_num' => '000200', 'c2_item' => '01', 'c2_sequen' => '001',
            'c2_produto' => 'PB2', 'c2_datrf' => '', 'status' => 'aberta', 'd_e_l_e_t_' => ' ', 'r_e_c_n_o_' => 2]);
        $this->apontamento('06:00:00', '08:29:59', 3000, 2, 3); // OP 1
        $fuso = config('oee.fuso_exibicao');
        DB::table('apontamentos_estado')->insert(['maquina_id' => $this->maquina->id, 'legenda_id' => $this->produzindo->id,
            'ordem_producao_id' => $op2->id, 'inicio' => Carbon::parse('2026-11-17 08:30:00', $fuso)->utc(), 'fim' => null,
            'ciclos' => 100, 'cavidades' => 1, 'pecas' => 100, 'ciclo_medio' => 30, 'observacao' => 'Troca de OP',
            'criado_em' => now(), 'atualizado_em' => now()]);
        $this->maquina->update(['ordem_producao_id' => $op2->id]);

        $admin = $this->admin();
        $dados = $this->actingAs($admin)->get(route('producao-hora'))->assertOk()->viewData('maquinas')[0];
        $meta = collect($dados['horas'])->pluck('meta', 'hora');
        $this->assertEquals(2941, $meta['06:00']);  // OP 1 inteira (não mudou com a troca)
        $this->assertEquals(2941, $meta['07:00']);
        $this->assertEquals(1971, $meta['08:00']);  // metade OP 1 (1.470,6) + metade OP 2 (500,1)
        $this->assertEquals(1000, $meta['09:00']);  // OP 2
        $this->assertEquals(1000, $meta['15:00']);  // futuro: OP atual
        $oito = collect($dados['horas'])->firstWhere('hora', '08:00');
        $this->assertSame(['00010001001', '00020001001'], array_column($oito['ops'], 'numero'));

        // Gravadas por hora e OP (só as horas que já começaram).
        $hora8 = Carbon::parse('2026-11-17 08:00', $fuso)->utc();
        $linhas = MetaHora::where('hora_inicio', $hora8)->orderBy('ordem_producao_id')->get();
        $this->assertCount(2, $linhas);
        $this->assertEqualsWithDelta(1470.6, $linhas[0]->meta, 0.1);
        $this->assertSame(1800, $linhas[0]->segundos);
        $this->assertEqualsWithDelta(500.1, $linhas[1]->meta, 0.1);
        $this->assertSame(0, MetaHora::where('hora_inicio', '>', now())->count());
        $total = MetaHora::count();
        $this->actingAs($admin)->get(route('producao-hora'))->assertOk();
        $this->assertSame($total, MetaHora::count()); // sem duplicar

        // App: a mesma meta por hora.
        $porHora = collect(app(MaquinasAplicativo::class)->detalhe($this->maquina->fresh())['producao']['por_hora'])->keyBy('hora');
        $this->assertSame(1971, $porHora['08:00']['meta']);
        $this->assertSame(2941, $porHora['06:00']['meta']);
        $this->assertCount(2, $porHora['08:00']['ops']);

        // O agendador grava as horas mesmo sem ninguém abrir as telas.
        MetaHora::query()->delete();
        $this->artisan('metas:registrar')->assertSuccessful();
        $this->assertSame($total, MetaHora::count());
    }

    public function test_fator_de_servico_da_mao_de_obra_entra_na_meta_dali_para_frente(): void
    {
        // Fórmula do usuário: ((0,00034 + 5%) × 100) ÷ 1,6667 = 0,0214196 h; × 60 = 1,285176 s; 60 ÷ 1,285176 = 46,69.
        $conta = MetaProducao::calcular(0.00034, 1, 'MO-1135', 5);
        $this->assertEqualsWithDelta(0.0214196, $conta['segundos_por_peca'] / 60, 0.0000001);
        $this->assertEqualsWithDelta(1.285176, $conta['segundos_por_peca'], 0.00001);
        $this->assertSame(46.69, $conta['por_minuto']);
        $this->assertSame(49.02, MetaProducao::calcular(0.00034)['por_minuto']); // fator 0 = conta anterior

        // A tela lista os MO da SB1 com fator 0 (os novos entram sozinhos) e o administrador altera o fator.
        $antes = now()->subMinute();
        $this->assertSame(49.02, app(MetaProducao::class)->porMinuto($this->op)); // registra a meta com fator 0
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('mao-de-obra.index'))->assertOk()->assertSee('MO-1135')->assertSee('MO-9999');
        $mo = MaoDeObra::where('b1_cod', 'MO-1135')->sole();
        $this->assertSame(0.0, $mo->fator_servico);
        $this->put(route('mao-de-obra.update', $mo), ['fator_servico' => 150])->assertSessionHasErrors('fator_servico');
        Carbon::setTestNow(now()->addHour());
        app()->forgetScopedInstances();
        $this->put(route('mao-de-obra.update', $mo), ['fator_servico' => 5])->assertSessionHasNoErrors();
        $this->assertSame(5.0, $mo->fresh()->fator_servico);

        app()->forgetScopedInstances();
        $metas = app(MetaProducao::class);
        $this->assertSame(46.69, $metas->porMinuto($this->op));          // dali para frente
        $this->assertSame(49.02, $metas->porMinuto($this->op, $antes));  // o passado não muda
        $this->assertSame(5.0, (float) MetaRegistrada::latest('id')->value('fator_servico'));

        // Quem não é administrador só consulta.
        $operador = Usuario::create(['entra_oid' => 'op', 'nome' => 'Operador', 'email' => 'op@alumbra.com.br', 'perfil' => 'operador']);
        $this->actingAs($operador)->put(route('mao-de-obra.update', $mo), ['fator_servico' => 1])->assertForbidden();
    }

    public function test_intervalo_entre_turnos_nao_tem_meta(): void
    {
        // Turno 1 08:00–09:00 e turno 2 10:00–11:00: a hora das 09 (intervalo) fica sem meta; as outras têm 49,02 × 60.
        $fuso = config('oee.fuso_exibicao');
        Carbon::setTestNow(Carbon::parse('2026-11-17 12:00', $fuso));
        $de = CarbonImmutable::parse('2026-11-17 08:00', $fuso);
        $ate = CarbonImmutable::parse('2026-11-17 11:00', $fuso);
        $horas = array_map(fn ($h) => ['hora' => $h->format('H:i'), 'inicio' => $h->utc()->toIso8601String(), 'pecas' => 0],
            [$de, $de->addHour(), $de->addHours(2)]);
        $turnos = [['numero' => 1, 'de' => $de, 'ate' => $de->addHour()], ['numero' => 2, 'de' => $de->addHours(2), 'ate' => $ate]];
        $r = app(MetaPorHora::class)->completar($this->maquina, $horas, $de, $ate, $fuso, [], $this->op, false, $turnos);
        $this->assertSame([2941, null, 2941], array_column($r, 'meta'));
        Carbon::setTestNow();
    }
}
