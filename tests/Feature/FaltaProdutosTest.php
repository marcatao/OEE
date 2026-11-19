<?php

namespace Tests\Feature;

use App\Models\ApontamentoEstado;
use App\Models\FaltaProduto;
use App\Models\FiltroUsuario;
use App\Models\Legenda;
use App\Models\Linha;
use App\Models\Maquina;
use App\Models\OrdemProducao;
use App\Models\Planta;
use App\Models\Usuario;
use App\Services\FaltaProdutos\FaltaProdutos;
use App\Services\FaltaProdutos\FiltroFaltaProdutos;
use App\Services\Protheus\ImportadorFaltasProduto;
use App\Services\Protheus\SincronizadorProtheus;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\IotDeTeste;
use Tests\TestCase;

/**
 * Produção › Falta de produtos: sincronização da ZB5 (pelo PHP no SQLite; no SQL Server, pela procedure), situação da
 * produção de cada produto (em produção, direcionada, com OP, sem OP), filtros, painéis e filtros salvos.
 */
class FaltaProdutosTest extends TestCase
{
    use IotDeTeste;
    use RefreshDatabase;

    private array $ops = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-19 15:00', 'UTC')); // 12:00 em São Paulo
        config(['oee.fuso_exibicao' => 'America/Sao_Paulo']);
        config(['database.connections.protheus' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('protheus');
        Schema::connection('protheus')->create('ZB5', function (Blueprint $t) {
            foreach (ImportadorFaltasProduto::CAMPOS as $c) {
                in_array($c, ['ZB5_QTDSOL', 'ZB5_QTDATE', 'ZB5_VLPED', 'R_E_C_N_O_'], true) ? $t->float($c)->default(0) : $t->string($c)->default('');
            }
        });
        // [recno, doc, produto, status, qtd sol, qtd atendida, data sol, hora sol, data atend, hora atend, excluída]
        foreach ([
            [1, '0000000001', 'PA001', 'P', 100, 0, '20261118', '08:00:00', '', '', ' '],
            [2, '0000000002', 'PA002', 'A', 50, 20, '20261110', '10:00:00', '20261112', '10:00:00', ' '], // 48 h
            [3, '0000000003', 'PA003', 'P', 30, 0, '20261001', '07:30', '', '', ' '],
            [4, '0000000004', 'PA004', 'P', 10, 0, '20261119', '11:00:00', '', '', ' '],
            [5, '0000000005', 'PA005', 'C', 5, 5, '20261020', '08:00:00', '20261021', '10:00:00', ' '],     // 26 h
            [6, '0000000006', 'PA001', 'D', 7, 0, '20261119', '09:00:00', '', '', ' '],
            [7, '0000000007', 'PA009', 'P', 1, 0, '20261119', '09:00:00', '', '', '*'],                      // excluída, nunca veio
        ] as [$r, $doc, $prod, $st, $sol, $ate, $dts, $hs, $dta, $ha, $del]) {
            DB::connection('protheus')->table('ZB5')->insert(['ZB5_FILIAL' => '01', 'ZB5_DOC' => $doc, 'ZB5_PRODUT' => $prod.'           ',
                'ZB5_STATUS' => $st, 'ZB5_QTDSOL' => $sol, 'ZB5_QTDATE' => $ate, 'ZB5_DTSOLI' => $dts, 'ZB5_HORINI' => $hs,
                'ZB5_DTATEN' => $dta, 'ZB5_HORFIM' => $ha, 'ZB5_SOLICI' => 'VENDAS', 'ZB5_PEDIDO' => '1234'.$r, 'ZB5_VLPED' => 1000 * $r,
                'D_E_L_E_T_' => $del, 'R_E_C_N_O_' => $r]);
        }
        $this->assertSame('sucesso', app(SincronizadorProtheus::class)->sincronizar('faltas_produto', 'comando')->situacao);

        // Produção: PA001 com OP sendo produzida na INJ-01; PA002 com OP na INJ-02 (sem produzir); PA003 com OP sem
        // máquina; PA004 só com OP fechada (= sem OP).
        foreach ([['PA001', 'andamento', '100001'], ['PA002', 'aberta', '100002'], ['PA003', 'aberta', '100003'], ['PA004', 'fechada', '100004']] as [$prod, $status, $num]) {
            $this->ops[$prod] = OrdemProducao::create(['c2_num' => $num, 'c2_item' => '01', 'c2_sequen' => '001', 'numero' => $num.'01001',
                'c2_produto' => $prod, 'status' => $status, 'c2_quant' => 500, 'c2_quje' => 100, 'c2_datrf' => $status === 'fechada' ? '20261101' : '']);
        }
        $linha = Linha::create(['planta_id' => Planta::create(['codigo' => 'MATRIZ', 'nome' => 'Matriz'])->id, 'codigo' => 'L1', 'nome' => 'Linha 1']);
        $inj1 = Maquina::create(['linha_id' => $linha->id, 'codigo' => 'INJ-01', 'nome' => 'Injetora 01'] + $this->iotGravado());
        Maquina::create(['linha_id' => $linha->id, 'codigo' => 'INJ-02', 'nome' => 'Injetora 02', 'ordem_producao_id' => $this->ops['PA002']->id] + $this->iotGravado());
        ApontamentoEstado::create(['maquina_id' => $inj1->id, 'legenda_id' => Legenda::where('tipo', 'produzindo')->firstOrFail()->id,
            'ordem_producao_id' => $this->ops['PA001']->id, 'inicio' => now()->subHour()]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): Usuario
    {
        return Usuario::create(['entra_oid' => 'adm', 'nome' => 'Admin', 'email' => 'adm@empresa.com', 'perfil' => 'admin']);
    }

    private function montar(array $parametros = []): array
    {
        return app(FaltaProdutos::class)->montar(FiltroFaltaProdutos::daRequisicao(Request::create('/', 'GET', $parametros)), 1, '/');
    }

    public function test_sincroniza_a_zb5_com_datas_em_utc(): void
    {
        $this->assertSame(6, FaltaProduto::count()); // a excluída que nunca veio não entra
        $f = FaltaProduto::where('r_e_c_n_o_', 1)->sole();
        $this->assertSame(['PA001', 'P', '2026-11-18 11:00:00'], [$f->zb5_produt, $f->zb5_status, $f->solicitado_em->format('Y-m-d H:i:s')]);
        $this->assertNull($f->atendido_em);
        $this->assertSame('2026-10-01 10:30:00', FaltaProduto::where('r_e_c_n_o_', 3)->sole()->solicitado_em->format('Y-m-d H:i:s')); // "07:30" ganha ":00"
        $this->assertSame(1560.0, FaltaProduto::where('r_e_c_n_o_', 5)->sole()->minutosAtendimento());       // 26 h

        // Atendida no Protheus: a próxima sincronização atualiza; nada novo.
        DB::connection('protheus')->table('ZB5')->where('R_E_C_N_O_', 1)
            ->update(['ZB5_STATUS' => 'C', 'ZB5_QTDATE' => 100, 'ZB5_DTATEN' => '20261119', 'ZB5_HORFIM' => '10:00:00']);
        $r = app(SincronizadorProtheus::class)->sincronizar('faltas_produto', 'comando');
        $this->assertSame([0, 1], [$r->novos, $r->atualizados]);
        $this->assertSame('C', FaltaProduto::where('r_e_c_n_o_', 1)->value('zb5_status'));
    }

    public function test_situacao_da_producao_de_cada_produto_em_falta(): void
    {
        $dados = $this->montar();
        // Padrão: só pendentes e atendidas parcialmente.
        $faltas = collect($dados['faltas']->items());
        $this->assertSame(['PA004', 'PA001', 'PA002', 'PA003'], $faltas->map->produto()->all()); // mais recentes primeiro
        $this->assertSame(['PA004' => 'sem_op', 'PA001' => 'em_producao', 'PA002' => 'direcionada', 'PA003' => 'com_op'],
            $faltas->mapWithKeys(fn ($f) => [$f->produto() => $f->situacao_producao])->all());
        $this->assertSame('INJ-01', $dados['producao']['PA001']['produzindo'][0]['maquina']->codigo);
        $this->assertSame('INJ-02', $dados['producao']['PA002']['direcionadas'][0]['maquina']->codigo);

        // Painéis do filtro: tempo médio só das atendidas do filtro (a parcial: 48 h); saldo e idades das em aberto.
        $p = $dados['painel'];
        $this->assertSame([4, 4, 2880.0, 1], [$p['faltas'], $p['em_aberto'], $p['atendimento_medio'], $p['atendidas']]);
        $this->assertSame(100 + 30 + 30 + 10, (int) $p['saldo']);
        $this->assertSame(['em_producao' => 1, 'direcionada' => 1, 'com_op' => 1, 'sem_op' => 1], $p['por_situacao']);
        $this->assertSame(['Até 1 dia' => 1, '1 a 3 dias' => 1, '3 a 7 dias' => 0, 'Mais de 7 dias' => 2], $p['idades']);

        // Todas as legendas: entram a concluída (26 h) e a cancelada; tempo médio (48 + 26) / 2 = 37 h.
        $todas = $this->montar(['f' => 1]);
        $this->assertSame(6, $todas['faltas']->total());
        $this->assertSame(2220.0, $todas['painel']['atendimento_medio']);
        $this->assertSame('encerrada', collect($todas['faltas']->items())->firstWhere('zb5_status', 'C')->situacao_producao);

        // Filtro pela produção e pelo período.
        $this->assertSame(['PA004'], collect($this->montar(['f' => 1, 'status' => ['P', 'A'], 'producao' => ['sem_op']])['faltas']->items())->map->produto()->all());
        $this->assertSame(['PA004', 'PA001'], collect($this->montar(['f' => 1, 'status' => ['P'], 'de' => '2026-11-15'])['faltas']->items())->map->produto()->all());

        // Mês a mês (sem período: últimos 12 meses): faltantes sem as canceladas; atendidas = concluídas no mês.
        $meses = collect($todas['meses'])->keyBy('chave');
        $this->assertCount(12, $meses);
        $this->assertSame([3, 0], [$meses['2026-11']['faltantes'], $meses['2026-11']['atendidas']]);
        $this->assertSame([2, 1], [$meses['2026-10']['faltantes'], $meses['2026-10']['atendidas']]);
    }

    public function test_tela_permissao_e_filtro_salvo(): void
    {
        $operador = Usuario::create(['entra_oid' => 'op', 'nome' => 'Operador', 'email' => 'op@empresa.com', 'perfil' => 'operador']);
        $this->actingAs($operador)->get('/producao/falta-de-produtos')->assertForbidden();

        $admin = $this->admin();
        $this->actingAs($admin)->get('/')->assertSee('Falta de produtos');
        $this->get('/producao/falta-de-produtos')->assertOk()
            ->assertSee('Solicitação pendente')->assertSee('Atendida parcialmente')->assertSee('Solicitação concluída')->assertSee('Solicitação cancelada')
            ->assertSee('0000000001')->assertSee('0000000004')->assertDontSee('0000000005')->assertDontSee('0000000006')
            ->assertSee('Produzindo na')->assertSee('Tempo médio de atendimento')->assertSee('Faltantes × atendidas mês a mês')
            ->assertSee('Produtos que mais faltam');
        // Só o resultado (troca sem recarregar a página).
        $this->get('/producao/falta-de-produtos?f=1&producao[]=sem_op&parcial=1')->assertOk()
            ->assertSee('0000000004')->assertDontSee('0000000001')->assertDontSee('<html', false);

        // Filtro salvo como padrão: abre com ele.
        $this->post('/producao/falta-de-produtos/filtros', ['nome' => 'Só concluídas', 'padrao' => '1', 'f' => '1', 'status' => ['C']])
            ->assertRedirect();
        $this->assertSame(['C'], FiltroUsuario::sole()->filtros['status']);
        $this->get('/producao/falta-de-produtos')->assertOk()->assertSee('0000000005')->assertDontSee('0000000001');
        $this->delete('/producao/falta-de-produtos/filtros/'.FiltroUsuario::sole()->id)->assertRedirect();
        $this->assertSame(0, FiltroUsuario::count());
    }

    public function test_falta_de_produtos_vai_pela_procedure_no_sql_server(): void
    {
        $this->assertSame('procedure', config('protheus.sincronizacoes.faltas_produto.via'));
        $this->assertSame('php', app(SincronizadorProtheus::class)->via('faltas_produto')); // SQLite
        $sql = file_get_contents(database_path('procedures/sp_sincronizar_faltas_produto.sql'));
        $this->assertMatchesRegularExpression('/ALTER PROCEDURE dbo\.sp_sincronizar_faltas_produto\s+AS\s+BEGIN/', $sql);
        $this->assertStringContainsString('FROM Compras.dbo.ZB5', $sql);
        $this->assertStringContainsString('MERGE dbo.faltas_produto', $sql);
        $this->assertStringContainsString('ON t.r_e_c_n_o_ = s.r_e_c_n_o_', $sql);
    }
}
