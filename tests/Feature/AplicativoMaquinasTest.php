<?php

namespace Tests\Feature;

use App\Models\ApontamentoEstado;
use App\Models\Ferramenta;
use App\Models\Legenda;
use App\Models\Linha;
use App\Models\Manutencao\AreaExecutante;
use App\Models\Manutencao\SolicitacaoServico;
use App\Models\Maquina;
use App\Models\OrdemProducao;
use App\Models\Planta;
use App\Models\ProdutoProtheus;
use App\Models\RoteiroOperacao;
use App\Models\Usuario;
use App\Services\Aplicativo\LoginAplicativo;
use App\Services\Manutencao\FluxoOrdemServico;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CalendarioDeTeste;
use Tests\Concerns\IotDeTeste;
use Tests\TestCase;

/** Aplicativo › Máquinas: lista, apontamentos, motivo da parada 98, troca de OP, roteiro/molde e cavidades. */
class AplicativoMaquinasTest extends TestCase
{
    use CalendarioDeTeste;
    use IotDeTeste;
    use RefreshDatabase;

    private const VERIFICADOR = 'segredo-do-app-com-pelo-menos-quarenta-e-tres-caracteres-123';

    private Maquina $maquina;

    private Legenda $produzindo;

    private Legenda $semMotivo;

    private Legenda $quebra;

    private OrdemProducao $op1;

    private OrdemProducao $op2;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $planta = Planta::create(['codigo' => 'P', 'nome' => 'Planta']);
        $linha = Linha::create(['planta_id' => $planta->id, 'codigo' => 'L', 'nome' => 'Linha 1']);
        $this->maquina = Maquina::create(['codigo' => 'INJ-01', 'nome' => 'Injetora 01', 'linha_id' => $linha->id, 'ativo' => true]
            + $this->jornadaGravada() + $this->iotGravado());
        Maquina::create(['codigo' => 'INJ-02', 'nome' => 'Injetora sem IoT', 'linha_id' => $linha->id, 'ativo' => false]);
        $this->produzindo = Legenda::create(['codigo' => '1', 'descricao' => 'Produzindo', 'tipo' => 'produzindo', 'cor' => '#2e7d32']);
        $this->semMotivo = Legenda::create(['codigo' => '98', 'descricao' => 'Parada sem motivo', 'tipo' => 'parada', 'cor' => '#b42318']);
        $this->quebra = Legenda::create(['codigo' => '10', 'descricao' => 'Quebra de molde', 'tipo' => 'parada', 'cor' => '#d97706']);
        Legenda::create(['codigo' => '20', 'descricao' => 'Refeição', 'tipo' => 'planejada', 'cor' => '#1d5fa8']);
        $this->op1 = OrdemProducao::create(['numero' => '00010001001', 'c2_num' => '000100', 'c2_item' => '01', 'c2_sequen' => '001',
            'c2_produto' => 'PA1', 'c2_datrf' => '', 'status' => 'aberta', 'd_e_l_e_t_' => ' ', 'r_e_c_n_o_' => 1, 'c2_quant' => 1000, 'c2_quje' => 250]);
        $this->op2 = OrdemProducao::create(['numero' => '00020001001', 'c2_num' => '000200', 'c2_item' => '01', 'c2_sequen' => '001',
            'c2_produto' => 'PA2', 'c2_datrf' => '', 'status' => 'aberta', 'd_e_l_e_t_' => ' ', 'r_e_c_n_o_' => 2]);

        $operador = Usuario::create(['entra_oid' => 'op', 'nome' => 'Operador', 'email' => 'op@alumbra.com.br', 'perfil' => 'operador']);
        $login = app(LoginAplicativo::class);
        $this->token = $login->trocarCodigo($login->criarCodigo($operador, LoginAplicativo::desafioDe(self::VERIFICADOR)), self::VERIFICADOR, null, null)['token'];
    }

    /** Fixa o relógio em HOJE, no horário local informado (a tela da máquina mostra só o dia de hoje). */
    private function travar(string $hora): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-17 '.$hora, config('oee.fuso_exibicao'))->utc());
    }

    private function api(string $metodo, string $url, array $dados = [])
    {
        return $this->json($metodo, '/api/app/'.$url, $dados, ['Authorization' => 'Bearer '.$this->token]);
    }

    public function test_lista_so_maquinas_ativas_com_iot_e_conta_paradas_sem_motivo(): void
    {
        ApontamentoEstado::create(['maquina_id' => $this->maquina->id, 'legenda_id' => $this->semMotivo->id, 'inicio' => now()->subMinutes(30)]);

        $json = $this->api('GET', 'maquinas')->assertOk()->json('maquinas');
        $this->assertCount(1, $json);
        $this->assertSame('Injetora 01', $json[0]['nome']);
        $this->assertSame(1, $json[0]['sem_motivo']);
    }

    public function test_turno_noturno_de_ontem_em_andamento_e_o_que_aparece(): void
    {
        $this->maquina->update(['jornada_inicio' => '22:00', 'jornada_fim' => '06:00']);
        $this->travar('02:00'); // terça 17/11, 02:00: o turno de segunda 22:00 ainda está em andamento
        $json = $this->api('GET', "maquinas/{$this->maquina->id}")->assertOk()->json();
        $this->assertSame('Turno de 16/11 · 22:00 às 06:00', $json['periodo']['rotulo']);

        $this->travar('23:00'); // terça 23:00: o turno de terça já começou
        $json = $this->api('GET', "maquinas/{$this->maquina->id}")->assertOk()->json();
        $this->assertSame('Turno de hoje, 17/11 · 22:00 às 06:00', $json['periodo']['rotulo']);
    }

    public function test_detalhe_traz_apontamentos_pendencia_e_so_legendas_de_parada(): void
    {
        $this->travar('15:00');
        ApontamentoEstado::create(['maquina_id' => $this->maquina->id, 'legenda_id' => $this->produzindo->id,
            'inicio' => now()->subDay(), 'fim' => now()->subDay()->addHour()]); // ontem: fora da tela
        ApontamentoEstado::create(['maquina_id' => $this->maquina->id, 'legenda_id' => $this->produzindo->id,
            'inicio' => now()->subHours(12), 'fim' => now()->subHours(11)]); // 03:00–04:00: antes do turno (06:00–22:00)
        ApontamentoEstado::create(['maquina_id' => $this->maquina->id, 'legenda_id' => $this->produzindo->id,
            'inicio' => now()->subHours(2), 'fim' => now()->subHour()]);
        $ap = ApontamentoEstado::create(['maquina_id' => $this->maquina->id, 'legenda_id' => $this->semMotivo->id, 'inicio' => now()->subHour()->addSecond()]);

        $json = $this->api('GET', "maquinas/{$this->maquina->id}")->assertOk()->json();
        $this->assertCount(2, $json['apontamentos']); // só os de hoje, dentro do turno
        $this->assertSame('Turno de hoje, 17/11 · 06:00 às 22:00', $json['periodo']['rotulo']);
        $this->assertSame($ap->id, $json['sem_motivo'][0]['id']);
        $codigos = array_column($json['legendas_motivo'], 'codigo');
        $this->assertContains('10', $codigos);
        $this->assertNotContains('98', $codigos); // nunca o próprio "sem motivo"
        $this->assertNotContains('1', $codigos);  // produzindo não é motivo de parada
        $this->assertNotContains('20', $codigos); // só legendas do tipo parada
        $this->assertSame(['parada'], array_values(array_unique(array_column($json['legendas_motivo'], 'tipo'))));
        $this->assertNull($json['op']);
    }

    public function test_motivo_troca_a_legenda_so_daquele_apontamento(): void
    {
        $ap = ApontamentoEstado::create(['maquina_id' => $this->maquina->id, 'legenda_id' => $this->semMotivo->id, 'inicio' => now()->subHour()]);

        $this->api('POST', "apontamentos/{$ap->id}/motivo", ['legenda_id' => $this->produzindo->id])->assertUnprocessable();
        $this->api('POST', "apontamentos/{$ap->id}/motivo", ['legenda_id' => $this->quebra->id])->assertOk()->assertJsonCount(0, 'sem_motivo');

        $this->assertSame($this->quebra->id, $ap->fresh()->legenda_id);
        $this->assertSame('parada', $this->maquina->fresh()->situacao);
        $this->api('POST', "apontamentos/{$ap->id}/motivo", ['legenda_id' => $this->quebra->id])->assertUnprocessable(); // já tem motivo
    }

    public function test_motivo_com_legenda_que_abre_ss_abre_a_ss_automatica(): void
    {
        $area = AreaExecutante::create(['codigo' => 'MEC', 'nome' => 'Mecânica', 'unidade' => 'manutencao', 'ativo' => true]);
        $this->quebra->forceFill(['abre_ss_unidade' => 'manutencao', 'abre_ss_area_executante_id' => $area->id])->save();
        $ap = ApontamentoEstado::create(['maquina_id' => $this->maquina->id, 'legenda_id' => $this->semMotivo->id, 'inicio' => now()->subHour()]);
        $this->assertSame(0, SolicitacaoServico::count()); // "sem motivo" não abre

        $this->api('POST', "apontamentos/{$ap->id}/motivo", ['legenda_id' => $this->quebra->id])->assertOk();
        $ss = SolicitacaoServico::sole();
        $this->assertTrue($ss->automatica);
        $this->assertSame([$this->maquina->id, $area->id, $ap->id], [$ss->maquina_id, $ss->area_executante_id, $ss->apontamento_estado_id]);
        $this->assertStringStartsWith('OS automática', $ss->titulo);
        // Solicitante = o operador logado no app (não o IoT).
        $this->assertSame('Operador', $ss->solicitante->nome);
        $this->assertNull($ss->origem_iot);

        // Máquina parada: no lugar da meta vem o número e a situação da SS (e, aprovada, da OS).
        $this->assertSame('parada', $this->maquina->fresh()->situacao);
        $this->api('GET', "maquinas/{$this->maquina->id}")->assertOk()
            ->assertJsonPath('maquina.chamado.numero', $ss->numeroFormatado())->assertJsonPath('maquina.chamado.rotulo', 'Aguardando análise');
        $os = app(FluxoOrdemServico::class)->aprovar($ss, $ss->solicitante, []);
        $lista = collect($this->api('GET', 'maquinas')->assertOk()->json('maquinas'))->firstWhere('id', $this->maquina->id);
        $this->assertSame([$os->numeroFormatado(), 'os'], [$lista['chamado']['numero'], $lista['chamado']['tipo']]);

        // Voltou a produzir: some (volta a meta).
        $this->maquina->forceFill(['situacao' => 'produzindo', 'situacao_desde' => now()])->save();
        $this->api('GET', "maquinas/{$this->maquina->id}")->assertOk()->assertJsonPath('maquina.chamado', null);
    }

    public function test_alteracoes_pelo_aplicativo_gravam_quem_alterou_e_quando(): void
    {
        $operador = Usuario::where('entra_oid', 'op')->first();
        $ap = ApontamentoEstado::create(['maquina_id' => $this->maquina->id, 'legenda_id' => $this->semMotivo->id,
            'ordem_producao_id' => $this->op1->id, 'inicio' => now()->subHour()]);
        $this->assertNull($ap->atualizado_por_id);

        // Motivo da parada.
        $this->api('POST', "apontamentos/{$ap->id}/motivo", ['legenda_id' => $this->quebra->id])->assertOk();
        $this->assertSame($operador->id, (int) $ap->fresh()->atualizado_por_id);
        $this->assertNotNull($ap->fresh()->atualizado_em);

        // Troca de OP: o aberto é encerrado (alterado pelo operador) e o novo é registrado por ele.
        $ap->forceFill(['atualizado_por_id' => null])->saveQuietly();
        $this->api('POST', "maquinas/{$this->maquina->id}/op", ['numero' => $this->op2->numero])->assertOk();
        $this->assertNotNull($ap->fresh()->fim);
        $this->assertSame($operador->id, (int) $ap->fresh()->atualizado_por_id);
        $novo = ApontamentoEstado::where('maquina_id', $this->maquina->id)->whereNull('fim')->first();
        $this->assertSame($operador->id, (int) $novo->criado_por_id);
    }

    public function test_trocar_op_divide_o_apontamento_aberto_com_outra_op(): void
    {
        $ap = ApontamentoEstado::create(['maquina_id' => $this->maquina->id, 'legenda_id' => $this->produzindo->id,
            'ordem_producao_id' => $this->op1->id, 'inicio' => now()->subHour()]);
        $this->maquina->update(['ordem_producao_id' => $this->op1->id]);

        $this->api('POST', "maquinas/{$this->maquina->id}/op", ['numero' => '99999999999'])->assertUnprocessable();
        $json = $this->api('POST', "maquinas/{$this->maquina->id}/op", ['numero' => $this->op2->numero])->assertOk()->json();

        $this->assertSame($this->op2->numero, $json['op']['numero']);
        $this->assertNotNull($ap->fresh()->fim);
        $novo = ApontamentoEstado::where('maquina_id', $this->maquina->id)->whereNull('fim')->first();
        $this->assertSame($this->op2->id, $novo->ordem_producao_id);
        $this->assertSame($this->produzindo->id, $novo->legenda_id);
        $this->assertSame($ap->fresh()->fim->addSecond()->getTimestamp(), $novo->inicio->getTimestamp());
        $this->assertSame($this->op2->id, $this->maquina->fresh()->ordem_producao_id);
    }

    public function test_trocar_op_no_app_com_modo_atual_troca_o_apontamento_inteiro(): void
    {
        $ap = ApontamentoEstado::create(['maquina_id' => $this->maquina->id, 'legenda_id' => $this->produzindo->id,
            'ordem_producao_id' => $this->op1->id, 'inicio' => now()->subHour()]);

        $this->api('POST', "maquinas/{$this->maquina->id}/op", ['numero' => $this->op2->numero, 'modo' => 'atual'])->assertOk();
        $this->assertSame(1, ApontamentoEstado::count());
        $this->assertSame($this->op2->id, $ap->fresh()->ordem_producao_id);
    }

    public function test_trocar_op_preenche_o_apontamento_aberto_sem_op(): void
    {
        $ap = ApontamentoEstado::create(['maquina_id' => $this->maquina->id, 'legenda_id' => $this->produzindo->id, 'inicio' => now()->subHour()]);

        $this->api('POST', "maquinas/{$this->maquina->id}/op", ['numero' => $this->op1->numero])->assertOk();
        $this->assertSame($this->op1->id, $ap->fresh()->ordem_producao_id);
        $this->assertNull($ap->fresh()->fim);
        $this->assertSame(1, ApontamentoEstado::count());
    }

    public function test_op_traz_roteiro_e_molde_e_cavidades_respeitam_o_limite(): void
    {
        RoteiroOperacao::create(['g2_filial' => '', 'g2_produto' => 'PA1', 'g2_codigo' => '01', 'g2_operac' => '10',
            'g2_descri' => 'INJETAR', 'g2_ctrab' => 'INJ-01', 'g2_ferram' => 'M001', 'd_e_l_e_t_' => ' ', 'r_e_c_n_o_' => 1]);
        $molde = Ferramenta::forceCreate(['h4_filial' => '', 'h4_codigo' => 'M001', 'h4_descri' => 'MOLDE TAMPA', 'h4_qtdcav' => 8,
            'h4_qtdcavativas' => 8, 'd_e_l_e_t_' => ' ', 'r_e_c_n_o_' => 1]);
        $this->maquina->update(['ordem_producao_id' => $this->op1->id]);

        $op = $this->api('GET', "maquinas/{$this->maquina->id}")->assertOk()->json('op');
        $this->assertSame('INJETAR', $op['roteiro']['operacoes'][0]['descricao']);
        $this->assertTrue($op['roteiro']['operacoes'][0]['desta_maquina']);
        $this->assertSame('M001', $op['moldes'][0]['codigo']);

        $this->api('POST', "ferramentas/{$molde->id}/cavidades", ['cavidades' => 9, 'maquina_id' => $this->maquina->id])->assertUnprocessable();
        $this->api('POST', "ferramentas/{$molde->id}/cavidades", ['cavidades' => 6, 'maquina_id' => $this->maquina->id])
            ->assertOk()->assertJsonPath('op.moldes.0.cavidades_ativas', 6);
        $this->assertSame(6, $molde->fresh()->h4_qtdcavativas);

        // Molde que não é da OP atual desta máquina: recusado.
        $outro = Ferramenta::forceCreate(['h4_filial' => '', 'h4_codigo' => 'M999', 'h4_descri' => 'OUTRO', 'h4_qtdcav' => 4,
            'h4_qtdcavativas' => 4, 'd_e_l_e_t_' => ' ', 'r_e_c_n_o_' => 2]);
        $this->api('POST', "ferramentas/{$outro->id}/cavidades", ['cavidades' => 1, 'maquina_id' => $this->maquina->id])->assertForbidden();
        $this->assertSame(4, $outro->fresh()->h4_qtdcavativas);
    }

    public function test_busca_de_ops_so_abertas(): void
    {
        OrdemProducao::create(['numero' => '00030001001', 'c2_num' => '000300', 'c2_item' => '01', 'c2_sequen' => '001',
            'c2_produto' => 'PA3', 'c2_datrf' => '20261101', 'status' => 'fechada', 'd_e_l_e_t_' => ' ', 'r_e_c_n_o_' => 3]);

        $numeros = array_column($this->api('GET', 'ops?busca=000')->assertOk()->json('ops'), 'numero');
        $this->assertEqualsCanonicalizing([$this->op1->numero, $this->op2->numero], $numeros);
        // Mesma busca do portal: meio do número, SKU ou descrição do produto.
        ProdutoProtheus::forceCreate(['b1_filial' => '', 'b1_cod' => 'PA2', 'b1_desc' => 'TAMPA REDONDA', 'd_e_l_e_t_' => ' ', 'r_e_c_n_o_' => 9]);
        $this->assertSame([$this->op2->numero], array_column($this->api('GET', 'ops?busca=20001')->json('ops'), 'numero'));
        $this->assertSame([$this->op2->numero], array_column($this->api('GET', 'ops?busca=redonda')->json('ops'), 'numero'));
        $this->assertSame([$this->op1->numero], array_column($this->api('GET', 'ops?busca=PA1')->json('ops'), 'numero'));
    }

    public function test_maquinas_no_app_so_para_o_perfil_operador(): void
    {
        $mantenedor = Usuario::create(['entra_oid' => 'mant', 'nome' => 'Mantenedor', 'email' => 'mant@alumbra.com.br']);
        $mantenedor->definirPerfis(['mantenedor_producao']);
        $login = app(LoginAplicativo::class);
        $token = $login->trocarCodigo($login->criarCodigo($mantenedor->fresh(), LoginAplicativo::desafioDe(self::VERIFICADOR)), self::VERIFICADOR, null, null);

        $this->assertFalse($token['usuario']->temPerfil('operador'));
        app('auth')->forgetGuards();
        $this->getJson('/api/app/maquinas', ['Authorization' => 'Bearer '.$token['token']])->assertForbidden();
        $this->api('GET', 'maquinas')->assertOk(); // o operador entra
    }

    public function test_maquina_inativa_ou_sem_iot_nao_abre(): void
    {
        $sem = Maquina::withoutGlobalScopes()->where('codigo', 'INJ-02')->first();
        $this->api('GET', "maquinas/{$sem->id}")->assertNotFound();
    }

    public function test_pecas_pelo_iot_contam_ciclos_vezes_cavidades_somam_na_op_e_aparecem_no_app_e_na_timeline(): void
    {
        $this->travar('10:30');
        RoteiroOperacao::create(['g2_filial' => '', 'g2_produto' => 'PA1', 'g2_codigo' => '01', 'g2_operac' => '10',
            'g2_descri' => 'INJETAR', 'g2_ctrab' => 'INJ-01', 'g2_ferram' => 'M001', 'd_e_l_e_t_' => ' ', 'r_e_c_n_o_' => 1]);
        $molde = Ferramenta::forceCreate(['h4_filial' => '', 'h4_codigo' => 'M001', 'h4_descri' => 'MOLDE TAMPA', 'h4_qtdcav' => 8,
            'h4_qtdcavativas' => 4, 'd_e_l_e_t_' => ' ', 'r_e_c_n_o_' => 1]);
        $this->maquina->update(['ordem_producao_id' => $this->op1->id, 'situacao' => 'produzindo']);
        // O integrador grava direto no banco: produção 09:00–10:00 (100 ciclos) e em aberto desde 10:00 (30 ciclos).
        $novo = fn ($ini, $fim, $ciclos) => DB::table('apontamentos_estado')->insertGetId([
            'maquina_id' => $this->maquina->id, 'legenda_id' => $this->produzindo->id, 'ordem_producao_id' => $this->op1->id,
            'inicio' => $ini, 'fim' => $fim, 'ciclos' => $ciclos, 'ciclo_medio' => 20, 'observacao' => '[IoT] x', 'criado_em' => now(), 'atualizado_em' => now()]);
        $a1 = $novo(now()->subMinutes(90), now()->subMinutes(30)->subSecond(), 100);
        $a2 = $novo(now()->subMinutes(30), null, 30);
        $parada = DB::table('apontamentos_estado')->insertGetId(['maquina_id' => $this->maquina->id,
            'legenda_id' => $this->semMotivo->id, 'inicio' => now()->subMinutes(100), 'fim' => now()->subMinutes(90)->subSecond(),
            'ciclos' => 5, 'observacao' => '[IoT] x', 'criado_em' => now(), 'atualizado_em' => now()]);

        config(['oee.iot.token' => 'token-do-integrador-com-mais-de-32-caracteres']);
        $this->postJson('/api/iot/apontamentos/processar', ['producao' => [$a1, $a2, $parada]], ['Authorization' => 'Bearer token-do-integrador-com-mais-de-32-caracteres'])
            ->assertOk()->assertJsonPath('ops', [$this->op1->id]);
        // 4 cavidades ativas no molde da OP nesta máquina; pulsos numa parada (ciclo lento) também são peças.
        $this->assertSame([4, 400], [ApontamentoEstado::find($a1)->cavidades, ApontamentoEstado::find($a1)->pecas]);
        $this->assertSame(5, ApontamentoEstado::find($parada)->pecas); // parada sem OP e sem molde: 1 por ciclo
        $this->assertEquals(520, $this->op1->fresh()->quantidade_real);

        // Próximo ciclo do integrador: o aberto cresceu. As cavidades ficam as da 1ª conta, mesmo mudando no molde.
        $molde->update(['h4_qtdcavativas' => 8]);
        DB::table('apontamentos_estado')->where('id', $a2)->update(['ciclos' => 45]);
        $this->postJson('/api/iot/apontamentos/processar', ['producao' => [$a2]], ['Authorization' => 'Bearer token-do-integrador-com-mais-de-32-caracteres'])->assertOk();
        $this->assertEquals(580, $this->op1->fresh()->quantidade_real);

        // App: peças por hora no turno (06:00–22:00), total e peças por minuto (60 ÷ 20 s × 8 cavidades).
        $prod = $this->api('GET', "maquinas/{$this->maquina->id}")->assertOk()->json('producao');
        $porHora = collect($prod['por_hora'])->pluck('pecas', 'hora');
        $this->assertSame(16, count($prod['por_hora']));
        $this->assertSame([5, 400, 180], [$porHora['08:00'], $porHora['09:00'], $porHora['10:00']]);
        $this->assertSame(585, $prod['pecas_turno']);
        $this->assertEquals(24, $prod['pecas_por_minuto']);
        $this->assertNull($prod['meta_por_minuto']);

        // Trocar a OP do apontamento (portal): refaz a conta com o molde da OP nova (sem roteiro = 1 por ciclo).
        ApontamentoEstado::find($a1)->update(['ordem_producao_id' => $this->op2->id]);
        $this->assertSame([1, 100], [ApontamentoEstado::find($a1)->cavidades, ApontamentoEstado::find($a1)->pecas]);
        $this->assertEquals([180, 100], [$this->op1->fresh()->quantidade_real, $this->op2->fresh()->quantidade_real]);

        // Timeline: peças por minuto no cartão da máquina.
        $adm = Usuario::create(['entra_oid' => 'adm-tl', 'nome' => 'Adm', 'email' => 'adm-tl@alumbra.com.br', 'perfil' => 'admin']);
        $this->actingAs($adm)->get('/dashboards/timeline?parcial=1')->assertOk()->assertSee('24 <small>pç/min</small>', false);
    }

    public function test_conferencia_refaz_pecas_desatualizadas_e_soma_a_op(): void
    {
        $id = DB::table('apontamentos_estado')->insertGetId(['maquina_id' => $this->maquina->id,
            'legenda_id' => $this->produzindo->id, 'ordem_producao_id' => $this->op1->id, 'inicio' => now()->subHour(),
            'ciclos' => 12, 'observacao' => '[IoT] x', 'criado_em' => now(), 'atualizado_em' => now()]);
        $this->artisan('pecas:conferir')->assertSuccessful();
        $this->assertSame([1, 12], [ApontamentoEstado::find($id)->cavidades, ApontamentoEstado::find($id)->pecas]);
        $this->assertEquals(12, $this->op1->fresh()->quantidade_real);
        // Ciclos subiram e o aviso se perdeu: a próxima conferência acerta.
        DB::table('apontamentos_estado')->where('id', $id)->update(['ciclos' => 30]);
        $this->artisan('pecas:conferir')->assertSuccessful();
        $this->assertEquals(30, $this->op1->fresh()->quantidade_real);
    }

    public function test_porta_de_entrada_com_maiusculas_vai_para_a_subpasta_certa(): void
    {
        config(['app.url' => 'https://www.alumbra.com.br/oee']);
        $this->call('GET', '/OEE/dashboards/timeline', [], [], [], ['REQUEST_URI' => '/OEE/dashboards/timeline'])
            ->assertRedirect('https://www.alumbra.com.br/oee/dashboards/timeline')->assertStatus(301);
    }

    public function test_detalhes_da_op_com_kardex_empenhos_e_engenharia(): void
    {
        ProdutoProtheus::forceCreate(['b1_filial' => '', 'b1_cod' => 'PA1', 'b1_desc' => 'TAMPA', 'd_e_l_e_t_' => ' ', 'r_e_c_n_o_' => 1]);
        ProdutoProtheus::forceCreate(['b1_filial' => '', 'b1_cod' => 'MP1', 'b1_desc' => 'RESINA PP', 'b1_um' => 'KG', 'd_e_l_e_t_' => ' ', 'r_e_c_n_o_' => 2]);
        $mov = fn ($rec, $cf, $tipo, $cod, $qtd, $custo) => DB::table('movimentos_producao')->insert(['d3_filial' => '', 'op' => $this->op1->numero,
            'op_numero' => $this->op1->numero, 'd3_emissao' => '20261110', 'd3_cf' => $cf, 'tipo' => $tipo, 'd3_cod' => $cod, 'd3_um' => 'UN',
            'd3_quant' => $qtd, 'd3_custo1' => $custo, 'd3_doc' => 'DOC'.$rec, 'd3_local' => '01', 'd3_numseq' => (string) $rec,
            'd3_estorno' => '', 'd_e_l_e_t_' => ' ', 'r_e_c_n_o_' => $rec]);
        $mov(1, 'RE1', 'consumo', 'MP1', 5, 50);
        $mov(2, 'PR0', 'producao', 'PA1', 100, 80);
        DB::table('empenhos_op')->insert(['d4_filial' => '', 'op_numero' => $this->op1->numero, 'd4_op' => $this->op1->numero, 'd4_cod' => 'MP1',
            'd4_trt' => '', 'd4_local' => '01', 'd4_data' => '20261101', 'd4_qtdeori' => 10, 'd4_quant' => 4, 'd_e_l_e_t_' => ' ', 'r_e_c_n_o_' => 1]);
        RoteiroOperacao::forceCreate(['g2_filial' => '', 'g2_produto' => 'PA1', 'g2_codigo' => '01', 'g2_operac' => '10',
            'g2_descri' => 'INJETAR', 'g2_ctrab' => 'INJ-01', 'g2_ferram' => 'M001', 'd_e_l_e_t_' => ' ', 'r_e_c_n_o_' => 1]);
        $this->maquina->update(['ordem_producao_id' => $this->op1->id]);

        $json = $this->api('GET', "ops/{$this->op1->id}/detalhes")->assertOk()->json();
        $this->assertSame(['00010001001', 'TAMPA', 100.0], [$json['op']['numero'], $json['op']['descricao'], (float) $json['op']['produzido']]);
        $this->assertSame(2, $json['kardex']['total']);
        $this->assertSame('producao', $json['kardex']['movimentos'][0]['tipo']); // o mais recente primeiro
        $this->assertEquals(0.5, $json['custo']['simulado']); // baixas 50 ÷ 100 peças
        $this->assertSame(['MP1', 'RESINA PP', 6.0, 0.6], [$json['empenhos'][0]['componente'], $json['empenhos'][0]['descricao'],
            (float) $json['empenhos'][0]['baixado'], (float) $json['empenhos'][0]['atendido']]);
        $this->assertSame('INJ-01', $json['roteiro']['operacoes'][0]['maquina']);
        $this->assertTrue($json['roteiro']['operacoes'][0]['com_esta_op']);

        $this->api('GET', 'ops/999999/detalhes')->assertNotFound();
    }
}
