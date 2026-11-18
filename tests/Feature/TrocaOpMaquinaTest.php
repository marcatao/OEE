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
use Tests\Concerns\CalendarioDeTeste;
use Tests\Concerns\IotDeTeste;
use Tests\TestCase;

/** Timeline › "Trocar OP": no apontamento atual (inteiro) ou num novo apontamento a partir de agora. */
class TrocaOpMaquinaTest extends TestCase
{
    use CalendarioDeTeste;
    use IotDeTeste;
    use RefreshDatabase;

    private Maquina $maquina;

    private Legenda $produzindo;

    private OrdemProducao $op1;

    private OrdemProducao $op2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(Usuario::create(['entra_oid' => 'adm', 'nome' => 'Admin', 'email' => 'adm@alumbra.com.br', 'perfil' => 'admin']));
        $planta = Planta::create(['codigo' => 'P', 'nome' => 'Planta']);
        $linha = Linha::create(['planta_id' => $planta->id, 'codigo' => 'L', 'nome' => 'Linha']);
        $this->maquina = Maquina::create(['codigo' => 'INJ-01', 'nome' => 'Injetora 01', 'linha_id' => $linha->id, 'ativo' => true]
            + $this->jornadaGravada() + $this->iotGravado());
        $this->produzindo = Legenda::create(['codigo' => '1', 'descricao' => 'Produzindo', 'tipo' => 'produzindo', 'cor' => '#2e7d32']);
        $this->op1 = OrdemProducao::create(['numero' => '00010001001', 'c2_num' => '000100', 'c2_item' => '01', 'c2_sequen' => '001',
            'c2_produto' => 'PA1', 'c2_datrf' => '', 'status' => 'aberta', 'd_e_l_e_t_' => ' ', 'r_e_c_n_o_' => 1]);
        $this->op2 = OrdemProducao::create(['numero' => '00020001001', 'c2_num' => '000200', 'c2_item' => '01', 'c2_sequen' => '001',
            'c2_produto' => 'PA2', 'c2_datrf' => '', 'status' => 'aberta', 'd_e_l_e_t_' => ' ', 'r_e_c_n_o_' => 2]);
    }

    private function emAndamento(): ApontamentoEstado
    {
        $ap = ApontamentoEstado::create(['maquina_id' => $this->maquina->id, 'legenda_id' => $this->produzindo->id,
            'ordem_producao_id' => $this->op1->id, 'inicio' => now()->subHour()]);
        $this->maquina->update(['ordem_producao_id' => $this->op1->id]);

        return $ap;
    }

    private function trocar(string $modo, ?string $numero = null)
    {
        return $this->postJson("/producao/apontamentos/maquinas/{$this->maquina->id}/trocar-op",
            ['ordem_producao' => $numero ?? $this->op2->numero, 'modo' => $modo]);
    }

    public function test_timeline_mostra_trocar_op_ao_lado_da_op(): void
    {
        $this->emAndamento();
        $this->get('/dashboards/timeline')->assertOk()->assertSee('Trocar OP')->assertSee('tl-dialogo-troca', false)
            ->assertSee('Novo apontamento a partir de agora');
    }

    public function test_modo_atual_troca_o_apontamento_inteiro(): void
    {
        $ap = $this->emAndamento();
        $this->trocar('atual')->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(1, ApontamentoEstado::count());
        $this->assertSame($this->op2->id, $ap->fresh()->ordem_producao_id);
        $this->assertNull($ap->fresh()->fim);
        $this->assertSame($this->op2->id, $this->maquina->fresh()->ordem_producao_id);
    }

    public function test_modo_novo_encerra_e_comeca_outro_na_mesma_legenda(): void
    {
        $ap = $this->emAndamento();
        $this->trocar('novo')->assertOk();

        $this->assertSame($this->op1->id, $ap->fresh()->ordem_producao_id); // a OP anterior fica até agora
        $this->assertNotNull($ap->fresh()->fim);
        $novo = ApontamentoEstado::whereNull('fim')->firstOrFail();
        $this->assertSame($this->op2->id, $novo->ordem_producao_id);
        $this->assertSame($this->produzindo->id, $novo->legenda_id);
        $this->assertSame($ap->fresh()->fim->addSecond()->getTimestamp(), $novo->inicio->getTimestamp());
    }

    public function test_recusa_op_fechada_e_modo_invalido(): void
    {
        $this->emAndamento();
        $this->op2->update(['status' => 'fechada']);
        $this->trocar('novo')->assertUnprocessable()->assertJsonValidationErrors('ordem_producao');
        $this->trocar('qualquer', $this->op1->numero)->assertUnprocessable()->assertJsonValidationErrors('modo');
    }
}
