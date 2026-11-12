<?php

namespace Tests\Feature;

use App\Models\ApontamentoEstado;
use App\Models\Legenda;
use App\Models\Linha;
use App\Models\Maquina;
use App\Models\Planta;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Horário do apontamento real: um dia só; no mesmo dia, o próximo começa 1 segundo depois do fim do anterior
 * (travado); o primeiro do dia é livre.
 */
class HorarioApontamentoTest extends TestCase
{
    use RefreshDatabase;

    private Maquina $maquina;

    private int $prod;

    private int $parada;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-05 20:00', 'UTC')); // 17:00 em São Paulo
        config(['oee.fuso_exibicao' => 'America/Sao_Paulo']);
        $planta = Planta::create(['codigo' => 'P', 'nome' => 'Planta']);
        $linha = Linha::create(['planta_id' => $planta->id, 'codigo' => 'L', 'nome' => 'Linha']);
        $this->maquina = Maquina::create(['linha_id' => $linha->id, 'codigo' => 'INJ-01', 'nome' => 'Injetora 01', 'ativo' => true]);
        $this->prod = Legenda::where('codigo', 'PROD')->value('id');
        $this->parada = Legenda::where('codigo', 'DT')->value('id');
        $this->actingAs(Usuario::create(['entra_oid' => 'oid-a', 'nome' => 'Adm', 'email' => 'adm@empresa.com', 'perfil' => 'admin']));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function apontar(array $dados)
    {
        return $this->post('/producao/apontamentos', $dados + ['maquina_id' => $this->maquina->id, 'legenda_id' => $this->prod, 'dia' => '2026-11-05']);
    }

    public function test_primeiro_do_dia_e_livre_e_os_seguintes_encadeiam_com_1_segundo(): void
    {
        $this->getJson('/producao/apontamentos/proximo-inicio?maquina_id='.$this->maquina->id.'&dia=2026-11-05')
            ->assertJson(['travado' => false, 'hora' => null]);

        $this->apontar(['hora_inicio' => '06:13:00', 'hora_fim' => '08:00:00'])->assertSessionHasNoErrors();

        // O próximo começa às 08:00:01, travado.
        $this->getJson('/producao/apontamentos/proximo-inicio?maquina_id='.$this->maquina->id.'&dia=2026-11-05')
            ->assertJson(['travado' => true, 'hora' => '08:00:01']);
        $this->get('/producao/apontamentos/novo?maquina_id='.$this->maquina->id)->assertOk()
            ->assertSee('value="08:00:01"', false)->assertSee('readonly', false);

        $this->apontar(['legenda_id' => $this->parada, 'hora_inicio' => '08:00:00'])->assertSessionHasErrors('hora_inicio');
        $this->apontar(['legenda_id' => $this->parada, 'hora_inicio' => '08:05:00'])->assertSessionHasErrors('hora_inicio');
        $this->apontar(['legenda_id' => $this->parada, 'hora_inicio' => '08:00:01', 'hora_fim' => '09:00:00'])->assertSessionHasNoErrors();
        $this->assertSame(2, ApontamentoEstado::count());

        // Outro dia: livre de novo.
        $this->apontar(['dia' => '2026-11-04', 'hora_inicio' => '10:00:00', 'hora_fim' => '11:00:00'])->assertSessionHasNoErrors();
    }

    public function test_anterior_em_aberto_e_encerrado_1_segundo_antes(): void
    {
        $this->apontar(['hora_inicio' => '07:00:00'])->assertSessionHasNoErrors(); // em aberto
        $this->getJson('/producao/apontamentos/proximo-inicio?maquina_id='.$this->maquina->id.'&dia=2026-11-05')->assertJson(['travado' => false]);

        $this->apontar(['legenda_id' => $this->parada, 'hora_inicio' => '06:00:00'])->assertSessionHasErrors('hora_inicio'); // antes dele
        $this->apontar(['legenda_id' => $this->parada, 'hora_inicio' => '09:30:00'])->assertSessionHasNoErrors();
        $primeiro = ApontamentoEstado::orderBy('inicio')->first();
        $this->assertSame('09:29:59', $primeiro->fim->setTimezone('America/Sao_Paulo')->format('H:i:s'));
    }

    public function test_apontamento_nao_passa_de_um_dia(): void
    {
        // Fim antes do início no mesmo dia, ou em outro dia (formato antigo): recusado.
        $this->apontar(['hora_inicio' => '10:00:00', 'hora_fim' => '09:00:00'])->assertSessionHasErrors('hora_fim');
        $this->post('/producao/apontamentos', ['maquina_id' => $this->maquina->id, 'legenda_id' => $this->prod,
            'inicio' => '2026-11-04T22:00', 'fim' => '2026-11-05T02:00'])->assertSessionHasErrors('hora_fim');
        $this->assertSame(0, ApontamentoEstado::count());

        // Em aberto num dia; o primeiro do dia seguinte o encerra às 23:59:59 do dia dele.
        $this->apontar(['dia' => '2026-11-04', 'hora_inicio' => '22:00:00'])->assertSessionHasNoErrors();
        $this->apontar(['legenda_id' => $this->parada, 'hora_inicio' => '06:00:00'])->assertSessionHasNoErrors();
        $ontem = ApontamentoEstado::orderBy('inicio')->first();
        $this->assertSame('2026-11-04 23:59:59', $ontem->fim->setTimezone('America/Sao_Paulo')->format('Y-m-d H:i:s'));

        // Dia completo (o último vai até 23:59:59): não cabe outro.
        $this->getJson('/producao/apontamentos/proximo-inicio?maquina_id='.$this->maquina->id.'&dia=2026-11-04')
            ->assertJson(['travado' => true, 'completo' => true]);
        $this->apontar(['dia' => '2026-11-04', 'hora_inicio' => '23:59:59'])->assertSessionHasErrors('hora_inicio');
    }
}
