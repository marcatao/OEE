<?php

namespace Tests\Feature;

use App\Models\ApontamentoEstado;
use App\Models\Legenda;
use App\Models\Linha;
use App\Models\Maquina;
use App\Models\OrdemProducao;
use App\Models\Planta;
use App\Models\Usuario;
use App\Services\SituacaoMaquina;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CalendarioDeTeste;
use Tests\Concerns\IotDeTeste;
use Tests\TestCase;

/** Timeline: OP fechada sai da máquina; apontamento sem OP numa legenda que exige OP pisca e recebe a OP. */
class TimelineOpPendenteTest extends TestCase
{
    use CalendarioDeTeste;
    use IotDeTeste;
    use RefreshDatabase;

    private function cenario(): array
    {
        $admin = Usuario::create(['entra_oid' => 'adm', 'nome' => 'Admin', 'email' => 'adm@empresa.com', 'perfil' => 'admin']);
        $this->actingAs($admin);
        $planta = Planta::create(['codigo' => 'P', 'nome' => 'Planta']);
        $linha = Linha::create(['planta_id' => $planta->id, 'codigo' => 'L', 'nome' => 'Linha']);
        $maquina = Maquina::create(['codigo' => 'INJ-01', 'nome' => 'Injetora 01', 'linha_id' => $linha->id, 'ativo' => true] + $this->jornadaGravada() + $this->iotGravado());
        $produz = Legenda::create(['codigo' => '1', 'descricao' => 'Produzindo', 'tipo' => 'produzindo', 'cor' => '#2e7d32', 'exige_op' => true]);
        $aberta = OrdemProducao::create(['numero' => '00010001001', 'c2_num' => '000100', 'c2_item' => '01', 'c2_sequen' => '001',
            'c2_produto' => 'PA1', 'c2_datrf' => '', 'status' => 'aberta', 'd_e_l_e_t_' => ' ', 'r_e_c_n_o_' => 1]);
        $fechada = OrdemProducao::create(['numero' => '00020001001', 'c2_num' => '000200', 'c2_item' => '01', 'c2_sequen' => '001',
            'c2_produto' => 'PA1', 'c2_datrf' => '20261101', 'status' => 'fechada', 'd_e_l_e_t_' => ' ', 'r_e_c_n_o_' => 2]);

        return [$maquina, $produz, $aberta, $fechada];
    }

    public function test_op_fechada_sai_da_maquina_ao_abrir_a_timeline(): void
    {
        [$maquina, , $aberta, $fechada] = $this->cenario();
        $outra = Maquina::create(['codigo' => 'INJ-02', 'nome' => 'Injetora 02', 'linha_id' => $maquina->linha_id, 'ativo' => true,
            'ordem_producao_id' => $aberta->id] + $this->jornadaGravada());
        $maquina->update(['ordem_producao_id' => $fechada->id]);

        $this->get('/dashboards/timeline')->assertOk();
        $this->assertNull($maquina->fresh()->ordem_producao_id);
        $this->assertSame($aberta->id, $outra->fresh()->ordem_producao_id);
        $this->assertSame(0, app(SituacaoMaquina::class)->desvincularOpsFechadas([$aberta->id]));
    }

    public function test_informar_op_no_apontamento_que_pisca(): void
    {
        [$maquina, $produz, $aberta, $fechada] = $this->cenario();
        $ap = ApontamentoEstado::create(['maquina_id' => $maquina->id, 'legenda_id' => $produz->id, 'inicio' => now()->subHour()]);

        $this->get('/dashboards/timeline')->assertOk()->assertSee('INFORMAR OP')->assertSee('tl-pendente-op', false)
            ->assertSee('1 apontamento exige ordem de produção', false)->assertSee('Informar OP</button>', false)->assertSee('Injetora 01 · 1 · Produzindo')->assertSee('tl-dialogo-op', false);

        $url = "/producao/apontamentos/{$ap->id}/informar-op";
        $this->postJson($url, ['ordem_producao' => $fechada->numero])->assertUnprocessable()->assertJsonValidationErrors('ordem_producao');
        $this->postJson($url, ['ordem_producao' => ''])->assertUnprocessable();
        $this->postJson($url, ['ordem_producao' => $aberta->numero])->assertOk()->assertJson(['ok' => true]);

        $this->assertSame($aberta->id, $ap->fresh()->ordem_producao_id);
        $this->assertSame($aberta->id, $maquina->fresh()->ordem_producao_id);
        $this->assertSame('andamento', $aberta->fresh()->status);
        $this->get('/dashboards/timeline')->assertOk()->assertDontSee('INFORMAR OP');
    }
}
