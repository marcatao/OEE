<?php

namespace Tests\Feature;

use App\Models\ApontamentoEstado;
use App\Models\Legenda;
use App\Models\Linha;
use App\Models\Maquina;
use App\Models\OrdemProducao;
use App\Models\Planta;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Legenda com "Exige OP": o apontamento real com ela precisa da ordem de produção. */
class LegendaExigeOpTest extends TestCase
{
    use RefreshDatabase;

    public function test_legenda_exige_op_e_o_apontamento_cobra_a_op(): void
    {
        $admin = Usuario::create(['entra_oid' => 'oid-a', 'nome' => 'Adm', 'email' => 'adm@empresa.com', 'perfil' => 'admin']);
        $planta = Planta::create(['codigo' => 'P', 'nome' => 'Planta']);
        $linha = Linha::create(['planta_id' => $planta->id, 'codigo' => 'L', 'nome' => 'Linha']);
        $maquina = Maquina::create(['linha_id' => $linha->id, 'codigo' => 'INJ-01', 'nome' => 'Injetora', 'ativo' => true]);
        OrdemProducao::create(['c2_num' => '509859', 'c2_item' => '01', 'c2_sequen' => '001', 'numero' => '50985901001', 'status' => 'aberta']);

        // Cadastro: campo Exige OP (Sim/Não), padrão Não.
        $this->actingAs($admin)->get('/admin/legendas/nova')->assertOk()->assertSee('Exige OP');
        $this->post('/admin/legendas', ['codigo' => '2', 'descricao' => 'Produção Normal', 'tipo' => 'produzindo', 'cor' => '#43a047',
            'ativo' => '1', 'exige_op' => '1'])->assertSessionHasNoErrors();
        $this->post('/admin/legendas', ['codigo' => '16', 'descricao' => 'Refeição', 'tipo' => 'planejada', 'cor' => '#90a4ae', 'ativo' => '1'])
            ->assertSessionHasNoErrors();
        $producao = Legenda::where('codigo', '2')->sole();
        $refeicao = Legenda::where('codigo', '16')->sole();
        $this->assertSame([true, false], [$producao->exige_op, $refeicao->exige_op]);
        $this->get('/admin/legendas')->assertSee('Exige OP');

        // Editar sem mandar o campo mantém o valor.
        $this->put("/admin/legendas/{$producao->id}", ['codigo' => '2', 'descricao' => 'Produção Normal', 'tipo' => 'produzindo',
            'cor' => '#43a047', 'ativo' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue($producao->fresh()->exige_op);

        // Apontamento: com a legenda que exige OP, sem OP é recusado; com OP passa. A outra não exige.
        $base = ['maquina_id' => $maquina->id, 'inicio' => now('America/Sao_Paulo')->subHour()->format('Y-m-d\TH:i')];
        $this->get('/producao/apontamentos/novo')->assertSee('(exige OP)')->assertSee('data-exige-op="1"', false);
        $this->post('/producao/apontamentos', $base + ['legenda_id' => $producao->id])->assertSessionHasErrors('ordem_producao');
        $this->assertSame(0, ApontamentoEstado::count());
        $agora = now('America/Sao_Paulo');
        $this->post('/producao/apontamentos', ['maquina_id' => $maquina->id, 'legenda_id' => $producao->id, 'ordem_producao' => '50985901001',
            'inicio' => $agora->copy()->subHours(3)->format('Y-m-d\TH:i'), 'fim' => $agora->copy()->subHours(2)->format('Y-m-d\TH:i')])
            ->assertSessionHasNoErrors();
        $this->post('/producao/apontamentos', $base + ['legenda_id' => $refeicao->id])->assertSessionHasNoErrors();
        $this->assertSame(2, ApontamentoEstado::count());
    }
}
