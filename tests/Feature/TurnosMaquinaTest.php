<?php

namespace Tests\Feature;

use App\Models\Linha;
use App\Models\Maquina;
use App\Models\OrdemProducao;
use App\Models\Planta;
use App\Models\Usuario;
use App\Services\PerdasOrdemProducao;
use App\Services\TurnoDaMaquina;
use App\Support\JornadaSemanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CalendarioDeTeste;
use Tests\TestCase;

/** Até 3 turnos por dia nas máquinas e o turno da perda (pedido do usuário em 25/11/2026). */
class TurnosMaquinaTest extends TestCase
{
    use CalendarioDeTeste;
    use RefreshDatabase;

    private Linha $linha;

    protected function setUp(): void
    {
        parent::setUp();
        $this->linha = Linha::create(['planta_id' => Planta::create(['codigo' => 'P', 'nome' => 'Planta'])->id, 'codigo' => 'L', 'nome' => 'Linha']);
        $u = Usuario::create(['entra_oid' => 'adm', 'nome' => 'Adm', 'email' => 'adm@empresa.com', 'perfil' => 'admin']);
        $this->actingAs($u);
    }

    /** turno[dia][n] para os dias úteis, com os turnos informados. */
    private function turnos(array $turnos, array $dias = [1, 2, 3, 4, 5]): array
    {
        $campos = [];
        foreach ($dias as $d) {
            foreach ($turnos as $n => [$i, $f]) {
                $campos[$d][$n] = ['inicio' => $i, 'fim' => $f];
            }
        }

        return ['calendario_id' => $this->jornada()['calendario_id'], 'dias' => $dias, 'turno' => $campos];
    }

    private function salvar(array $turnos)
    {
        return $this->post('/admin/maquinas', ['linha_id' => $this->linha->id, 'codigo' => 'INJ-01', 'nome' => 'Injetora 01', 'ativo' => '0']
            + $this->turnos($turnos));
    }

    public function test_cadastro_com_tres_turnos_soma_as_horas_e_grava_o_envelope(): void
    {
        $this->get('/admin/maquinas/nova')->assertOk()->assertSee('Turno 3')->assertSee('Horas disponíveis');
        $this->salvar([1 => ['06:00', '14:00'], 2 => ['14:00', '22:00'], 3 => ['22:00', '06:00']])->assertSessionHasNoErrors();
        $m = Maquina::sole();
        $turnos = JornadaSemanal::turnos($m);
        $this->assertSame([1 => ['06:00', '14:00'], 2 => ['14:00', '22:00'], 3 => ['22:00', '06:00']], $turnos[1]);
        $this->assertSame(1440, JornadaSemanal::minutosDoDia($turnos[1]));          // 24 h disponíveis
        $this->assertSame(['06:00', '05:59'], JornadaSemanal::de($m)[1]);              // envelope para quem lê a jornada antiga
        $this->assertSame('12345', $m->dias_semana);
        $this->get("/admin/maquinas/{$m->id}/editar")->assertSee('name="turno[1][3][inicio]" value="22:00"', false);
    }

    public function test_turnos_invalidos_sao_recusados(): void
    {
        $this->salvar([1 => ['06:00', '14:00'], 2 => ['13:00', '22:00']])->assertSessionHasErrors('turno.1'); // sobrepostos
        $this->salvar([1 => ['06:00', '14:00'], 3 => ['22:00', '06:00']])->assertSessionHasErrors('turno.1'); // turno 3 sem o 2
        $this->salvar([2 => ['14:00', '22:00']])->assertSessionHasErrors('turno.1');                         // sem o turno 1
        $this->salvar([1 => ['06:00', '06:00']])->assertSessionHasErrors('turno.1');                         // início = fim
        $this->assertSame(0, Maquina::count());
    }

    public function test_intervalos_turno_do_horario_e_perda_do_app_pelo_horario(): void
    {
        $this->salvar([1 => ['06:00', '12:00'], 2 => ['13:00', '18:00']])->assertSessionHasNoErrors(); // almoço 12:00–13:00 fora
        $m = Maquina::sole();
        $fuso = config('oee.fuso_exibicao');
        Carbon::setTestNow(Carbon::parse('2026-11-25 14:30', $fuso)); // quarta-feira, turno 2
        $turno = app(TurnoDaMaquina::class);
        $intervalos = $turno->intervalos($m);
        $this->assertSame([[1, '06:00', '12:00'], [2, '13:00', '18:00']],
            array_map(fn ($t) => [$t['numero'], $t['de']->setTimezone($fuso)->format('H:i'), $t['ate']->setTimezone($fuso)->format('H:i')], $intervalos));
        [$de, $ate, $rotulo] = $turno->periodo($m);
        $this->assertSame(['06:00', '18:01'], [$de->setTimezone($fuso)->format('H:i'), $ate->setTimezone($fuso)->format('H:i')]);
        $this->assertStringContainsString('T1 06:00–12:00 · T2 13:00–18:00', $rotulo);
        $this->assertSame(2, $turno->turnoEm($m, Carbon::now()->toImmutable()));
        $this->assertNull($turno->turnoEm($m, Carbon::parse('2026-11-25 12:30', $fuso)->toImmutable())); // intervalo: fora de turno

        // Perda pelo app: turno do horário do lançamento (ignora o que vier).
        $op = OrdemProducao::create(['numero' => '00010001001', 'c2_num' => '000100', 'c2_item' => '01', 'c2_sequen' => '001',
            'c2_produto' => 'PA1', 'c2_datrf' => '', 'status' => 'aberta', 'd_e_l_e_t_' => ' ', 'r_e_c_n_o_' => 1]);
        $perda = app(PerdasOrdemProducao::class)->registrar($op, auth()->user(), ['quantidade' => 3, 'maquina_id' => $m->id, 'turno' => 1], 'aplicativo');
        $this->assertSame(2, $perda->turno);
        Carbon::setTestNow();
    }
}
