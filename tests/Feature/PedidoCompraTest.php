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
            ->assertSee('Gerar pedido de compra')->assertSee('name="pedido_itens[]" value="'.$aprovado->id.'"', false);

        // Item não aprovado não entra; centros de custo são obrigatórios.
        $this->post($url, ['pedido_itens' => [$pendente->id], 'centro_custo_origem' => '1001', 'centro_custo_destino' => '3003'])->assertSessionHasErrors('pedido_itens');
        $this->post($url, ['pedido_itens' => [$aprovado->id]])->assertSessionHasErrors(['centro_custo_origem', 'centro_custo_destino']);
        $this->assertSame(0, $this->muro()->count());

        $this->post($url, ['pedido_itens' => [$aprovado->id], 'centro_custo_origem' => '1001', 'centro_custo_destino' => '3003', 'observacao_pedido' => 'Urgente'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $pedido = PedidoCompra::sole();
        $this->assertSame('aguardando_protheus', $pedido->status);
        $muro = $this->muro()->sole();
        $this->assertSame('AGUARDANDO_PROTHEUS', $muro->STATUS);
        $this->assertSame($pedido->id, (int) $muro->ID_PORTAL);
        $this->assertSame(['1001', '3003', 'Urgente', $sm->numeroFormatado()], [$muro->CC_ORIGEM, $muro->CC_DESTINO, $muro->OBSERVACAO, $muro->ORIGEM]);
        $linha = $this->muroItens()->sole();
        $this->assertSame(['0001', 'MP0001'], [$linha->ITEM, $linha->PRODUTO]);
        $this->assertEquals(3, (float) $linha->QUANTIDADE); // a quantidade aprovada

        // O mesmo item não entra em outro pedido.
        $this->post($url, ['pedido_itens' => [$aprovado->id], 'centro_custo_origem' => '1001', 'centro_custo_destino' => '3003'])->assertSessionHasErrors('pedido_itens');

        // O Protheus integra: número e aguardando aprovação.
        $this->muro()->update(['STATUS' => 'AGUARDANDO_APROVACAO', 'NUM_PEDIDO' => '012345', 'INTEGRADO_EM' => '2026-11-17 10:00:00', 'VALOR_TOTAL' => 75.5]);
        $this->muroItens()->update(['C7_ITEM' => '0001', 'VALOR_UNITARIO' => 25.1667, 'VALOR_TOTAL' => 75.5, 'QTD_ENTREGUE' => 0]);
        $this->get("/ordens-de-servico/solicitacoes-de-material/{$sm->id}")->assertOk()
            ->assertSee('Pedido Protheus 012345')->assertSee('Integrado — aguardando aprovação')->assertSee('R$ 75,50');
        $pedido->refresh();
        $this->assertSame('012345', $pedido->numero_pedido);
        $this->assertSame('2026-11-17 13:00:00', $pedido->integrado_em->format('Y-m-d H:i:s')); // horário local → UTC
        $this->assertFalse($pedido->podeCancelar());
        $this->post("/ordens-de-servico/pedidos-de-compra/{$pedido->id}/cancelar")->assertSessionHasErrors('pedido');

        // Aprovado e concluído (pelo comando agendado).
        $this->muro()->update(['STATUS' => 'CONCLUIDO', 'APROVADOR' => 'Fulano Compras', 'APROVADO_EM' => '2026-11-18 08:00:00',
            'CONCLUIDO_EM' => '2026-11-20 15:30:00', 'VALOR_TOTAL' => 80]);
        $this->muroItens()->update(['VALOR_TOTAL' => 80, 'QTD_ENTREGUE' => 3]);
        $this->artisan('compras:atualizar-pedidos')->assertSuccessful();
        $pedido->refresh();
        $this->assertSame(['concluido', 'Fulano Compras'], [$pedido->status, $pedido->aprovador]);
        $this->assertEquals(80, (float) $pedido->valor_total);
        $this->assertEquals(3, (float) $pedido->itens()->value('quantidade_entregue'));
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
        $this->muro()->update(['STATUS' => 'ERRO', 'MENSAGEM' => 'Produto sem preço']);
        $this->get("/ordens-de-servico/solicitacoes-de-material/{$sm->id}")->assertSee('Produto sem preço')->assertSee('Reenviar ao Protheus');
        $this->post("/ordens-de-servico/pedidos-de-compra/{$pedido->id}/reenviar")->assertSessionHasNoErrors();
        $this->assertSame('AGUARDANDO_PROTHEUS', $this->muro()->value('STATUS'));
        $this->post("/ordens-de-servico/pedidos-de-compra/{$pedido->id}/cancelar")->assertSessionHasNoErrors();
        $this->assertSame('CANCELADO', $this->muro()->value('STATUS'));
        $this->assertSame('cancelado', $pedido->fresh()->status);
        $this->post("/ordens-de-servico/pedidos-de-compra/da-solicitacao/{$sm->id}", $dados)->assertSessionHasNoErrors();
        $this->assertSame(2, PedidoCompra::count());
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
            ->assertSee('Quanto esta OS está custando')->assertSee('R$ 20,00')->assertSee('Gerar pedido de compra');

        $this->post($url, ['centro_custo_origem' => '1001', 'centro_custo_destino' => '3003', 'itens' => [['produto_codigo' => 'NAOEXISTE', 'quantidade' => 1]]])
            ->assertSessionHasErrors('itens.0.produto_codigo');
        $this->post($url, ['centro_custo_origem' => '1001', 'centro_custo_destino' => '3003', 'itens' => [
            ['produto_codigo' => 'MP0001', 'quantidade' => 2, 'observacao' => 'Motor'],
            ['produto_codigo' => 'MP0002', 'quantidade' => 1],
            ['produto_codigo' => '', 'quantidade' => ''],
        ]])->assertRedirect()->assertSessionHasNoErrors();
        $pedido = PedidoCompra::sole();
        $this->assertSame($os->id, $pedido->ordem_servico_id);
        $this->assertSame(['0001', '0002'], $this->muroItens()->orderBy('ITEM')->pluck('ITEM')->all());
        $this->assertSame($os->numeroFormatado(), $this->muro()->value('ORIGEM'));

        // Valor do pedido entra no custo da OS.
        $this->muro()->update(['STATUS' => 'APROVADO', 'NUM_PEDIDO' => '000777', 'VALOR_TOTAL' => 150.25, 'APROVADOR' => 'Gerente']);
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
        config(['compras.tabela_pedidos' => 'TABELA_QUE_NAO_EXISTE']);

        $this->actingAs($adm)->post("/ordens-de-servico/pedidos-de-compra/da-solicitacao/{$sm->id}",
            ['pedido_itens' => [$item->id], 'centro_custo_origem' => '1001', 'centro_custo_destino' => '3003'])->assertSessionHasErrors('pedido');
        $this->assertSame(0, PedidoCompra::count());
        $this->get("/ordens-de-servico/solicitacoes-de-material/{$sm->id}")->assertOk();
    }
}
