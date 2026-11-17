<?php

namespace Tests\Feature;

use App\Models\CentroCusto;
use App\Models\Compras\PedidoCompra;
use App\Models\Manutencao\AreaExecutante;
use App\Models\Manutencao\MaterialOs;
use App\Models\Manutencao\OrdemServico;
use App\Models\Manutencao\SolicitacaoMaterial;
use App\Models\ProdutoProtheus;
use App\Models\Usuario;
use App\Support\AcessoOs;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\TabelaMuroDeCompras;
use Tests\TestCase;

/** Pedido de compra pela tabela muro do Protheus: gerado da solicitação de material ou da OS, status lido de volta. */
class PedidoCompraTest extends TestCase
{
    use RefreshDatabase;
    use TabelaMuroDeCompras;

    protected function setUp(): void
    {
        parent::setUp();
        $this->criarTabelasMuro();
        AcessoOs::limparCache();
        CentroCusto::create(['ctt_custo' => '1001', 'ctt_desc01' => 'INJECAO']);
        CentroCusto::create(['ctt_custo' => '3003', 'ctt_desc01' => 'MANUTENCAO']);
        ProdutoProtheus::create(['b1_cod' => 'MP0001', 'b1_desc' => 'ROLAMENTO 6204 ZZ', 'b1_um' => 'PC']);
        ProdutoProtheus::create(['b1_cod' => 'MP0002', 'b1_desc' => 'GRAXA LITIO 1KG', 'b1_um' => 'KG']);
    }

    private function usuario(array $perfis, string $email): Usuario
    {
        $u = Usuario::create(['entra_oid' => "oid-{$email}", 'nome' => ucfirst(strtok($email, '@')), 'email' => $email]);
        $u->definirPerfis($perfis);

        return $u->fresh();
    }

    private function muro(): Builder
    {
        return DB::connection(config('compras.conexao'))->table(config('compras.tabela_pedidos'));
    }

    private function muroItens(): Builder
    {
        return DB::connection(config('compras.conexao'))->table(config('compras.tabela_itens'));
    }

    /** Solicitação com MP0001 aprovado (3) e MP0002 pendente. */
    private function solicitacaoAprovada(Usuario $adm): SolicitacaoMaterial
    {
        $sm = SolicitacaoMaterial::create(['unidade' => 'manutencao', 'solicitante_id' => $adm->id, 'status' => 'em_analise']);
        $sm->itens()->create(['produto_codigo' => 'MP0001', 'produto_descricao' => 'ROLAMENTO 6204 ZZ', 'um' => 'PC', 'centro_custo' => '1001',
            'quantidade_solicitada' => 4, 'quantidade_aprovada' => 3, 'prioridade' => 'MP', 'status' => 'aprovado', 'aprovado_por_id' => $adm->id, 'aprovado_em' => now()]);
        $sm->itens()->create(['produto_codigo' => 'MP0002', 'produto_descricao' => 'GRAXA', 'um' => 'KG', 'centro_custo' => '1001',
            'quantidade_solicitada' => 2, 'prioridade' => 'NO', 'status' => 'pendente']);

        return $sm;
    }

    public function test_administrador_gera_pedido_da_solicitacao_e_o_protheus_devolve_o_andamento(): void
    {
        $adm = $this->usuario(['admin_producao'], 'adm@empresa.com');
        $sm = $this->solicitacaoAprovada($adm);
        [$aprovado, $pendente] = [$sm->itens()->where('produto_codigo', 'MP0001')->first(), $sm->itens()->where('produto_codigo', 'MP0002')->first()];
        $url = "/ordens-de-servico/pedidos-de-compra/da-solicitacao/{$sm->id}";

        $this->actingAs($adm)->get("/ordens-de-servico/solicitacoes-de-material/{$sm->id}")->assertOk()
            ->assertSee('Gerar pedido ou solicitação de compra')->assertSee('name="pedido_itens[]" value="'.$aprovado->id.'"', false);

        // Item não aprovado não entra; centros de custo são obrigatórios.
        $this->post($url, ['pedido_itens' => [$pendente->id], 'centro_custo_origem' => '1001', 'centro_custo_destino' => '3003'])->assertSessionHasErrors('pedido_itens');
        $this->post($url, ['pedido_itens' => [$aprovado->id]])->assertSessionHasErrors(['centro_custo_origem', 'centro_custo_destino']);
        $this->assertSame(0, $this->muro()->count());

        $this->post($url, ['pedido_itens' => [$aprovado->id], 'centro_custo_origem' => '1001', 'centro_custo_destino' => '3003', 'observacao_pedido' => 'Urgente'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $pedido = PedidoCompra::sole();
        $this->assertSame('aguardando_protheus', $pedido->status);
        $muro = $this->muro()->sole();
        $this->assertSame('AGUARDANDO_PROTHEUS', $muro->ZP1_STATUS);
        $this->assertSame(str_pad((string) $pedido->id, 10, '0', STR_PAD_LEFT), $muro->ZP1_IDPORT);
        $this->assertSame(['1001', '3003', 'Urgente', $sm->numeroFormatado()], [$muro->ZP1_CCORIG, $muro->ZP1_CCDEST, $muro->ZP1_OBS, $muro->ZP1_ORIGEM]);
        $this->assertSame([' ', 1], [$muro->D_E_L_E_T_, (int) $muro->R_E_C_N_O_]);
        $this->assertMatchesRegularExpression('/^\d{8}$/', $muro->ZP1_DTCRIA);
        $linha = $this->muroItens()->sole();
        $this->assertSame(['0001', 'MP0001', $muro->ZP1_IDPORT], [$linha->ZP2_ITEM, $linha->ZP2_PROD, $linha->ZP2_IDPORT]);
        $this->assertEquals(3, (float) $linha->ZP2_QUANT); // a quantidade aprovada

        // O mesmo item não entra em outro pedido.
        $this->post($url, ['pedido_itens' => [$aprovado->id], 'centro_custo_origem' => '1001', 'centro_custo_destino' => '3003'])->assertSessionHasErrors('pedido_itens');

        // O Protheus integra: número e aguardando aprovação.
        $this->muro()->update(['ZP1_STATUS' => 'AGUARDANDO_APROVACAO', 'ZP1_NUMPED' => '012345', 'ZP1_DTINT' => '20261117', 'ZP1_HRINT' => '10:00:00', 'ZP1_VALOR' => 75.5]);
        $this->muroItens()->update(['ZP2_C7ITEM' => '0001', 'ZP2_VUNIT' => 25.1667, 'ZP2_VTOTAL' => 75.5, 'ZP2_QTDENT' => 0]);
        $this->get("/ordens-de-servico/solicitacoes-de-material/{$sm->id}")->assertOk()
            ->assertSee('Pedido Protheus 012345')->assertSee('Integrado — aguardando aprovação')->assertSee('R$ 75,50');
        $pedido->refresh();
        $this->assertSame('012345', $pedido->numero_pedido);
        $this->assertSame('2026-11-17 13:00:00', $pedido->integrado_em->format('Y-m-d H:i:s')); // horário local → UTC
        $this->assertFalse($pedido->podeCancelar());
        $this->post("/ordens-de-servico/pedidos-de-compra/{$pedido->id}/cancelar")->assertSessionHasErrors('pedido');

        // Aprovado e concluído (pelo comando agendado).
        $this->muro()->update(['ZP1_STATUS' => 'CONCLUIDO', 'ZP1_APROV' => 'Fulano Compras', 'ZP1_DTAPRO' => '20261118', 'ZP1_HRAPRO' => '08:00',
            'ZP1_DTCONC' => '20261120', 'ZP1_HRCONC' => '15:30:00', 'ZP1_VALOR' => 80]);
        $this->muroItens()->update(['ZP2_VTOTAL' => 80, 'ZP2_QTDENT' => 3]);
        $this->artisan('compras:atualizar-pedidos')->assertSuccessful();
        $pedido->refresh();
        $this->assertSame(['concluido', 'Fulano Compras'], [$pedido->status, $pedido->aprovador]);
        $this->assertEquals(80, (float) $pedido->valor_total);
        $this->assertEquals(3, (float) $pedido->itens()->value('quantidade_entregue'));
        $this->assertSame('2026-11-18 11:00:00', $pedido->aprovado_em->format('Y-m-d H:i:s'));
    }

    public function test_so_o_administrador_da_unidade_gera_e_cancelar_ou_reenviar_seguem_o_protheus(): void
    {
        $adm = $this->usuario(['admin_producao'], 'adm@empresa.com');
        $sm = $this->solicitacaoAprovada($adm);
        $item = $sm->itens()->where('status', 'aprovado')->first();
        $dados = ['pedido_itens' => [$item->id], 'centro_custo_origem' => '1001', 'centro_custo_destino' => '3003'];

        $this->actingAs($this->usuario(['admin_ferramentaria'], 'ferr@empresa.com'))->post("/ordens-de-servico/pedidos-de-compra/da-solicitacao/{$sm->id}", $dados)->assertForbidden();
        $this->actingAs($this->usuario(['mantenedor_producao'], 'mec@empresa.com'))->post("/ordens-de-servico/pedidos-de-compra/da-solicitacao/{$sm->id}", $dados)->assertForbidden();

        $this->actingAs($adm)->post("/ordens-de-servico/pedidos-de-compra/da-solicitacao/{$sm->id}", $dados)->assertSessionHasNoErrors();
        $pedido = PedidoCompra::sole();

        // Erro no Protheus: reenviar volta para a fila; cancelar (antes de o Protheus pegar) libera o item.
        $this->muro()->update(['ZP1_STATUS' => 'ERRO', 'ZP1_MSG' => 'Produto sem preço']);
        $this->get("/ordens-de-servico/solicitacoes-de-material/{$sm->id}")->assertSee('Produto sem preço')->assertSee('Reenviar ao Protheus');
        $this->post("/ordens-de-servico/pedidos-de-compra/{$pedido->id}/reenviar")->assertSessionHasNoErrors();
        $this->assertSame('AGUARDANDO_PROTHEUS', $this->muro()->value('ZP1_STATUS'));
        $this->post("/ordens-de-servico/pedidos-de-compra/{$pedido->id}/cancelar")->assertSessionHasNoErrors();
        $this->assertSame('CANCELADO', $this->muro()->value('ZP1_STATUS'));
        $this->assertSame('cancelado', $pedido->fresh()->status);
        $this->post("/ordens-de-servico/pedidos-de-compra/da-solicitacao/{$sm->id}", $dados)->assertSessionHasNoErrors();
        $this->assertSame(2, PedidoCompra::count());
        $this->assertSame([1, 2], $this->muro()->orderBy('R_E_C_N_O_')->pluck('R_E_C_N_O_')->map(fn ($r) => (int) $r)->all());
    }

    public function test_pedido_da_os_aberta_e_custo_da_os(): void
    {
        $adm = $this->usuario(['admin_producao'], 'adm@empresa.com');
        $area = AreaExecutante::create(['codigo' => 'MEC', 'nome' => 'Mecânica', 'unidade' => 'manutencao', 'ativo' => true]);
        $os = OrdemServico::create(['unidade' => 'manutencao', 'tipo' => 'corretiva', 'area_executante_id' => $area->id, 'centro_custo' => '1001',
            'prioridade' => 'alta', 'descricao' => 'Troca do rolamento', 'solicitante_id' => $adm->id, 'status' => 'aberta']);
        MaterialOs::create(['ordem_servico_id' => $os->id, 'descricao' => 'Correia', 'quantidade' => 2, 'custo_unitario' => 10, 'status' => 'entregue']);
        $url = "/ordens-de-servico/pedidos-de-compra/da-ordem/{$os->id}";

        $this->actingAs($adm)->get("/ordens-de-servico/ordens/{$os->id}")->assertOk()
            ->assertSee('Quanto esta OS está custando')->assertSee('R$ 20,00')->assertSee('Gerar pedido ou solicitação de compra');

        $this->post($url, ['centro_custo_origem' => '1001', 'centro_custo_destino' => '3003', 'itens' => [['produto_codigo' => 'NAOEXISTE', 'quantidade' => 1]]])
            ->assertSessionHasErrors('itens.0.produto_codigo');
        $this->post($url, ['centro_custo_origem' => '1001', 'centro_custo_destino' => '3003', 'itens' => [
            ['produto_codigo' => 'MP0001', 'quantidade' => 2, 'observacao' => 'Motor'],
            ['produto_codigo' => 'MP0002', 'quantidade' => 1],
            ['produto_codigo' => '', 'quantidade' => ''],
        ]])->assertRedirect()->assertSessionHasNoErrors();
        $pedido = PedidoCompra::sole();
        $this->assertSame($os->id, $pedido->ordem_servico_id);
        $this->assertSame(['0001', '0002'], $this->muroItens()->orderBy('ZP2_ITEM')->pluck('ZP2_ITEM')->all());
        $this->assertSame([1, 2], $this->muroItens()->orderBy('R_E_C_N_O_')->pluck('R_E_C_N_O_')->map(fn ($r) => (int) $r)->all());
        $this->assertSame($os->numeroFormatado(), $this->muro()->value('ZP1_ORIGEM'));

        // Valor do pedido entra no custo da OS.
        $this->muro()->update(['ZP1_STATUS' => 'APROVADO', 'ZP1_NUMPED' => '000777', 'ZP1_VALOR' => 150.25, 'ZP1_APROV' => 'Gerente']);
        $this->get("/ordens-de-servico/ordens/{$os->id}")->assertOk()->assertSee('R$ 170,25')->assertSee('Pedido Protheus 000777')->assertSee('Gerente');

        // OS concluída: não gera mais pedido.
        $os->forceFill(['status' => 'concluida'])->save();
        $this->post($url, ['centro_custo_origem' => '1001', 'centro_custo_destino' => '3003', 'itens' => [['produto_codigo' => 'MP0001', 'quantidade' => 1]]])->assertStatus(422);
    }

    public function test_falha_na_tabela_muro_nao_grava_nada_no_portal(): void
    {
        $adm = $this->usuario(['admin_producao'], 'adm@empresa.com');
        $sm = $this->solicitacaoAprovada($adm);
        $item = $sm->itens()->where('status', 'aprovado')->first();
        config(['compras.tabela_pedidos' => 'ZZZ999']);

        $this->actingAs($adm)->post("/ordens-de-servico/pedidos-de-compra/da-solicitacao/{$sm->id}",
            ['pedido_itens' => [$item->id], 'centro_custo_origem' => '1001', 'centro_custo_destino' => '3003'])->assertSessionHasErrors('pedido');
        $this->assertSame(0, PedidoCompra::count());
        $this->get("/ordens-de-servico/solicitacoes-de-material/{$sm->id}")->assertOk();
    }

    public function test_solicitacao_de_compra_pelo_mesmo_caminho(): void
    {
        $adm = $this->usuario(['admin_producao'], 'adm@empresa.com');
        $sm = $this->solicitacaoAprovada($adm);
        $item = $sm->itens()->where('status', 'aprovado')->first();

        $this->actingAs($adm)->get("/ordens-de-servico/solicitacoes-de-material/{$sm->id}")->assertOk()->assertSee('Solicitação de compra');
        $this->post("/ordens-de-servico/pedidos-de-compra/da-solicitacao/{$sm->id}", ['tipo' => 'outro', 'pedido_itens' => [$item->id],
            'centro_custo_origem' => '1001', 'centro_custo_destino' => '3003'])->assertSessionHasErrors('tipo');
        $this->post("/ordens-de-servico/pedidos-de-compra/da-solicitacao/{$sm->id}", ['tipo' => 'solicitacao', 'pedido_itens' => [$item->id],
            'centro_custo_origem' => '1001', 'centro_custo_destino' => '3003'])->assertSessionHasNoErrors();
        $sc = PedidoCompra::sole();
        $this->assertSame(['solicitacao', 'SC-'], [$sc->tipo, substr($sc->numeroFormatado(), 0, 3)]);
        $this->assertSame('SC', $this->muro()->value('ZP1_TIPO'));

        // O item já está na SC: não vai para um pedido.
        $this->post("/ordens-de-servico/pedidos-de-compra/da-solicitacao/{$sm->id}", ['pedido_itens' => [$item->id],
            'centro_custo_origem' => '1001', 'centro_custo_destino' => '3003'])->assertSessionHasErrors('pedido_itens');

        // O Protheus integra a SC e depois a atende com o pedido 000999.
        $this->muro()->update(['ZP1_STATUS' => 'AGUARDANDO_APROVACAO', 'ZP1_NUMPED' => '000555']);
        $this->get("/ordens-de-servico/solicitacoes-de-material/{$sm->id}")->assertSee('SC Protheus 000555')->assertSee('Integrada — aguardando aprovação');
        $this->muro()->update(['ZP1_STATUS' => 'CONCLUIDO', 'ZP1_VALOR' => 42.9, 'ZP1_DTCONC' => '20261121']);
        $this->muroItens()->update(['ZP2_NUMPC' => '000999', 'ZP2_QTDENT' => 3, 'ZP2_VTOTAL' => 42.9]);
        $this->get("/ordens-de-servico/solicitacoes-de-material/{$sm->id}")->assertSee('Atendida (pedido gerado)')->assertSee('000999')->assertSee('R$ 42,90');
        $this->assertSame('000999', $sc->itens()->value('pedido_gerado'));
    }
}
