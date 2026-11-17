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
use App\Models\Sincronizacao;
use App\Models\Usuario;
use App\Services\CustoOrdemProducao;
use App\Services\Protheus\ImportadorMovimentosProducao;
use App\Services\Protheus\ImportadorNotasFiscaisOp;
use App\Services\Protheus\ImportadorOrdensProducao;
use App\Services\Protheus\ImportadorProdutos;
use App\Services\Protheus\SincronizadorProtheus;
use App\Services\StatusOrdemProducao;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\IotDeTeste;
use Tests\TestCase;

/**
 * Produtos (SB1) e ordens de produção (SC2) do Protheus, status da OP e OP no apontamento.
 * O "Protheus" dos testes é um SQLite em memória separado.
 */
class OrdensProducaoTest extends TestCase
{
    use IotDeTeste;
    use RefreshDatabase;

    private Maquina $maquina;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-05 15:00', 'UTC'));
        config(['oee.fuso_exibicao' => 'America/Sao_Paulo']);
        config(['database.connections.protheus' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('protheus');
        $sb9 = ['B9_FILIAL', 'B9_COD', 'B9_LOCAL', 'B9_DATA', 'B9_QINI', 'B9_VINI1', 'B9_CM1', 'D_E_L_E_T_', 'R_E_C_N_O_'];
        $sb2 = ['B2_FILIAL', 'B2_COD', 'B2_LOCAL', 'B2_QATU', 'B2_VATU1', 'B2_CM1', 'D_E_L_E_T_', 'R_E_C_N_O_'];
        $numericos = ['R_E_C_N_O_', 'C2_QUANT', 'C2_QUJE', 'C2_PERDA', 'D3_QUANT', 'D3_CUSTO1', 'D2_QUANT', 'D2_CUSTO1', 'B9_QINI', 'B9_VINI1', 'B9_CM1',
            'B2_QATU', 'B2_VATU1', 'B2_CM1'];
        foreach (['SB1' => ImportadorProdutos::CAMPOS, 'SC2' => ImportadorOrdensProducao::CAMPOS, 'SB9' => $sb9, 'SB2' => $sb2,
            'SD3' => [...ImportadorMovimentosProducao::CAMPOS, 'D3_ORDEM'], 'SD2' => [...ImportadorNotasFiscaisOp::CAMPOS, 'D2_OP']] as $tabela => $campos) {
            Schema::connection('protheus')->create($tabela, function (Blueprint $t) use ($campos, $numericos) {
                foreach ($campos as $c) {
                    in_array($c, $numericos, true) ? $t->float($c)->default(0) : $t->string($c)->default('');
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
        $this->maquina = Maquina::create(['linha_id' => $linha->id, 'codigo' => 'INJ-01', 'nome' => 'Injetora 01', 'ativo' => true] + $this->iotGravado());
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

    private function usuario(array $permissoes, string $email = 'op@empresa.com', array $perfis = []): Usuario
    {
        $u = Usuario::create(['entra_oid' => 'oid-'.$email, 'nome' => 'Fulano', 'email' => $email, 'perfil' => 'operador']);
        if ($perfis) {
            $u->definirPerfis($perfis);
        }
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
        // A OP 509860 está na máquina; ao fechar no Protheus, sai dela (a 509859, aberta, fica).
        $m1 = Maquina::create(['codigo' => 'M1', 'nome' => 'M1', 'ativo' => false, 'ordem_producao_id' => OrdemProducao::where('c2_num', '509860')->value('id')]);
        $m2 = Maquina::create(['codigo' => 'M2', 'nome' => 'M2', 'ativo' => false, 'ordem_producao_id' => OrdemProducao::where('c2_num', '509859')->value('id')]);
        DB::connection('protheus')->table('SC2')->where('C2_NUM', '509859')->update(['C2_QUJE' => 10]);
        DB::connection('protheus')->table('SC2')->where('C2_NUM', '509860')->update(['C2_DATRF' => '20261105']);
        $r = $this->sincronizar('ordens_producao');

        $this->assertNull($m1->fresh()->ordem_producao_id);
        $this->assertNotNull($m2->fresh()->ordem_producao_id);
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

        // Campo da OP com busca: acha as OPs não fechadas pelo número ou produto, com a descrição do produto.
        $this->get('/producao/apontamentos/novo')->assertOk()->assertSee('data-busca-url', false);
        $this->getJson('/producao/ordens-de-producao/busca?q=5098')->assertOk()
            ->assertJsonFragment(['valor' => '50985901001', 'texto' => '50985901001 · PA001 TAMPA 50MM · Aberta'])
            ->assertJsonMissing(['valor' => '50986101001']); // fechada não aparece

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

        $this->actingAs($this->usuario(['apontamentos'], 'sem@empresa.com', ['mantenedor_producao']))->get('/producao/ordens-de-producao')->assertForbidden();

        $this->actingAs($this->usuario(['ordens_producao']));
        $this->get('/producao/ordens-de-producao')->assertOk()
            ->assertSee('50985901001')->assertSee('TAMPA 50MM')->assertSee('FRASCO 100ML')
            ->assertSee('Ordens de produção', false)->assertSee('op-parada', false)
            ->assertSee('03/11/2026 a 10/11/2026');
        $this->get('/producao/ordens-de-producao?status[]=fechada')->assertSee('50986101001')->assertDontSee('50985901001');
        $this->get('/producao/ordens-de-producao?busca=frasco')->assertSee('50986001001')->assertDontSee('50985901001');
    }

    public function test_inicio_mostra_ops_em_andamento_e_lista_as_abertas(): void
    {
        $this->sincronizar('produtos');
        $this->sincronizar('ordens_producao');

        // Sem a permissão da tela de OPs: nada de OPs na página inicial.
        $this->actingAs($this->usuario(['apontamentos'], 'sem@empresa.com', ['mantenedor_producao']))->get('/')->assertOk()
            ->assertDontSee('OPs em andamento')->assertDontSee('50985901001');

        $this->actingAs($this->usuario(['ordens_producao']))->get('/')->assertOk()
            ->assertSee('OPs em andamento')->assertSee('3 abertas')->assertSee('1 parada')
            ->assertSee('Ordens de produção abertas')
            ->assertSee('50985901001')->assertSee('50986001001')->assertSee('50986201001') // aberta, andamento e parada
            ->assertDontSee('50986101001')                                               // fechada fica de fora
            ->assertSee('TAMPA 50MM')->assertSee('op-andamento', false)
            ->assertSeeInOrder(['50986201001', '50986001001', '50985901001']);          // parada, andamento, aberta
    }

    private function sd3(int $recno, string $op, string $cf, string $produto, float $quant, float $custo, array $extra = []): void
    {
        DB::connection('protheus')->table('SD3')->insert($extra + ['D3_FILIAL' => '01', 'D3_TM' => str_starts_with($cf, 'RE') ? '999' : '001',
            'D3_CF' => $cf, 'D3_COD' => $produto, 'D3_UM' => 'UN', 'D3_QUANT' => $quant, 'D3_LOCAL' => '01', 'D3_DOC' => 'DOC'.$recno,
            'D3_EMISSAO' => '20261104', 'D3_NUMSEQ' => str_pad((string) $recno, 6, '0', STR_PAD_LEFT), 'D3_CUSTO1' => $custo,
            'D3_ESTORNO' => '', 'D3_ORDEM' => $op, 'D_E_L_E_T_' => ' ', 'R_E_C_N_O_' => $recno]);
    }

    private function sb9(string $produto, string $data, float $qtd, float $valor, float $cm, int $recno, string $local = '01', string $filial = '01'): void
    {
        DB::connection('protheus')->table('SB9')->insert(['B9_FILIAL' => $filial, 'B9_COD' => $produto, 'B9_LOCAL' => $local, 'B9_DATA' => $data,
            'B9_QINI' => $qtd, 'B9_VINI1' => $valor, 'B9_CM1' => $cm, 'D_E_L_E_T_' => ' ', 'R_E_C_N_O_' => $recno]);
    }

    /** OP 509860 (PA002): produziu 100, consumiu 2 componentes, devolveu um pouco e teve uma NF. Meta SB9 = 2,00. */
    private function cenarioDeCusto(): void
    {
        $this->sb1('MP001', 'RESINA PET', 3);
        $this->sb1('MP002', 'TAMPA', 4);
        $this->sb9('PA002', '20260930', 50, 90, 1.8, 1);                  // fechamento antigo: não vale
        $this->sb9('PA002', '20261031', 100, 180, 1.8, 2, '01');          // mais recente, dois armazéns:
        $this->sb9('PA002', '20261031', 50, 120, 2.4, 3, '02');           // (180 + 120) ÷ 150 = 2,00
        $this->sb9('PA001', '20261031', 10, 15, 1.5, 4, '01', '02');      // outra filial (não vale para OP da filial 01)

        $op = '50986001001   ';
        $this->sd3(1, $op, 'RE1', 'MP001', 10, 150);
        $this->sd3(2, $op, 'RE0', 'MP002', 100, 80);
        $this->sd3(3, $op, 'DE0', 'MP001', 1, 15);
        $this->sd3(4, $op, 'PR0', 'PA002', 100, 215, ['D3_EMISSAO' => '20261105']);
        $this->sd3(5, $op, 'RE1', 'MP001', 50, 999, ['D3_ESTORNO' => 'S']);    // estornado: fica de fora
        $this->sd3(6, $op, 'RE1', 'MP001', 50, 999, ['D_E_L_E_T_' => '*']);    // excluído: fica de fora
        $this->sd3(7, '', 'RE0', 'MP001', 5, 50);                              // sem OP: não vem
        $this->sd3(8, '50985901001', 'PR0', 'PA001', 10, 20);                  // outra OP
        $this->sd3(9, $op, 'RE1', 'MP002', 10, 0, ['D3_LOCAL' => '02']);       // Protheus ainda não valorizou: custo médio
        // Custo médio de estoque (SB2) da MP002: armazém 02 = 0,90 por unidade (o 01 não importa para esta baixa).
        foreach ([['01', 1000, 1100, 1.1, 1], ['02', 500, 450, 0.9, 2]] as [$local, $q, $v, $cm, $rec]) {
            DB::connection('protheus')->table('SB2')->insert(['B2_FILIAL' => '01', 'B2_COD' => 'MP002', 'B2_LOCAL' => $local,
                'B2_QATU' => $q, 'B2_VATU1' => $v, 'B2_CM1' => $cm, 'D_E_L_E_T_' => ' ', 'R_E_C_N_O_' => $rec]);
        }
        DB::connection('protheus')->table('SD2')->insert(['D2_FILIAL' => '01', 'D2_DOC' => '000123', 'D2_SERIE' => '1', 'D2_ITEM' => '01',
            'D2_COD' => 'PA002', 'D2_UM' => 'UN', 'D2_QUANT' => 40, 'D2_LOCAL' => '01', 'D2_EMISSAO' => '20261106', 'D2_CUSTO1' => 86,
            'D2_CF' => '5101', 'D2_CLIENTE' => 'C001', 'D2_LOJA' => '01', 'D2_OP' => $op, 'D_E_L_E_T_' => ' ', 'R_E_C_N_O_' => 1]);
        DB::connection('protheus')->table('SD2')->insert(['D2_FILIAL' => '01', 'D2_DOC' => '000124', 'D2_COD' => 'PA002', 'D2_QUANT' => 5,
            'D2_EMISSAO' => '20261106', 'D2_CUSTO1' => 10, 'D2_OP' => '', 'D_E_L_E_T_' => ' ', 'R_E_C_N_O_' => 2]);  // sem OP

        foreach (['produtos', 'ordens_producao', 'movimentos_producao', 'notas_fiscais_op'] as $chave) {
            $this->sincronizar($chave);
        }
    }

    public function test_sincroniza_movimentos_da_sd3_e_notas_da_sd2_com_op(): void
    {
        $this->cenarioDeCusto();

        // Só as linhas com OP; excluída nunca vista é ignorada; estornada vem marcada (e as telas a escondem).
        $this->assertSame([1, 2, 3, 4, 5, 8, 9], DB::table('movimentos_producao')->orderBy('r_e_c_n_o_')->pluck('r_e_c_n_o_')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(['consumo', 'consumo', 'devolucao', 'producao'],
            DB::table('movimentos_producao')->whereIn('r_e_c_n_o_', [1, 2, 3, 4])->orderBy('r_e_c_n_o_')->pluck('tipo')->all());
        $this->assertSame('50986001001', DB::table('movimentos_producao')->where('r_e_c_n_o_', 1)->value('op_numero'));
        $this->assertSame(1, DB::table('notas_fiscais_op')->count());

        // Movimento excluído depois no Protheus: fica marcado, não some.
        DB::connection('protheus')->table('SD3')->where('R_E_C_N_O_', 2)->update(['D_E_L_E_T_' => '*']);
        $this->sincronizar('movimentos_producao');
        $this->assertSame('*', DB::table('movimentos_producao')->where('r_e_c_n_o_', 2)->value('d_e_l_e_t_'));
    }

    public function test_custo_meta_vem_do_fechamento_mais_recente_da_sb9(): void
    {
        $this->cenarioDeCusto();

        $meta = fn (string $numero) => OrdemProducao::where('numero', $numero)->value('custo_meta');
        $this->assertEqualsWithDelta(2.0, $meta('50986001001'), 0.0001);  // PA002: média ponderada dos armazéns
        $this->assertNull($meta('50985901001'));                          // PA001: só há fechamento em outra filial

        // Novo fechamento no Protheus: a próxima sincronização das OPs atualiza a meta (sem mudar nada na SC2).
        $this->sb9('PA002', '20261130', 10, 25, 2.5, 5);
        $r = $this->sincronizar('ordens_producao');
        $this->assertEqualsWithDelta(2.5, $meta('50986001001'), 0.0001);
        $this->assertGreaterThanOrEqual(2, $r->atualizados);

        // Produto sem fechamento na SB9: meta vazia.
        $this->sc2('509870', 'PA999', 70);
        $this->sincronizar('ordens_producao');
        $this->assertNull($meta('50987001001'));

        // Fechamento na filial em branco (compartilhada) vale quando a filial da OP não tem.
        $this->sb9('PA999', '20261031', 4, 10, 2.5, 6, '01', '');
        $this->sincronizar('ordens_producao');
        $this->assertEqualsWithDelta(2.5, $meta('50987001001'), 0.0001);
    }

    public function test_codigos_so_com_numeros_vao_como_texto_para_a_sb9(): void
    {
        // Produto "85301" e filial "10": como chave de array do PHP viram inteiros; no SQL Server, um parâmetro
        // inteiro faz a coluna B9_COD inteira ser convertida para número e a consulta falha.
        $this->sb1('85301', 'PRODUTO NUMERICO', 9);
        $this->sb9('85301', '20261031', 10, 30, 3, 9, '01', '10');
        DB::connection('protheus')->table('SC2')->insert(['C2_FILIAL' => '10', 'C2_NUM' => '600001', 'C2_ITEM' => '01', 'C2_SEQUEN' => '001',
            'C2_PRODUTO' => '85301', 'C2_QUANT' => 10, 'C2_EMISSAO' => '20261101', 'D_E_L_E_T_' => ' ', 'R_E_C_N_O_' => 90]);

        $parametros = [];
        DB::connection('protheus')->listen(function ($q) use (&$parametros) {
            if (str_contains($q->sql, '"SB9"')) {
                array_push($parametros, ...$q->bindings);
            }
        });
        $this->assertSame('sucesso', $this->sincronizar('ordens_producao')->situacao);

        $this->assertContains('85301', $parametros);
        $this->assertContains('10', $parametros);
        $this->assertSame([], array_values(array_filter($parametros, fn ($p) => is_int($p))));
        $this->assertEqualsWithDelta(3.0, OrdemProducao::where('numero', '60000101001')->value('custo_meta'), 0.0001);
    }

    public function test_custo_atual_e_divergencia_na_lista_e_kardex_da_op(): void
    {
        $this->cenarioDeCusto();
        $op = OrdemProducao::where('numero', '50986001001')->sole();

        // Consumos = baixas 150 + 80 + (10 × 0,90 pelo custo médio) + nota 86 − devolução 15 = 310;
        // produzido 100 → 3,10 por peça; meta 2,00 por peça → +55%.
        $resumo = app(CustoOrdemProducao::class)->resumos([$op])[$op->id];
        $this->assertEqualsWithDelta(310, $resumo->custoLiquido(), 0.001);
        $this->assertEqualsWithDelta(3.10, $resumo->custoUnitario(), 0.0001);
        $this->assertEqualsWithDelta(0.55, $resumo->divergencia(), 0.0001);
        $this->assertEqualsWithDelta(40, $resumo->notaQtd, 0.001);
        $this->assertSame([1, 0], [$resumo->pelaMedia, $resumo->semCusto]);
        $this->assertEqualsWithDelta(0.9, DB::table('movimentos_producao')->where('r_e_c_n_o_', 9)->value('custo_medio'), 0.0001);

        $this->actingAs($this->usuario(['ordens_producao']));
        $this->get('/producao/ordens-de-producao?busca=50986001001')->assertOk()
            ->assertSee('Custo unit. meta')->assertSee('R$ 3,1000')->assertSee('R$ 2,0000')->assertSee('+55,0%')->assertSee('div-acima', false)
            ->assertSee(route('ordens-producao.show', $op), false);

        $this->get(route('ordens-producao.show', $op))->assertOk()
            ->assertSee('Kardex da OP')->assertSee('Consumo por componente')->assertSee('RESINA PET')
            ->assertSee('Baixa automática (empenho)')->assertSee('Nota fiscal')->assertSee('000123')
            ->assertSee('R$ 310,00')->assertSee('+55,0%')->assertSee('custo médio')
            ->assertDontSee('R$ 999,00');                                          // estornado e excluído fora
        $this->get('/producao/ordens-de-producao/999999')->assertNotFound();

        $this->actingAs($this->usuario(['apontamentos'], 'sem@empresa.com', ['mantenedor_producao']))->get(route('ordens-producao.show', $op))->assertForbidden();
    }

    public function test_tela_apontamentos_de_producao(): void
    {
        $this->cenarioDeCusto();

        $this->actingAs($this->usuario(['ordens_producao'], 'sem@empresa.com'))->get('/producao/apontamentos-de-producao')->assertForbidden();

        $this->actingAs($this->usuario(['apontamentos_producao']));
        $this->get('/producao/apontamentos-de-producao')->assertOk()
            ->assertSee('Apontamentos OP')->assertSee('Legendas do D3_CF')
            ->assertSee('DOC1')->assertSee('DOC4')->assertSee('000123')->assertSee('Nota fiscal')
            ->assertSee('mov-producao', false)->assertSee('mov-consumo', false)->assertSee('mov-nota_fiscal', false)
            ->assertDontSee('DOC5')->assertDontSee('DOC6')->assertDontSee('DOC7')->assertDontSee('000124');
        $this->get('/producao/apontamentos-de-producao?tipo[]=producao')->assertSee('DOC4')->assertDontSee('DOC1');
        $this->get('/producao/apontamentos-de-producao?busca=resina')->assertSee('DOC1')->assertDontSee('DOC4');
        $this->get('/producao/apontamentos-de-producao?de=2026-11-06')->assertSee('000123')->assertDontSee('DOC4');
    }

    public function test_sincronizacao_em_etapas_continua_de_onde_parou(): void
    {
        config(['protheus.lote' => 50]);
        foreach (range(1, 230) as $i) {
            $this->sc2((string) (700000 + $i), 'PA001', 100 + $i);
        }
        $sincronizador = app(SincronizadorProtheus::class);

        $registro = $sincronizador->iniciar('ordens_producao', 'manual');
        $etapas = 0;
        while ($registro->situacao === 'executando') {
            $registro = $sincronizador->continuar($registro, 0); // tempo zero: uma página por etapa
            $etapas++;
            $this->assertLessThan(10, $etapas);
        }

        $this->assertSame(5, $etapas);                 // 234 OPs em páginas de 50
        $this->assertSame(['sucesso', 234, 234, 330], [$registro->situacao, $registro->lidos, $registro->novos, $registro->ultimo_recno]);
        $this->assertSame(234, OrdemProducao::count());
        $this->assertSame(1, Sincronizacao::count());  // uma execução só, com os totais somados
    }

    public function test_botao_sincroniza_pela_etapa_em_json(): void
    {
        $admin = Usuario::create(['entra_oid' => 'oid-adm', 'nome' => 'Adm', 'email' => 'adm@empresa.com', 'perfil' => 'admin']);

        // 1ª chamada só abre a execução e devolve o id na hora.
        $r = $this->actingAs($admin)->postJson('/admin/banco/sincronizar/etapa', ['tabela' => 'ordens_producao'])->assertOk()
            ->assertJson(['tabela' => 'ordens_producao', 'situacao' => 'executando', 'terminou' => false, 'retomada' => false, 'lidos' => 0]);
        $this->postJson('/admin/banco/sincronizar/etapa', ['tabela' => 'ordens_producao', 'id' => $r->json('id')])->assertOk()
            ->assertJson(['id' => $r->json('id'), 'situacao' => 'sucesso', 'terminou' => true, 'lidos' => 4, 'novos' => 4]);

        // Continuar uma execução que já terminou só devolve o resultado.
        $this->postJson('/admin/banco/sincronizar/etapa', ['tabela' => 'ordens_producao', 'id' => $r->json('id')])
            ->assertOk()->assertJson(['id' => $r->json('id'), 'terminou' => true]);

        // Já existe uma execução em andamento (ex.: a etapa anterior foi cortada pelo IIS): o botão retoma essa, sem erro.
        $aberta = app(SincronizadorProtheus::class)->iniciar('produtos', 'manual', $admin);
        $this->postJson('/admin/banco/sincronizar/etapa', ['tabela' => 'produtos'])
            ->assertOk()->assertJson(['id' => $aberta->id, 'retomada' => true, 'terminou' => false]);
        $this->postJson('/admin/banco/sincronizar/etapa', ['tabela' => 'produtos', 'id' => $aberta->id])
            ->assertOk()->assertJson(['id' => $aberta->id, 'situacao' => 'sucesso', 'novos' => 2])
            ->assertJsonStructure(['segundos', 'tempos' => ['Protheus', 'portal']]);
        $this->assertSame(0, Sincronizacao::where('situacao', 'erro')->count());

        $this->postJson('/admin/banco/sincronizar/etapa', ['tabela' => 'usuarios'])->assertUnprocessable();
        $this->actingAs($this->usuario(['ordens_producao']))->postJson('/admin/banco/sincronizar/etapa', ['tabela' => 'produtos'])->assertForbidden();
        $this->get('/admin/banco')->assertForbidden();
    }

    public function test_etapa_cortada_no_meio_retoma_do_ultimo_lote_gravado(): void
    {
        config(['protheus.lote' => 50]);
        foreach (range(1, 230) as $i) {
            $this->sc2((string) (700000 + $i), 'PA001', 100 + $i);
        }
        $sincronizador = app(SincronizadorProtheus::class);
        $registro = $sincronizador->iniciar('ordens_producao', 'manual');

        // Simula o IIS matando o PHP no meio da 3ª página: os 2 primeiros lotes já foram gravados.
        $chamadas = 0;
        DB::connection('protheus')->listen(function ($q) use (&$chamadas) {
            if (str_contains($q->sql, '"SC2"') && ++$chamadas === 3) {
                throw new \RuntimeException('processo cortado');
            }
        });
        try {
            $sincronizador->continuar($registro);
        } catch (\RuntimeException) {
        }
        DB::connection('protheus')->flushQueryLog();
        DB::connection('protheus')->setEventDispatcher(new Dispatcher);

        $registro->refresh();
        $this->assertSame(196, $registro->ultimo_recno);   // 2 lotes de 50 (4 OPs do setUp + R_E_C_N_O_ 101 a 196)
        $this->assertSame(100, OrdemProducao::count());

        // A etapa "morta" deixou a trava presa e não dá sinal há 2 minutos: a próxima etapa assume e termina.
        Cache::lock("protheus-passo-{$registro->id}", 300)->get();
        $registro->update(['situacao' => 'executando', 'sinal_em' => now()->subMinutes(2), 'mensagem' => null]);
        $final = $sincronizador->continuar($registro);
        $this->assertSame('sucesso', $final->situacao);
        $this->assertSame(234, OrdemProducao::count());
    }

    public function test_sem_linhas_com_op_explica_o_motivo(): void
    {
        // D3_ORDEM vazio em toda a SD3, mas D3_OP preenchido (padrão do Protheus).
        Schema::connection('protheus')->table('SD3', fn (Blueprint $t) => $t->string('D3_OP')->default(''));
        $this->sd3(1, '', 'RE0', 'MP001', 1, 10, ['D3_OP' => '50986001001']);

        $r = $this->sincronizar('movimentos_producao');
        $this->assertSame(['sucesso', 0], [$r->situacao, $r->lidos]);
        $this->assertStringContainsString('Nenhuma linha com D3_ORDEM preenchido', $r->mensagem);
        $this->assertStringContainsString('PROTHEUS_SD3_CAMPO_OP=D3_OP', $r->mensagem);

        // Trocando o campo, os movimentos vêm.
        config(['protheus.sd3_campo_op' => 'D3_OP']);
        $this->assertSame(1, $this->sincronizar('movimentos_producao')->novos);

        $this->actingAs(Usuario::create(['entra_oid' => 'oid-a', 'nome' => 'A', 'email' => 'a@empresa.com', 'perfil' => 'admin']))
            ->get('/admin/banco')->assertOk();
    }

    public function test_movimentos_sao_lidos_de_forma_incremental(): void
    {
        $op = '50986001001';
        $this->sd3(1, $op, 'RE0', 'MP001', 1, 10, ['D3_EMISSAO' => '20260105']);   // antigos (fora da janela de 45 dias)
        $this->sd3(2, $op, 'RE0', 'MP001', 1, 10, ['D3_EMISSAO' => '20260106']);
        $this->sd3(10, $op, 'RE0', 'MP001', 1, 10, ['D3_EMISSAO' => '20261101']);  // recentes
        $this->sd3(11, $op, 'RE0', 'MP001', 1, 10, ['D3_EMISSAO' => '20261102']);
        $this->assertSame(4, $this->sincronizar('movimentos_producao')->lidos);  // primeira vez: tudo

        DB::connection('protheus')->table('SD3')->where('R_E_C_N_O_', 2)->update(['D3_CUSTO1' => 99]);       // mudança antiga
        DB::connection('protheus')->table('SD3')->where('R_E_C_N_O_', 11)->update(['D3_ESTORNO' => 'S']);     // estorno recente
        $this->sd3(12, $op, 'PR0', 'PA002', 5, 50, ['D3_EMISSAO' => '20261105']);                              // novo

        $r = $this->sincronizar('movimentos_producao');
        $this->assertSame([3, 1, 1], [$r->lidos, $r->novos, $r->atualizados]);    // relê só a janela (10, 11) e o novo (12)
        $this->assertSame('S', DB::table('movimentos_producao')->where('r_e_c_n_o_', 11)->value('d3_estorno'));
        $this->assertEqualsWithDelta(10, DB::table('movimentos_producao')->where('r_e_c_n_o_', 2)->value('d3_custo1'), 0.001);

        $r = app(SincronizadorProtheus::class)->sincronizar('movimentos_producao', 'comando', completa: true);
        $this->assertSame([5, 1], [$r->lidos, $r->atualizados]);                  // a completa pega a mudança antiga
        $this->assertEqualsWithDelta(99, DB::table('movimentos_producao')->where('r_e_c_n_o_', 2)->value('d3_custo1'), 0.001);

        $this->artisan('protheus:sincronizar movimentos_producao --completa')->assertSuccessful();
    }

    public function test_sincroniza_em_lotes_lendo_o_protheus_em_paginas(): void
    {
        config(['protheus.lote' => 50]);
        foreach (range(1, 230) as $i) {
            $this->sc2((string) (700000 + $i), 'PA001', 100 + $i);
        }

        DB::connection('protheus')->enableQueryLog();
        $r = $this->sincronizar('ordens_producao');
        $log = collect(DB::connection('protheus')->getQueryLog())->pluck('query');
        $consultas = $log->filter(fn ($q) => str_contains($q, '"SC2"'))->count();

        $this->assertSame(['sucesso', 234, 234], [$r->situacao, $r->lidos, $r->novos]);
        $this->assertSame(234, OrdemProducao::count());
        $this->assertSame(5, $consultas); // 234 linhas em páginas de 50 = 5 consultas ao Protheus
        // Custo meta: o produto (PA001) é consultado na SB9 uma vez só (filial 01 e filial em branco), não a cada lote.
        $this->assertSame(2, $log->filter(fn ($q) => str_contains($q, '"SB9"'))->count());

        $r = $this->sincronizar('ordens_producao');
        $this->assertSame([0, 0], [$r->novos, $r->atualizados]); // nada mudou
    }

    public function test_excluida_e_recriada_em_lotes_diferentes_fica_a_versao_ativa(): void
    {
        config(['protheus.lote' => 50]);
        foreach (range(1, 120) as $i) {
            $this->sc2((string) (800000 + $i), 'PA001', 100 + $i);
        }
        // A OP 509859 (R_E_C_N_O_ 1, primeiro lote) foi excluída e recriada (R_E_C_N_O_ 500, último lote).
        DB::connection('protheus')->table('SC2')->where('R_E_C_N_O_', 1)->update(['D_E_L_E_T_' => '*']);
        $this->sc2('509859', 'PA001', 500, ['C2_QUANT' => 3000]);

        $this->sincronizar('ordens_producao');
        $op = OrdemProducao::where('c2_num', '509859')->sole();
        $this->assertSame([' ', 3000.0, 500], [$op->d_e_l_e_t_, $op->c2_quant, $op->r_e_c_n_o_]);

        // Na próxima sincronização, a linha antiga excluída (primeiro lote) não marca a OP como excluída.
        $r = $this->sincronizar('ordens_producao');
        $this->assertSame(0, $r->excluidos);
        $this->assertSame(' ', $op->fresh()->d_e_l_e_t_);
    }

    public function test_sincronizacao_interrompida_nao_fica_presa_em_andamento(): void
    {
        $presa = Sincronizacao::create(['chave' => 'ordens_producao', 'origem' => 'Compras.SC2', 'disparo' => 'manual',
            'iniciado_em' => now()->subMinutes(30), 'situacao' => 'executando']);
        $recente = Sincronizacao::create(['chave' => 'produtos', 'origem' => 'Compras.SB1', 'disparo' => 'manual',
            'iniciado_em' => now()->subMinute(), 'situacao' => 'executando']);

        app(SincronizadorProtheus::class)->ultimas();

        $this->assertSame('erro', $presa->fresh()->situacao);
        $this->assertStringContainsString('Interrompida', $presa->fresh()->mensagem);
        $this->assertSame('executando', $recente->fresh()->situacao); // ainda dentro do tempo máximo
    }

    public function test_aberta_ou_fechada_e_decidido_so_pelo_c2_datrf(): void
    {
        $status = app(StatusOrdemProducao::class);
        $op = fn (array $campos) => new OrdemProducao($campos + ['c2_datrf' => '', 'c2_status' => 'N', 'c2_quje' => 0, 'd_e_l_e_t_' => ' ']);

        $this->assertSame('fechada', $status->calcular($op(['c2_datrf' => '20261105'])));
        $this->assertSame('aberta', $status->calcular($op([])));
        $this->assertSame('aberta', $status->calcular($op(['c2_datrf' => '        ']))); // vazio no Protheus vem com espaços
        $this->assertSame('aberta', $status->calcular($op(['d_e_l_e_t_' => '*']))); // excluída, mas sem data de fim
        $this->assertSame('parada', $status->calcular($op(['c2_status' => 'U'])));
        $this->assertSame('andamento', $status->calcular($op(['c2_quje' => 5])));
        $this->assertSame('fechada', $status->calcular($op(['c2_datrf' => '20261105', 'c2_status' => 'U', 'c2_quje' => 5])));
    }

    public function test_toda_sincronizacao_recalcula_o_status_e_atualiza_as_quantidades(): void
    {
        $this->sincronizar('ordens_producao');
        // Status gravado pela regra antiga (sem mudança nenhuma no Protheus): a sincronização corrige.
        OrdemProducao::where('c2_num', '509859')->update(['status' => 'fechada']);
        // Quantidades mudaram no Protheus.
        DB::connection('protheus')->table('SC2')->where('C2_NUM', '509860')->update(['C2_QUANT' => 650, 'C2_QUJE' => 300, 'C2_PERDA' => 7]);

        $r = $this->sincronizar('ordens_producao');

        $this->assertSame(2, $r->atualizados); // 1 status corrigido + 1 OP com quantidades novas
        $this->assertSame('aberta', OrdemProducao::where('c2_num', '509859')->value('status'));
        $op = OrdemProducao::where('c2_num', '509860')->sole();
        $this->assertSame([650.0, 300.0, 7.0, 'andamento'], [$op->c2_quant, $op->c2_quje, $op->c2_perda, $op->status]);

        $this->assertSame(0, $this->sincronizar('ordens_producao')->atualizados); // nada mais a mudar
    }

    public function test_status_em_lote_considera_os_apontamentos(): void
    {
        $this->sincronizar('ordens_producao');
        $op = OrdemProducao::where('c2_num', '509859')->sole();
        ApontamentoEstado::create(['maquina_id' => $this->maquina->id, 'legenda_id' => Legenda::where('codigo', 'DT')->sole()->id,
            'ordem_producao_id' => $op->id, 'inicio' => now()->subHour()]);
        $op->update(['status' => 'aberta']); // como se o recálculo não tivesse rodado

        $this->sincronizar('ordens_producao');
        $this->assertSame('parada', $op->fresh()->status);
    }
}
