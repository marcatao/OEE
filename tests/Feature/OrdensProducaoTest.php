<?php

namespace Tests\Feature;

use App\Models\ApontamentoEstado;
use App\Models\Grupo;
use App\Models\Legenda;
use App\Models\Linha;
use App\Models\Maquina;
use App\Models\OrdemProducao;
use App\Models\Planta;
use App\Models\ProdutoProtheus;
use App\Models\Usuario;
use App\Services\Protheus\ImportadorOrdensProducao;
use App\Services\Protheus\ImportadorProdutos;
use App\Services\Protheus\SincronizadorProtheus;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Produtos (SB1) e ordens de produção (SC2) do Protheus, status da OP e OP no apontamento.
 * O "Protheus" dos testes é um SQLite em memória separado.
 */
class OrdensProducaoTest extends TestCase
{
    use RefreshDatabase;

    private Maquina $maquina;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-05 15:00', 'UTC'));
        config(['oee.fuso_exibicao' => 'America/Sao_Paulo']);
        config(['database.connections.protheus' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('protheus');
        foreach (['SB1' => ImportadorProdutos::CAMPOS, 'SC2' => ImportadorOrdensProducao::CAMPOS] as $tabela => $campos) {
            Schema::connection('protheus')->create($tabela, function (Blueprint $t) use ($campos) {
                foreach ($campos as $c) {
                    in_array($c, ['R_E_C_N_O_', 'C2_QUANT', 'C2_QUJE', 'C2_PERDA'], true) ? $t->float($c)->default(0) : $t->string($c)->default('');
                }
            });
        }
        $this->sb1('PA001          ', 'TAMPA 50MM                    ', 1);
        $this->sb1('PA002', 'FRASCO 100ML', 2);
        $this->sc2('509859', 'PA001', 1, ['C2_QUANT' => 1000]);                                  // aberta
        $this->sc2('509860', 'PA002', 2, ['C2_QUANT' => 500, 'C2_QUJE' => 120]);                // andamento (já produziu)
        $this->sc2('509861', 'PA001', 3, ['C2_DATRF' => '20261104']);                            // fechada
        $this->sc2('509862', 'PA002', 4, ['C2_STATUS' => 'U']);                                  // parada (suspensa)

        $planta = Planta::create(['codigo' => 'P', 'nome' => 'Planta']);
        $linha = Linha::create(['planta_id' => $planta->id, 'codigo' => 'L', 'nome' => 'Linha']);
        $this->maquina = Maquina::create(['linha_id' => $linha->id, 'codigo' => 'INJ-01', 'nome' => 'Injetora 01', 'ativo' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sb1(string $cod, string $desc, int $recno, string $del = ' '): void
    {
        DB::connection('protheus')->table('SB1')->insert(['B1_FILIAL' => '', 'B1_COD' => $cod, 'B1_DESC' => $desc, 'B1_TIPO' => 'PA',
            'B1_UM' => 'UN', 'B1_GRUPO' => '', 'B1_MSBLQL' => '2', 'D_E_L_E_T_' => $del, 'R_E_C_N_O_' => $recno]);
    }

    private function sc2(string $num, string $produto, int $recno, array $extra = []): void
    {
        DB::connection('protheus')->table('SC2')->insert($extra + ['C2_FILIAL' => '01', 'C2_NUM' => $num, 'C2_ITEM' => '01', 'C2_SEQUEN' => '001',
            'C2_PRODUTO' => $produto, 'C2_UM' => 'UN', 'C2_QUANT' => 100, 'C2_QUJE' => 0, 'C2_EMISSAO' => '20261101',
            'C2_DATPRI' => '20261103', 'C2_DATPRF' => '20261110', 'C2_DATRF' => '', 'C2_TPOP' => 'F', 'C2_STATUS' => 'N',
            'D_E_L_E_T_' => ' ', 'R_E_C_N_O_' => $recno]);
    }

    private function sincronizar(string $chave)
    {
        return app(SincronizadorProtheus::class)->sincronizar($chave, 'comando');
    }

    private function usuario(array $permissoes, string $email = 'op@empresa.com'): Usuario
    {
        $u = Usuario::create(['entra_oid' => 'oid-'.$email, 'nome' => 'Fulano', 'email' => $email, 'perfil' => 'operador']);
        $g = Grupo::create(['nome' => 'G '.$email, 'ativo' => true]);
        $g->definirPermissoes($permissoes);
        $g->usuarios()->attach($u->id);

        return $u;
    }

    public function test_produtos_da_sb1_sao_copiados_e_so_o_que_muda_e_gravado(): void
    {
        $r = $this->sincronizar('produtos');
        $this->assertSame(['sucesso', 2, 2], [$r->situacao, $r->lidos, $r->novos]);
        $this->assertSame('TAMPA 50MM', ProdutoProtheus::where('b1_cod', 'PA001')->sole()->descricao());

        $this->assertSame([0, 0], [$this->sincronizar('produtos')->novos, $this->sincronizar('produtos')->atualizados]);

        DB::connection('protheus')->table('SB1')->where('R_E_C_N_O_', 2)->update(['B1_DESC' => 'FRASCO 100ML PET']);
        DB::connection('protheus')->table('SB1')->where('R_E_C_N_O_', 1)->update(['D_E_L_E_T_' => '*']);
        $r = $this->sincronizar('produtos');
        $this->assertSame([1, 1], [$r->atualizados, $r->excluidos]);
        // Excluído no Protheus continua no portal, só marcado (nada é apagado).
        $this->assertSame(2, ProdutoProtheus::count());
        $this->assertTrue(ProdutoProtheus::where('b1_cod', 'PA001')->sole()->bloqueado());
    }

    public function test_ordens_da_sc2_chegam_com_numero_e_status(): void
    {
        $r = $this->sincronizar('ordens_producao');
        $this->assertSame(['sucesso', 4, 4], [$r->situacao, $r->lidos, $r->novos]);

        $status = OrdemProducao::orderBy('numero')->pluck('status', 'numero')->all();
        $this->assertSame(['50985901001' => 'aberta', '50986001001' => 'andamento', '50986101001' => 'fechada', '50986201001' => 'parada'], $status);
        $op = OrdemProducao::where('c2_num', '509859')->sole();
        $this->assertSame([1000.0, 'PA001', '20261110'], [$op->c2_quant, $op->c2_produto, $op->c2_datprf]);
    }

    public function test_status_muda_quando_a_op_muda_no_protheus(): void
    {
        $this->sincronizar('ordens_producao');
        DB::connection('protheus')->table('SC2')->where('C2_NUM', '509859')->update(['C2_QUJE' => 10]);
        DB::connection('protheus')->table('SC2')->where('C2_NUM', '509860')->update(['C2_DATRF' => '20261105']);
        $r = $this->sincronizar('ordens_producao');

        $this->assertSame(2, $r->atualizados);
        $this->assertSame('andamento', OrdemProducao::where('c2_num', '509859')->value('status'));
        $this->assertSame('fechada', OrdemProducao::where('c2_num', '509860')->value('status'));
    }

    public function test_op_excluida_e_recriada_no_protheus_e_filtro_de_data(): void
    {
        DB::connection('protheus')->table('SC2')->where('R_E_C_N_O_', 1)->update(['D_E_L_E_T_' => '*']);
        $this->sc2('509859', 'PA001', 10, ['C2_QUANT' => 2000]); // recriada com a mesma chave
        $this->sc2('400000', 'PA001', 11, ['C2_EMISSAO' => '20250101']); // antiga

        config(['protheus.sc2_desde' => '20260101']);
        $this->sincronizar('ordens_producao');

        $this->assertSame(2000.0, OrdemProducao::where('c2_num', '509859')->sole()->c2_quant);
        $this->assertFalse(OrdemProducao::where('c2_num', '400000')->exists());
    }

    public function test_muitas_ops_sao_gravadas_em_lotes(): void
    {
        foreach (range(1, 250) as $i) {
            $this->sc2((string) (600000 + $i), 'PA001', 100 + $i);
        }
        $this->assertSame(254, $this->sincronizar('ordens_producao')->novos);
        $this->assertSame(254, OrdemProducao::count());
    }

    public function test_apontamento_com_op_atualiza_o_status_da_op(): void
    {
        $this->sincronizar('produtos');
        $this->sincronizar('ordens_producao');
        $op = OrdemProducao::where('c2_num', '509859')->sole();
        $prod = Legenda::where('codigo', 'PROD')->sole();
        $dt = Legenda::where('codigo', 'DT')->sole();
        $this->actingAs($this->usuario(['apontamentos', 'timeline']));

        // Formulário lista as OPs não fechadas, com a descrição do produto.
        $this->get('/producao/apontamentos/novo')->assertOk()
            ->assertSee('value="50985901001"', false)->assertSee('TAMPA 50MM · Aberta')
            ->assertDontSee('value="50986101001"', false); // fechada não aparece

        $this->post('/producao/apontamentos', ['maquina_id' => $this->maquina->id, 'legenda_id' => $prod->id,
            'inicio' => '2026-11-05T08:00', 'ordem_producao' => '50985901001'])->assertSessionDoesntHaveErrors();
        $this->assertSame($op->id, ApontamentoEstado::sole()->ordem_producao_id);
        $this->assertSame('andamento', $op->fresh()->status);

        // Parada em aberto na mesma OP → parada; o apontamento anterior é encerrado.
        $this->post('/producao/apontamentos', ['maquina_id' => $this->maquina->id, 'legenda_id' => $dt->id,
            'inicio' => '2026-11-05T10:00', 'ordem_producao' => '50985901001'])->assertSessionDoesntHaveErrors();
        $this->assertSame('parada', $op->fresh()->status);

        // Timeline mostra a OP na barra e a lista de apontamentos mostra a OP com o status.
        $this->get('/dashboards/timeline')->assertSee('OP 50985901001');
        $this->get('/producao/apontamentos')->assertSee('50985901001')->assertSee('op-parada', false);

        // Excluir o apontamento em aberto: volta a andamento (ainda tem apontamento de produção).
        $this->delete('/producao/apontamentos/'.ApontamentoEstado::whereNull('fim')->sole()->id);
        $this->assertSame('andamento', $op->fresh()->status);
    }

    public function test_apontamento_recusa_op_inexistente_ou_fechada(): void
    {
        $this->sincronizar('ordens_producao');
        $prod = Legenda::where('codigo', 'PROD')->sole();
        $this->actingAs($this->usuario(['apontamentos']));
        $base = ['maquina_id' => $this->maquina->id, 'legenda_id' => $prod->id, 'inicio' => '2026-11-05T08:00'];

        $this->post('/producao/apontamentos', $base + ['ordem_producao' => '999'])->assertSessionHasErrors('ordem_producao');
        $this->post('/producao/apontamentos', $base + ['ordem_producao' => '50986101001'])->assertSessionHasErrors('ordem_producao');
        $this->post('/producao/apontamentos', $base)->assertSessionDoesntHaveErrors(); // OP é opcional
    }

    public function test_tela_de_ordens_de_producao(): void
    {
        $this->sincronizar('produtos');
        $this->sincronizar('ordens_producao');

        $this->actingAs($this->usuario(['apontamentos'], 'sem@empresa.com'))->get('/producao/ordens-de-producao')->assertForbidden();

        $this->actingAs($this->usuario(['ordens_producao']));
        $this->get('/producao/ordens-de-producao')->assertOk()
            ->assertSee('50985901001')->assertSee('TAMPA 50MM')->assertSee('FRASCO 100ML')
            ->assertSee('Ordens de produção', false)->assertSee('op-parada', false)
            ->assertSee('03/11/2026 a 10/11/2026');
        $this->get('/producao/ordens-de-producao?status[]=fechada')->assertSee('50986101001')->assertDontSee('50985901001');
        $this->get('/producao/ordens-de-producao?busca=frasco')->assertSee('50986001001')->assertDontSee('50985901001');
    }
}
