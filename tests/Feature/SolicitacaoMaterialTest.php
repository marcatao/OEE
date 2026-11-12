<?php

namespace Tests\Feature;

use App\Models\CentroCusto;
use App\Models\Manutencao\SolicitacaoMaterial;
use App\Models\ProdutoProtheus;
use App\Models\Usuario;
use App\Support\AcessoOs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Solicitação de material: o mantenedor pede (N itens) e o administrador da área analisa item a item. */
class SolicitacaoMaterialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        AcessoOs::limparCache();
        CentroCusto::create(['ctt_custo' => '1001', 'ctt_desc01' => 'INJECAO']);
        CentroCusto::create(['ctt_custo' => '2002', 'ctt_desc01' => 'ADMINISTRATIVO']);
        ProdutoProtheus::create(['b1_cod' => 'MP0001', 'b1_desc' => 'ROLAMENTO 6204 ZZ', 'b1_um' => 'PC']);
        ProdutoProtheus::create(['b1_cod' => 'MP0002', 'b1_desc' => 'GRAXA LITIO 1KG', 'b1_um' => 'KG']);
        ProdutoProtheus::create(['b1_cod' => 'MP0003', 'b1_desc' => 'ROLAMENTO BLOQUEADO', 'b1_um' => 'PC', 'b1_msblql' => '1']);
    }

    private function usuario(array $perfis, string $email, ?string $cc = null): Usuario
    {
        $u = Usuario::create(['entra_oid' => "oid-{$email}", 'nome' => ucfirst(strtok($email, '@')), 'email' => $email, 'local_escritorio' => $cc]);
        $u->definirPerfis($perfis);

        return $u->fresh();
    }

    private function pedir(Usuario $u): SolicitacaoMaterial
    {
        $this->actingAs($u)->post('/ordens-de-servico/solicitacoes-de-material', ['itens' => [
            ['produto_codigo' => 'MP0001', 'centro_custo' => '1001', 'quantidade' => 4, 'prioridade' => 'alta', 'observacao' => 'Motor da injetora 12'],
            ['produto_codigo' => 'MP0002', 'centro_custo' => '2002', 'quantidade' => 2.5, 'prioridade' => 'baixa'],
            ['produto_codigo' => '', 'centro_custo' => '', 'quantidade' => ''], // linha vazia é ignorada
        ]])->assertRedirect()->assertSessionHasNoErrors();

        return SolicitacaoMaterial::latest('id')->firstOrFail();
    }

    public function test_mantenedor_pede_e_administrador_analisa_item_a_item(): void
    {
        $mecanico = $this->usuario(['mantenedor_producao'], 'mecanico@empresa.com');
        $adm = $this->usuario(['admin_producao'], 'adm@empresa.com');

        $this->actingAs($mecanico)->get('/ordens-de-servico/solicitacoes-de-material/nova')->assertOk()->assertSee('Adicionar item');
        $sm = $this->pedir($mecanico);
        $this->assertSame('manutencao', $sm->unidade);
        $this->assertSame($mecanico->id, $sm->solicitante_id);
        $this->assertNotNull($sm->criado_em);
        $this->assertSame(2, $sm->itens()->count());
        $this->assertSame('ROLAMENTO 6204 ZZ', $sm->itens()->where('produto_codigo', 'MP0001')->value('produto_descricao'));

        // O mantenedor não analisa.
        $item1 = $sm->itens()->where('produto_codigo', 'MP0001')->first();
        $item2 = $sm->itens()->where('produto_codigo', 'MP0002')->first();
        $this->post("/ordens-de-servico/solicitacoes-de-material/{$sm->id}/analisar", ['acao' => 'aprovar', 'item' => $item1->id])->assertForbidden();
        $this->get("/ordens-de-servico/solicitacoes-de-material/{$sm->id}")->assertOk()->assertDontSee('name="acao" value="aprovar"', false);

        // Administrador aprova 3 de 4 (quantidade alterada) e reprova o outro com motivo.
        $this->actingAs($adm)->get("/ordens-de-servico/solicitacoes-de-material/{$sm->id}")->assertOk()->assertSee('name="acao" value="aprovar"', false);
        $this->post("/ordens-de-servico/solicitacoes-de-material/{$sm->id}/analisar", ['acao' => 'aprovar', 'item' => $item1->id, 'quantidade' => [$item1->id => 3]])->assertRedirect();
        $item1->refresh();
        $this->assertSame('aprovado', $item1->status);
        $this->assertEquals(3, (float) $item1->quantidade_aprovada);
        $this->assertSame($adm->id, $item1->aprovado_por_id);
        $this->assertNotNull($item1->aprovado_em);
        $this->assertSame('em_analise', $sm->fresh()->status);

        $this->post("/ordens-de-servico/solicitacoes-de-material/{$sm->id}/analisar", ['acao' => 'reprovar', 'item' => $item2->id])->assertSessionHasErrors("motivo.{$item2->id}");
        $this->post("/ordens-de-servico/solicitacoes-de-material/{$sm->id}/analisar", ['acao' => 'reprovar', 'item' => $item2->id, 'motivo' => [$item2->id => 'Tem no estoque da manutenção']])->assertRedirect();
        $this->assertSame('reprovado', $item2->fresh()->status);
        $this->assertSame('analisada', $sm->fresh()->status);
        // Item já analisado não volta a ser analisado.
        $this->post("/ordens-de-servico/solicitacoes-de-material/{$sm->id}/analisar", ['acao' => 'aprovar', 'item' => $item2->id])->assertSessionHasErrors('item');

        // O solicitante vê o resultado.
        $this->actingAs($mecanico)->get("/ordens-de-servico/solicitacoes-de-material/{$sm->id}")->assertOk()->assertSee('Aprovado')->assertSee('<strong>3</strong> PC', false)->assertSee('Tem no estoque da manutenção');
    }

    public function test_aprovar_todos_validacoes_e_quem_ve(): void
    {
        $mecanico = $this->usuario(['mantenedor_producao'], 'mecanico@empresa.com', '1001');
        $outro = $this->usuario(['mantenedor_producao'], 'outro@empresa.com');
        $admTi = $this->usuario(['admin_tecnologia'], 'adm-ti@empresa.com');
        $admProd = $this->usuario(['admin_producao'], 'adm@empresa.com');
        $operador = $this->usuario(['operador', 'solicitante_manutencao'], 'op@empresa.com');

        // Produto bloqueado, inexistente e CC fora da lista são recusados.
        $this->actingAs($mecanico)->post('/ordens-de-servico/solicitacoes-de-material', ['itens' => [
            ['produto_codigo' => 'MP0003', 'centro_custo' => '9999', 'quantidade' => 1, 'prioridade' => 'media'],
        ]])->assertSessionHasErrors(['itens.0.produto_codigo', 'itens.0.centro_custo']);
        $this->post('/ordens-de-servico/solicitacoes-de-material', ['itens' => []])->assertSessionHasErrors('itens');

        $sm = $this->pedir($mecanico);
        // Outro mantenedor não vê; o admin de Produção vê (área dele); o admin de Tecnologia vê tudo; operador não entra.
        $this->actingAs($outro)->get("/ordens-de-servico/solicitacoes-de-material/{$sm->id}")->assertNotFound();
        $this->actingAs($operador)->get('/ordens-de-servico/solicitacoes-de-material')->assertForbidden();
        $this->actingAs($admProd)->get('/ordens-de-servico/solicitacoes-de-material')->assertOk()->assertSee($sm->numeroFormatado());

        // Aprovar todos com as quantidades da tela.
        $this->actingAs($admTi)->post("/ordens-de-servico/solicitacoes-de-material/{$sm->id}/analisar", ['acao' => 'aprovar_todos'])->assertRedirect();
        $this->assertSame(2, $sm->itens()->where('status', 'aprovado')->count());
        $this->assertSame('analisada', $sm->fresh()->status);
        // Já analisada: não cancela mais.
        $this->actingAs($mecanico)->post("/ordens-de-servico/solicitacoes-de-material/{$sm->id}/cancelar")->assertForbidden();
    }

    public function test_busca_de_produtos_por_codigo_ou_descricao(): void
    {
        $this->actingAs($this->usuario(['mantenedor_producao'], 'mecanico@empresa.com'));
        $this->getJson('/ordens-de-servico/solicitacoes-de-material/produtos?q=r')->assertOk()->assertExactJson([]);
        $this->getJson('/ordens-de-servico/solicitacoes-de-material/produtos?q=MP0002')->assertOk()->assertJsonCount(1)->assertJsonFragment(['valor' => 'MP0002']);
        // Todas as palavras da descrição; bloqueado não aparece.
        $resultado = $this->getJson('/ordens-de-servico/solicitacoes-de-material/produtos?q=rolamento 6204')->assertOk()->json();
        $this->assertSame(['MP0001'], array_column($resultado, 'valor'));
        $this->getJson('/ordens-de-servico/solicitacoes-de-material/produtos?q=rolamento')->assertOk()->assertJsonCount(1);
    }
}
