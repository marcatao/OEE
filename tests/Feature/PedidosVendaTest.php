<?php

namespace Tests\Feature;

use App\Models\Faturamento\PedidoVendaEtapa;
use App\Models\Faturamento\PedidoVendaLiberacao;
use App\Models\Faturamento\RegraEtapaPedido;
use App\Models\FiltroUsuario;
use App\Models\Grupo;
use App\Models\PedidoVenda;
use App\Models\Sincronizacao;
use App\Models\Usuario;
use App\Services\Assistente\FerramentasAssistente;
use App\Services\Faturamento\EtapasPedidoVenda;
use App\Services\Protheus\SincronizadorProtheus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Faturamento › Pedidos de venda: etapas calculadas sobre as cópias do portal (pedidos_venda = SC5 + SC6, um item por
 * linha, e pedidos_venda_liberacoes = SC9, gravadas pela procedure sp_sincronizar_pedidos_venda; aqui direto nas
 * tabelas), dashboard por mês, filtros salvos, regras do WMS, assistente e a garantia de que o portal não lê o Protheus.
 */
class PedidosVendaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-26 15:00', 'UTC'));
        config(['oee.fuso_exibicao' => 'America/Sao_Paulo']);
        // O portal nunca lê o Protheus: uma conexão quebrada não pode atrapalhar nada desta tela.
        config(['database.connections.protheus' => ['driver' => 'sqlsrv', 'host' => 'nao-existe.invalid', 'database' => 'x']]);

        // [número, emissão, [[item, qtd, entregue, entrega, datfat, blq]], [[item, qtd, extras]]]
        foreach ([
            ['000001', '20261101', [['01', 10, 0, '20261128', '', '']], [['01', 10, ['C9_STSERV' => '1']]]],   // separação (regra)
            ['000002', '20261001', [['01', 5, 0, '20261015', '', '']], []],                                  // carteira, atrasado
            ['000003', '20261102', [['01', 4, 4, '20261110', '20261110', '']], []],                            // faturado em novembro
            ['000004', '20261001', [['01', 2, 2, '20261020', '20261020', '']], []],                            // faturado em outubro
            ['000005', '20261120', [['01', 3, 0, '20270115', '', '']], [['01', 3, ['C9_STSERV' => '']]]],      // expedição, entrega em 2027
            ['000006', '20261120', [['01', 1, 0, '20261130', '', 'R']], []],                                   // resíduo eliminado sem entrega
        ] as $i => [$num, $emissao, $itens, $libs]) {
            foreach ($itens as $k => [$item, $qtd, $ent, $entrega, $datfat, $blq]) {
                PedidoVenda::create(['c5_filial' => '01', 'c5_num' => $num, 'c5_tipo' => 'N', 'c5_cliente' => 'C0000'.$i, 'c5_lojacli' => '01',
                    'cliente_nome' => 'Cliente '.$num, 'c5_emissao' => $emissao, 'c6_item' => $item, 'c6_produto' => 'PA00'.$i,
                    'c6_descri' => 'Produto '.$i, 'c6_qtdven' => $qtd, 'c6_qtdent' => $ent, 'c6_prcven' => 100, 'c6_valor' => $qtd * 100,
                    'c6_entreg' => $entrega, 'c6_datfat' => $datfat, 'c6_blq' => $blq, 'c5_recno' => 100 + $i, 'r_e_c_n_o_' => 1000 + $i * 10 + $k]);
            }
            foreach ($libs as $k => [$item, $qtd, $extras]) {
                PedidoVendaLiberacao::create(['c9_filial' => '01', 'c9_pedido' => $num, 'c9_item' => $item, 'c9_sequen' => '01',
                    'c9_qtdlib' => $qtd, 'c9_datalib' => '20261121', 'extras' => json_encode($extras), 'c5_emissao' => $emissao,
                    'r_e_c_n_o_' => 5000 + $i * 10 + $k]);
            }
        }
        RegraEtapaPedido::create(['etapa' => 'separacao', 'campo' => 'C9_STSERV', 'operador' => 'igual', 'valores' => '1,2']);
        // Primeiro cálculo: pedidos sem linha em pedidos_venda_etapas (inclusive os já faturados).
        app(EtapasPedidoVenda::class)->recalcularAbertos();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function usuario(array $permissoes, string $email = 'vendas@empresa.com'): Usuario
    {
        $u = Usuario::create(['entra_oid' => "oid-{$email}", 'nome' => 'Fulano', 'email' => $email]);
        $u->definirPerfis(['operador']);
        $grupo = Grupo::create(['nome' => 'Grupo '.$email, 'ativo' => true]);
        $grupo->definirPermissoes($permissoes);
        $grupo->usuarios()->attach($u->id);

        return $u->fresh();
    }

    private function etapa(string $num): ?string
    {
        return PedidoVendaEtapa::where('c5_num', $num)->value('etapa');
    }

    public function test_etapas_valores_e_datas_calculados_so_com_as_tabelas_do_portal(): void
    {
        $this->assertSame('separacao', $this->etapa('000001'));
        $this->assertSame('carteira', $this->etapa('000002'));
        $this->assertSame('faturado', $this->etapa('000003'));
        $this->assertSame('faturado', $this->etapa('000004'));
        $this->assertSame('expedicao', $this->etapa('000005'));
        $this->assertNull($this->etapa('000006')); // resíduo eliminado sem entrega: encerrado, fora da tela

        $p3 = PedidoVendaEtapa::where('c5_num', '000003')->first();
        $this->assertSame('20261110', $p3->faturado_em);
        $this->assertSame(400.0, $p3->valor_faturado);
        // Em expedição: desde a liberação (21/11, 00:00 em São Paulo = 03:00 UTC); carteira (1º cálculo): desde a emissão.
        $this->assertSame('2026-11-21 03:00:00', PedidoVendaEtapa::where('c5_num', '000005')->first()->etapa_desde->format('Y-m-d H:i:s'));
        $this->assertSame(5, PedidoVendaEtapa::where('c5_num', '000005')->first()->diasNaEtapa());
        $this->assertSame(56, PedidoVendaEtapa::where('c5_num', '000002')->first()->diasNaEtapa());
    }

    public function test_dashboard_por_etapa_no_mes(): void
    {
        $this->actingAs($this->usuario(['faturamento_pedidos']));
        $r = $this->get('/faturamento/pedidos-de-venda')->assertOk()
            ->assertSee('Pedidos de venda')->assertSee('novembro de 2026')
            ->assertSee('000001')->assertSee('000002')->assertSee('000003')
            ->assertDontSee('000004')  // faturado em outubro
            ->assertDontSee('000005')  // entrega em 2027
            ->assertDontSee('000006'); // encerrado
        $cartoes = $r->viewData('cartoes');
        $this->assertSame(1, $cartoes['separacao']['quantidade']);
        $this->assertSame(1000.0, $cartoes['separacao']['valor']);
        $this->assertSame(1, $cartoes['carteira']['quantidade']);
        $this->assertSame(1, $cartoes['faturado']['quantidade']);
        $this->assertSame(400.0, $cartoes['faturado']['valor']);
        $this->assertSame(0, $cartoes['expedicao']['quantidade']);

        // Outubro: só o faturado de outubro e o em aberto com entrega até outubro (atrasado).
        $this->get('/faturamento/pedidos-de-venda?f=1&mes=2026-10')->assertOk()->assertSee('outubro de 2026')
            ->assertSee('000004')->assertSee('000002')->assertDontSee('000003')->assertDontSee('000001');
        // Todos os em aberto (inclusive entrega futura); cartão filtra a lista (?parcial=1 devolve só o resultado).
        $this->get('/faturamento/pedidos-de-venda?f=1&abertos=todos&etapas[]=expedicao&parcial=1')->assertOk()
            ->assertSee('000005')->assertDontSee('000001')->assertDontSee('<html', false);
        // Busca por cliente e por produto.
        $this->get('/faturamento/pedidos-de-venda?f=1&busca=Cliente%20000002')->assertSee('000002')->assertDontSee('000001');
        $this->get('/faturamento/pedidos-de-venda?f=1&busca=PA000')->assertSee('000001')->assertDontSee('000002');
        $this->get('/faturamento/pedidos-de-venda?f=1&busca=Produto%201')->assertSee('000002')->assertDontSee('000001');
    }

    public function test_filtro_salvo_nao_guarda_o_mes(): void
    {
        $u = $this->usuario(['faturamento_pedidos']);
        $this->actingAs($u)->post('/faturamento/pedidos-de-venda/filtros', ['nome' => 'Separação', 'padrao' => 1, 'f' => 1,
            'mes' => '2026-10', 'etapas' => ['separacao']])->assertRedirect();
        $salvo = FiltroUsuario::where('tela', 'faturamento_pedidos')->firstOrFail();
        $this->assertSame(['etapas' => ['separacao'], 'abertos' => 'mes_atrasados', 'busca' => ''], $salvo->filtros);

        // Abre com o padrão, no mês atual.
        $this->get('/faturamento/pedidos-de-venda')->assertSee('novembro de 2026')->assertSee('000001')->assertDontSee('000002');
        $this->delete("/faturamento/pedidos-de-venda/filtros/{$salvo->id}")->assertRedirect();
        $this->assertSame(0, FiltroUsuario::count());
    }

    public function test_regras_do_wms_recalculam_os_pedidos(): void
    {
        $this->actingAs($this->usuario(['faturamento_etapas']));
        $this->get('/faturamento/etapas')->assertOk()->assertSee('C9_STSERV')->assertSee('Valores encontrados');

        // Campo de outra tabela e valores vazios são recusados.
        $this->post('/faturamento/etapas', ['etapa' => 'logistica', 'campo' => 'C5_X', 'operador' => 'igual', 'valores' => '1'])
            ->assertSessionHasErrors('campo');
        $this->post('/faturamento/etapas', ['etapa' => 'logistica', 'campo' => 'C9_CARGA', 'operador' => 'igual', 'valores' => ' , '])
            ->assertSessionHasErrors('valores');

        // "Vazio" no C9_STSERV = aguardando faturamento: o 000005 muda na hora.
        $this->post('/faturamento/etapas', ['etapa' => 'aguardando_faturamento', 'campo' => 'c9_stserv',
            'operador' => 'vazio'])->assertRedirect('/faturamento/etapas')->assertSessionHas('ok');
        $this->assertSame('aguardando_faturamento', $this->etapa('000005'));
        $this->assertSame(now()->format('Y-m-d H:i'), PedidoVendaEtapa::where('c5_num', '000005')->first()->etapa_desde->format('Y-m-d H:i'));

        // Desativar volta para expedição (regras são desativadas, não excluídas).
        $regra = RegraEtapaPedido::where('etapa', 'aguardando_faturamento')->firstOrFail();
        $this->patch("/faturamento/etapas/{$regra->id}")->assertRedirect();
        $this->assertFalse($regra->fresh()->ativo);
        $this->assertSame('expedicao', $this->etapa('000005'));
        $this->assertSame(2, RegraEtapaPedido::count());
    }

    public function test_sem_permissao_nao_ve(): void
    {
        $this->actingAs($this->usuario(['timeline']));
        $this->get('/faturamento/pedidos-de-venda')->assertForbidden();
        $this->get('/faturamento/etapas')->assertForbidden();
        $this->get('/')->assertDontSee('Pedidos de venda');
    }

    public function test_depois_da_procedure_recalcula_os_mexidos_sem_ler_o_protheus(): void
    {
        // A procedure entregou o resto do 000001 (item com nota) e mexeu nele: vira faturado.
        $registro = Sincronizacao::create(['chave' => 'pedidos_venda', 'origem' => 'x', 'disparo' => 'comando', 'situacao' => 'executando',
            'iniciado_em' => now()->subMinute()]);
        PedidoVenda::where('c5_num', '000001')->update(['c6_qtdent' => 10, 'c6_datfat' => '20261126', 'situacao' => 'faturado', 'sincronizado_em' => now()]);
        PedidoVendaLiberacao::where('c9_pedido', '000001')->update(['c9_nfiscal' => '000123', 'sincronizado_em' => now()]);
        $mensagem = app(EtapasPedidoVenda::class)->depoisDaSincronizacao($registro);
        $this->assertSame('faturado', $this->etapa('000001'));
        $this->assertStringContainsString('Etapas recalculadas', $mensagem);

        $registro->update(['situacao' => 'sucesso', 'terminado_em' => now()]);
        // A sincronização pelo portal só chama a procedure: fora do SQL Server não lê nada (nem tenta o Protheus).
        $s = app(SincronizadorProtheus::class)->sincronizar('pedidos_venda', 'comando');
        $this->assertSame('sucesso', $s->situacao);
        $this->assertSame(0, $s->lidos);
        $this->assertSame(EtapasPedidoVenda::class, config('protheus.sincronizacoes.pedidos_venda.depois'));
    }

    public function test_nenhum_codigo_do_faturamento_acessa_o_protheus(): void
    {
        $arquivos = array_merge(glob(app_path('Services/Faturamento/*.php')), glob(app_path('Models/Faturamento/*.php')),
            glob(app_path('Http/Controllers/Faturamento/*.php')));
        $this->assertNotEmpty($arquivos);
        foreach ($arquivos as $arquivo) {
            $this->assertDoesNotMatchRegularExpression("/connection\\(\\s*['\"]protheus/", file_get_contents($arquivo), $arquivo);
        }
        $this->assertEmpty(config('protheus.sincronizacoes.pedidos_venda.importador'));
    }

    public function test_assistente_consulta_os_pedidos(): void
    {
        $u = $this->usuario(['faturamento_pedidos', 'assistente']);
        $this->actingAs($u);
        $ferramentas = app(FerramentasAssistente::class);
        $this->assertContains('pedidos_venda', $ferramentas->disponiveis($u));
        $r = $ferramentas->executar($u, 'pedidos_venda', ['etapas' => ['carteira']]);
        $this->assertSame('novembro de 2026', $r['mes']);
        $this->assertSame(['000002'], array_column($r['pedidos'], 'pedido'));
        $this->assertSame('Em carteira', $r['pedidos'][0]['etapa']);
        $this->assertSame(1, collect($r['etapas'])->firstWhere('etapa', 'Faturados')['pedidos']);

        $outro = $this->usuario(['assistente'], 'outro@empresa.com');
        $this->actingAs($outro);
        $this->assertNotContains('pedidos_venda', $ferramentas->disponiveis($outro));
    }
}
