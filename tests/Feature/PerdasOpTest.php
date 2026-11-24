<?php

namespace Tests\Feature;

use App\Models\Grupo;
use App\Models\OrdemProducao;
use App\Models\PerdaOp;
use App\Models\ProdutoProtheus;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Aba "Apontamento de perdas" da tela da OP (pedido do usuário em 24/11/2026). */
class PerdasOpTest extends TestCase
{
    use RefreshDatabase;

    private OrdemProducao $op;

    protected function setUp(): void
    {
        parent::setUp();
        ProdutoProtheus::create(['b1_filial' => '', 'b1_cod' => 'PA1', 'b1_desc' => 'TAMPA 28MM', 'b1_um' => 'UN', 'b1_peso' => 0.0125, 'd_e_l_e_t_' => ' ']);
        $this->op = OrdemProducao::create(['numero' => '00010001001', 'c2_num' => '000100', 'c2_item' => '01', 'c2_sequen' => '001',
            'c2_produto' => 'PA1', 'c2_datrf' => '', 'status' => 'aberta', 'd_e_l_e_t_' => ' ', 'r_e_c_n_o_' => 1, 'c2_quant' => 1000, 'c2_perda' => 3]);
    }

    private function usuario(array $perfis, string $nome = 'Ana'): Usuario
    {
        $u = Usuario::create(['entra_oid' => 'oid-'.$nome, 'nome' => $nome, 'email' => mb_strtolower($nome).'@empresa.com']);
        $u->definirPerfis($perfis);

        return $u->fresh();
    }

    public function test_apontar_e_excluir_perdas_na_aba_da_op(): void
    {
        $this->actingAs($this->usuario(['operador']));
        $url = route('ordens-producao.show', $this->op);
        $this->get($url)->assertOk()->assertSee('Apontamento de perdas')->assertSee('data-calculadora-pu="0.0125"', false)
            ->assertSee('Apontar perda');

        // Peso com vírgula (brasileiro): quantidade = 1,25 kg ÷ 0,0125 = 100 peças.
        $this->post(route('perdas-op.store', $this->op), ['peso' => '1,25', 'dia' => now(config('oee.fuso_exibicao'))->format('Y-m-d'), 'observacao' => 'Rebarba'])
            ->assertRedirect($url.'#aba-perdas')->assertSessionHas('ok', fn ($m) => str_contains($m, '100 pç'));
        $this->post(route('perdas-op.store', $this->op), ['quantidade' => '20'])->assertRedirect();
        $perda = PerdaOp::where('quantidade', 20)->sole();
        $this->assertEqualsWithDelta(0.25, $perda->peso, 0.0001);
        $this->assertEquals(120, $this->op->fresh()->quantidade_perda);
        $this->assertEquals(3, $this->op->fresh()->c2_perda); // o C2_PERDA do Protheus não muda

        // Outro usuário exclui: sai do total, fica na lista como "Excluído por".
        $this->actingAs($this->usuario(['operador'], 'Bruno'));
        $this->delete(route('perdas-op.destroy', $perda))->assertRedirect($url.'#aba-perdas');
        $this->assertEquals(100, $this->op->fresh()->quantidade_perda);
        $this->assertNotNull($perda->fresh()->excluido_em);
        $this->get($url)->assertSee('Excluído por Bruno')->assertSee('perda-excluida', false);

        // Dia no futuro e nada informado: recusa, com a mensagem na aba.
        $this->post(route('perdas-op.store', $this->op), ['quantidade' => '5', 'dia' => now()->addDays(2)->format('Y-m-d')])->assertSessionHasErrors('dia');
        $this->post(route('perdas-op.store', $this->op), ['quantidade' => '', 'peso' => ''])->assertSessionHasErrors('quantidade');
    }

    public function test_sem_permissao_ve_a_lista_mas_nao_aponta(): void
    {
        $u = $this->usuario(['solicitante_manutencao'], 'Carla');
        $grupo = Grupo::create(['nome' => 'Consulta OP', 'ativo' => true]);
        $grupo->definirPermissoes(['ordens_producao']);
        $grupo->usuarios()->attach($u->id);
        $this->actingAs($u->fresh());
        $this->assertFalse(auth()->user()->pode('perdas_op'));
        $this->get(route('ordens-producao.show', $this->op))->assertOk()->assertSee('Apontamento de perdas')->assertDontSee('Apontar perda');
        $this->post(route('perdas-op.store', $this->op), ['quantidade' => '5'])->assertForbidden();
    }
}
