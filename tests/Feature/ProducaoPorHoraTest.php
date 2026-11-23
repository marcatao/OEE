<?php

namespace Tests\Feature;

use App\Models\FiltroUsuario;
use App\Models\Legenda;
use App\Models\Linha;
use App\Models\Maquina;
use App\Models\OrdemProducao;
use App\Models\Planta;
use App\Models\ProdutoProtheus;
use App\Models\Usuario;
use App\Services\Aplicativo\MaquinasAplicativo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CalendarioDeTeste;
use Tests\Concerns\IotDeTeste;
use Tests\TestCase;

/** Dashboards › Produção por hora: peças por hora do turno de cada máquina, OP, produto e OPs do turno. */
class ProducaoPorHoraTest extends TestCase
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
        // Terça, 17/11/2026, 10:00 em São Paulo (turno da máquina: 06:00–22:00, seg a sex).
        Carbon::setTestNow(Carbon::parse('2026-11-17 10:00', config('oee.fuso_exibicao'))->utc());
        $planta = Planta::create(['codigo' => 'P', 'nome' => 'Planta']);
        $linha = Linha::create(['planta_id' => $planta->id, 'codigo' => 'L', 'nome' => 'Linha 1']);
        $this->maquina = Maquina::create(['codigo' => 'INJ-01', 'nome' => 'Injetora 01', 'linha_id' => $linha->id, 'ativo' => true]
            + $this->jornadaGravada() + $this->iotGravado());
        $this->produzindo = Legenda::create(['codigo' => '1', 'descricao' => 'Produzindo', 'tipo' => 'produzindo', 'cor' => '#2e7d32']);
        ProdutoProtheus::forceCreate(['b1_filial' => '', 'b1_cod' => 'PA1', 'b1_desc' => 'TAMPA REDONDA', 'd_e_l_e_t_' => ' ', 'r_e_c_n_o_' => 9]);
        $this->op = OrdemProducao::create(['numero' => '00010001001', 'c2_num' => '000100', 'c2_item' => '01', 'c2_sequen' => '001',
            'c2_produto' => 'PA1', 'c2_datrf' => '', 'status' => 'aberta', 'd_e_l_e_t_' => ' ', 'r_e_c_n_o_' => 1, 'c2_quant' => 1000]);
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

    /** Apontamento com peças gravado direto (como o integrador): início/fim em horário local de hoje. */
    private function apontamento(string $inicio, ?string $fim, int $pecas, ?int $opId = null): void
    {
        $fuso = config('oee.fuso_exibicao');
        DB::table('apontamentos_estado')->insert([
            'maquina_id' => $this->maquina->id, 'legenda_id' => $this->produzindo->id, 'ordem_producao_id' => $opId ?? $this->op->id,
            'inicio' => Carbon::parse('2026-11-17 '.$inicio, $fuso)->utc(), 'fim' => $fim ? Carbon::parse('2026-11-17 '.$fim, $fuso)->utc() : null,
            'ciclos' => $pecas, 'cavidades' => 1, 'pecas' => $pecas, 'ciclo_medio' => 30, 'observacao' => '[IoT] x',
            'criado_em' => now(), 'atualizado_em' => now(),
        ]);
    }

    public function test_mostra_pecas_por_hora_do_turno_com_op_e_produto(): void
    {
        $outra = OrdemProducao::create(['numero' => '00020001001', 'c2_num' => '000200', 'c2_item' => '01', 'c2_sequen' => '001',
            'c2_produto' => 'PA2', 'c2_datrf' => '', 'status' => 'aberta', 'd_e_l_e_t_' => ' ', 'r_e_c_n_o_' => 2]);
        $this->apontamento('06:00:00', '07:59:59', 240, $outra->id); // 120 por hora (06h e 07h)
        $this->apontamento('08:00:00', '08:59:59', 150);

        $resposta = $this->actingAs($this->admin())->get(route('producao-hora'))->assertOk()
            ->assertSee('Produção por hora')->assertSee('Injetora 01')->assertSee('00010001001')->assertSee('TAMPA REDONDA')
            ->assertSee('Turno de hoje, 17/11 · 06:00 às 22:00')->assertSee('OP 00020001001');
        $dados = $resposta->viewData('maquinas')[0];
        $porHora = collect($dados['horas'])->pluck('pecas', 'hora');
        $this->assertCount(16, $dados['horas']); // 06h a 21h
        $this->assertSame([120, 120, 150, 0], [$porHora['06:00'], $porHora['07:00'], $porHora['08:00'], $porHora['09:00']]);
        $this->assertSame(390, $dados['total']);
        $this->assertTrue(collect($dados['horas'])->firstWhere('hora', '10:00')['atual']);
        $this->assertTrue(collect($dados['horas'])->firstWhere('hora', '11:00')['futura']);
        $this->assertSame([240, 150], array_column($dados['ops'], 'pecas')); // a OP que mais produziu primeiro
        $this->assertSame(2.0, $dados['pecasPorMinuto']); // 60 ÷ 30 s × 1 cavidade
        $this->assertNull($dados['metaPorMinuto']); // OP sem item MO na estrutura: sem meta
        $this->assertSame(390, $resposta->viewData('geral')['total']);

        // Atualização sem recarregar: só a área de resultado.
        $this->get(route('producao-hora', ['parcial' => 1]))->assertOk()->assertDontSee('<html', false)->assertSee('Injetora 01');
    }

    public function test_dia_anterior_usa_o_turno_daquele_dia_e_nao_fica_ao_vivo(): void
    {
        $fuso = config('oee.fuso_exibicao');
        DB::table('apontamentos_estado')->insert(['maquina_id' => $this->maquina->id, 'legenda_id' => $this->produzindo->id,
            'ordem_producao_id' => $this->op->id, 'inicio' => Carbon::parse('2026-11-16 14:00', $fuso)->utc(),
            'fim' => Carbon::parse('2026-11-16 14:59:59', $fuso)->utc(), 'ciclos' => 80, 'cavidades' => 1, 'pecas' => 80,
            'criado_em' => now(), 'atualizado_em' => now()]);

        $resposta = $this->actingAs($this->admin())->get(route('producao-hora', ['dia' => '2026-11-16']))->assertOk()
            ->assertSee('Turno de 16/11 · 06:00 às 22:00');
        $dados = $resposta->viewData('maquinas')[0];
        $this->assertSame(80, collect($dados['horas'])->firstWhere('hora', '14:00')['pecas']);
        $this->assertFalse($dados['aoVivo']);
        $this->assertFalse(collect($dados['horas'])->contains(fn ($h) => $h['atual'] || $h['futura']));
    }

    public function test_salva_filtro_e_abre_com_o_padrao(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('producao-hora.filtros.salvar'), [
            'nome' => 'Injetora', 'periodo' => 'hoje', 'padrao' => 1, 'maquinas' => [$this->maquina->id],
        ])->assertRedirect();
        $salvo = FiltroUsuario::where('tela', 'producao_hora')->sole();
        $this->assertSame([$this->maquina->id], $salvo->filtros['maquinas']);
        $this->get(route('producao-hora'))->assertOk()->assertViewHas('escolhido', fn ($e) => (int) $e->id === (int) $salvo->id);

        $this->delete(route('producao-hora.filtros.excluir', $salvo))->assertRedirect(route('producao-hora'));
        $this->assertSame(0, FiltroUsuario::count());
    }

    public function test_operador_ve_so_as_maquinas_da_sua_unidade_no_portal_e_no_app(): void
    {
        $tecnologia = Maquina::create(['codigo' => 'TEC-01', 'nome' => 'Impressora 3D', 'linha_id' => $this->maquina->linha_id,
            'ativo' => true, 'unidade' => 'tecnologia'] + $this->jornadaGravada() + $this->iotGravado());
        $operador = Usuario::create(['entra_oid' => 'op', 'nome' => 'Op', 'email' => 'op@alumbra.com.br', 'perfil' => 'operador']);
        $this->assertTrue($operador->pode('producao_hora'));

        $resposta = $this->actingAs($operador)->get(route('producao-hora'))->assertOk()
            ->assertSee('Injetora 01')->assertDontSee('Impressora 3D');
        $this->assertSame([(int) $this->maquina->id], array_map(fn ($d) => (int) $d['maquina']->id, $resposta->viewData('maquinas')));
        // Pedir a máquina de outra unidade pelo filtro não adianta.
        $this->get(route('producao-hora', ['maquinas' => [$tecnologia->id]]))->assertOk()->assertDontSee('Impressora 3D');

        // App (função Máquinas): a mesma regra; o gráfico de peças por hora vem no detalhe da máquina.
        $this->assertNotContains((int) $tecnologia->id, collect(app(MaquinasAplicativo::class)->lista())
            ->map(fn ($m) => (int) $m['id'])->all());
        $this->assertArrayHasKey('por_hora', app(MaquinasAplicativo::class)->detalhe($this->maquina->fresh())['producao']);
    }

    public function test_sem_a_permissao_nao_abre(): void
    {
        $solicitante = Usuario::create(['entra_oid' => 'sol', 'nome' => 'Sol', 'email' => 'sol@alumbra.com.br', 'perfil' => 'operador']);
        $solicitante->definirPerfis(['solicitante_manutencao']);
        $this->actingAs($solicitante)->get(route('producao-hora'))->assertForbidden();
    }
}
