<?php

namespace Tests\Feature;

use App\Models\Calendario;
use App\Models\DispositivoIot;
use App\Models\Linha;
use App\Models\Manutencao\AreaExecutante;
use App\Models\Manutencao\Mantenedor;
use App\Models\Maquina;
use App\Models\Planta;
use App\Models\Usuario;
use App\Services\Calendario\DiasUteis;
use App\Services\Calendario\Feriados;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Calendários de dias úteis (Administração › Calendários), máquinas e mantenedores. */
class CalendarioTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(array $perfis, string $email): Usuario
    {
        $u = Usuario::create(['entra_oid' => "oid-{$email}", 'nome' => ucfirst(strtok($email, '@')), 'email' => $email]);
        $u->definirPerfis($perfis);

        return $u->fresh();
    }

    private function diasUteisPadrao(int $ano, array $feriados): int
    {
        $total = 0;
        for ($d = CarbonImmutable::create($ano, 1, 1); $d->year === $ano; $d = $d->addDay()) {
            $total += ($d->dayOfWeekIso <= 5 && ! isset($feriados[$d->format('Y-m-d')])) ? 1 : 0;
        }

        return $total;
    }

    public function test_feriados_nacionais_sp_e_sao_bernardo(): void
    {
        $f = app(Feriados::class)->doAno(2026);
        $this->assertSame('2026-04-05', Feriados::pascoa(2026)->format('Y-m-d'));
        $this->assertSame('2027-03-28', Feriados::pascoa(2027)->format('Y-m-d'));
        foreach (['2026-01-01', '2026-02-16', '2026-02-17', '2026-04-03', '2026-04-21', '2026-05-01', '2026-06-04', '2026-07-09',
            '2026-08-20', '2026-09-07', '2026-10-12', '2026-11-02', '2026-11-15', '2026-11-20', '2026-12-25'] as $data) {
            $this->assertArrayHasKey($data, $f, $data);
        }
        $this->assertSame('Aniversário de São Bernardo do Campo', $f['2026-08-20']);
    }

    public function test_cria_calendario_com_feriados_e_edita_os_dias(): void
    {
        $this->actingAs($this->usuario(['admin_producao'], 'adm@empresa.com'));
        $this->get('/')->assertSee('Calendários');
        $this->get('/admin/calendarios')->assertOk();
        $this->post('/admin/calendarios', ['nome' => 'Padrão Manutenção', 'ano' => 2026, 'unidade' => 'manutencao'])->assertRedirect();
        $c = Calendario::sole();
        $this->assertSame(15, $c->dias()->count());
        $esperado = $this->diasUteisPadrao(2026, app(Feriados::class)->doAno(2026));

        $this->get("/admin/calendarios/{$c->id}")->assertOk()->assertSee('Dezembro')->assertSee('data-dia="2026-08-20"', false)
            ->assertSee('cal-feriado', false)->assertSee($esperado.'</strong> dias úteis', false);

        // Ponte na sexta depois de Corpus Christi; compensação num sábado; voltar ao padrão.
        $this->postJson("/admin/calendarios/{$c->id}/dia", ['data' => '2026-06-05', 'tipo' => 'ponte', 'descricao' => 'Ponte de Corpus Christi'])
            ->assertOk()->assertJson(['util' => false, 'tipo' => 'ponte', 'resumo' => ['total' => $esperado - 1]]);
        $this->postJson("/admin/calendarios/{$c->id}/dia", ['data' => '2026-06-13', 'tipo' => 'compensacao'])
            ->assertJson(['util' => true, 'resumo' => ['total' => $esperado]]);
        $this->postJson("/admin/calendarios/{$c->id}/dia", ['data' => '2026-06-05', 'tipo' => ''])->assertJson(['util' => true, 'tipo' => null]);
        $this->postJson("/admin/calendarios/{$c->id}/dia", ['data' => '2027-01-04', 'tipo' => 'ponte'])->assertUnprocessable(); // outro ano
        $this->postJson("/admin/calendarios/{$c->id}/dia", ['data' => '2026-01-05', 'tipo' => 'xyz'])->assertUnprocessable();

        // Cada unidade vê só os seus.
        $ti = Calendario::withoutGlobalScopes()->create(['nome' => 'TI', 'ano' => 2026, 'unidade' => 'tecnologia']);
        $this->get('/admin/calendarios')->assertSee('Padrão Manutenção')->assertDontSee('>TI<', false);
        $this->get("/admin/calendarios/{$ti->id}")->assertNotFound();
        $this->get('/admin/calendarios')->assertOk();
    }

    public function test_maquina_e_mantenedor_precisam_de_calendario_para_ativar(): void
    {
        $this->actingAs($this->usuario(['admin_producao'], 'adm@empresa.com'));
        $c = app(DiasUteis::class)->criar(['nome' => 'Padrão', 'ano' => 2026, 'unidade' => 'manutencao']);
        $planta = Planta::create(['codigo' => 'P', 'nome' => 'Planta']);
        $linha = Linha::create(['planta_id' => $planta->id, 'codigo' => 'L', 'nome' => 'Linha']);
        $iot = DispositivoIot::create(['codigo' => 'S1', 'nome' => 'Sensor', 'tipo' => 'sensor', 'protocolo' => 'mqtt', 'ativo' => true]);
        $base = ['codigo' => 'INJ-01', 'nome' => 'Injetora', 'linha_id' => $linha->id, 'ativo' => '1',
            'iot1_dispositivo_id' => $iot->id, 'iot1_canal' => 'chd1_value', 'iot1_tipo' => 'producao'];

        $this->get('/admin/maquinas/nova')->assertOk()->assertSee('Calendário e jornada')->assertSee('Padrão 2026');
        $this->post('/admin/maquinas', $base)->assertSessionHasErrors(['calendario_id', 'dias', 'jornada_inicio']);
        $this->post('/admin/maquinas', $base + ['calendario_id' => $c->id, 'dias' => [1, 2, 3, 4, 5, 6], 'jornada_inicio' => '06:00', 'jornada_fim' => '22:00'])
            ->assertSessionHasNoErrors();
        $m = Maquina::sole();
        $this->assertSame([$c->id, '123456', '06:00', '22:00'], [$m->calendario_id, $m->dias_semana, $m->jornada_inicio, $m->jornada_fim]);

        // A máquina entende os dias úteis: trabalha no sábado (dias dela), não no feriado; compensação num domingo, sim.
        $dias = app(DiasUteis::class);
        $this->assertTrue($dias->trabalha($m, '2026-06-06'));   // sábado
        $this->assertFalse($dias->trabalha($m, '2026-06-07'));  // domingo
        $this->assertFalse($dias->trabalha($m, '2026-06-04'));  // Corpus Christi
        $c->dias()->create(['data' => '2026-06-07', 'tipo' => 'compensacao']);
        $this->assertTrue($dias->trabalha($m, '2026-06-07'));

        // Mantenedor: calendário obrigatório para ativar.
        $area = AreaExecutante::create(['codigo' => 'MEC', 'nome' => 'Mecânica', 'unidade' => 'manutencao', 'ativo' => true]);
        $maria = $this->usuario(['mantenedor_producao'], 'maria@empresa.com');
        $dadosM = ['unidade' => 'manutencao', 'usuario_id' => $maria->id, 'jornada_inicio' => '07:00', 'jornada_fim' => '17:00',
            'intervalo_min' => 60, 'dias' => [1, 2, 3, 4, 5], 'areas' => [$area->id], 'ativo' => '1'];
        $this->get('/ordens-de-servico/cadastros/mantenedores/novo')->assertOk()->assertSee('Padrão 2026');
        $this->post('/ordens-de-servico/cadastros/mantenedores', $dadosM + ['calendario_id' => ''])->assertSessionHasErrors('calendario_id');
        $this->post('/ordens-de-servico/cadastros/mantenedores', $dadosM + ['calendario_id' => $c->id])->assertSessionHasNoErrors();
        $this->assertSame($c->id, Mantenedor::sole()->calendario_id);

        // Próximo ano: cria o de 2027 com os feriados e leva máquinas e mantenedores.
        $this->post("/admin/calendarios/{$c->id}/proximo-ano", ['mover' => '1'])->assertRedirect();
        $novo = Calendario::where('ano', 2027)->sole();
        $this->assertSame([$novo->id, $novo->id], [$m->fresh()->calendario_id, Mantenedor::sole()->calendario_id]);
        $this->assertTrue($novo->dias()->whereDate('data', '2027-03-26')->exists()); // Sexta-feira Santa de 2027
    }
}
