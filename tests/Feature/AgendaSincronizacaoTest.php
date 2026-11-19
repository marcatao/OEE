<?php

namespace Tests\Feature;

use App\Models\AgendaSincronizacao;
use App\Models\Sincronizacao;
use App\Models\Usuario;
use App\Services\Protheus\AgendaSincronizacoes;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Periodicidade da sincronização de cada tabela (Banco de dados) e o comando que o agendador roda a cada minuto. */
class AgendaSincronizacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['oee.fuso_exibicao' => 'America/Sao_Paulo']);
        Carbon::setTestNow(Carbon::parse('2026-11-20 13:30', 'UTC')); // 10:30 em São Paulo
        config(['database.connections.protheus' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('protheus');
        Schema::connection('protheus')->create('CTT', function (Blueprint $t) {
            foreach (['CTT_FILIAL', 'CTT_CUSTO', 'CTT_DESC01', 'CTT_CLASSE', 'CTT_BLOQ', 'CTT_CCSUP', 'CTT_DTEXIS', 'CTT_DTEXSF', 'D_E_L_E_T_'] as $c) {
                $t->string($c)->default('');
            }
            $t->integer('R_E_C_N_O_');
        });
        DB::connection('protheus')->table('CTT')->insert(['CTT_FILIAL' => '01', 'CTT_CUSTO' => '1001', 'CTT_DESC01' => 'USINAGEM', 'R_E_C_N_O_' => 1]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function local(string $quando): CarbonImmutable
    {
        return CarbonImmutable::parse($quando, 'America/Sao_Paulo');
    }

    public function test_horarios_do_dia(): void
    {
        $h = fn ($intervalo, $inicio) => array_map(fn ($m) => sprintf('%02d:%02d', intdiv($m, 60), $m % 60), AgendaSincronizacoes::horarios($intervalo, $inicio));
        $this->assertCount(24, $h(60, '00:00'));
        $this->assertSame(['00:00', '01:00', '02:00'], array_slice($h(60, '00:00'), 0, 3));
        $this->assertSame(['00:00'], $h(23 * 60 + 59, '00:00')); // a cada 23:59 = 1x ao dia
        $this->assertSame(['00:00'], $h(24 * 60, '00:00'));
        $this->assertSame(['06:00', '13:00', '20:00'], $h(7 * 60, '06:00')); // às 03:00 não: só 3 h até as 06:00
        $this->assertSame(['22:00', '04:00', '10:00', '16:00'], $h(6 * 60, '22:00'));

        $agenda = new AgendaSincronizacao(['chave' => 'centros_custo', 'ativo' => true, 'intervalo_minutos' => 60, 'inicio' => '00:00']);
        $this->assertSame('24x ao dia: 00:00, 01:00, 02:00 … 23:00', AgendaSincronizacoes::descrever($agenda));
        $agenda->intervalo_minutos = 1439;
        $this->assertSame('1x ao dia, às 00:00', AgendaSincronizacoes::descrever($agenda));
    }

    public function test_ultimo_e_proximo_horario(): void
    {
        $servico = app(AgendaSincronizacoes::class);
        $agenda = new AgendaSincronizacao(['intervalo_minutos' => 60, 'inicio' => '00:00']);
        $this->assertSame('2026-11-20 10:00', $servico->ultimoHorario($agenda, $this->local('2026-11-20 10:30'))->format('Y-m-d H:i'));
        $this->assertSame('2026-11-20 11:00', $servico->proximoHorario($agenda, $this->local('2026-11-20 10:30'))->format('Y-m-d H:i'));

        // 1x ao dia às 06:00: de madrugada, o último é o de ontem e o próximo o de hoje.
        $diaria = new AgendaSincronizacao(['intervalo_minutos' => 1439, 'inicio' => '06:00']);
        $this->assertSame('2026-11-19 06:00', $servico->ultimoHorario($diaria, $this->local('2026-11-20 03:00'))->format('Y-m-d H:i'));
        $this->assertSame('2026-11-20 06:00', $servico->proximoHorario($diaria, $this->local('2026-11-20 03:00'))->format('Y-m-d H:i'));

        // De hora em hora a partir das 06:00: o ciclo de ontem segue até as 05:00 de hoje.
        $horaria = new AgendaSincronizacao(['intervalo_minutos' => 60, 'inicio' => '06:00']);
        $this->assertSame('2026-11-20 04:00', $servico->proximoHorario($horaria, $this->local('2026-11-20 03:30'))->format('Y-m-d H:i'));
    }

    public function test_tela_salva_a_periodicidade_e_o_comando_sincroniza_na_hora(): void
    {
        $admin = Usuario::create(['entra_oid' => 'adm', 'nome' => 'Admin', 'email' => 'adm@empresa.com', 'perfil' => 'admin']);
        $this->actingAs($admin)->get('/admin/banco')->assertOk()->assertSee('Periodicidade')->assertSee('Desligada');

        $this->put('/admin/banco/agendas/centros_custo', ['ativo' => '1', 'intervalo' => '00:02', 'inicio' => '00:00'])
            ->assertSessionHasErrors('intervalo');
        $this->put('/admin/banco/agendas/inexistente', ['ativo' => '1', 'intervalo' => '01:00', 'inicio' => '00:00'])->assertNotFound();
        $this->put('/admin/banco/agendas/centros_custo', ['ativo' => '1', 'intervalo' => '01:00', 'inicio' => '00:00'])
            ->assertRedirect('/admin/banco')->assertSessionHas('ok', fn ($m) => str_contains($m, '24x ao dia'));
        $agenda = AgendaSincronizacao::sole();
        $this->assertSame([true, 60, '00:00', $admin->id], [$agenda->ativo, $agenda->intervalo_minutos, $agenda->inicio, $agenda->criado_por_id]);
        $this->get('/admin/banco')->assertSee('A cada 01:00')->assertSee('20/11/2026 11:00');

        // Salva às 10:30: o horário das 10:00 já passou e não dispara; o das 11:00 sim, uma vez só.
        $this->artisan('protheus:sincronizar-agendadas')->expectsOutputToContain('Nenhuma tabela')->assertSuccessful();
        Carbon::setTestNow(Carbon::parse('2026-11-20 14:00:30', 'UTC')); // 11:00:30
        $this->artisan('protheus:sincronizar-agendadas')->expectsOutputToContain('1 lidos')->assertSuccessful();
        $this->artisan('protheus:sincronizar-agendadas')->expectsOutputToContain('Nenhuma tabela')->assertSuccessful();
        $this->assertSame(['agendado'], Sincronizacao::pluck('disparo')->all());
        $this->assertSame([$admin->id, null], [AgendaSincronizacao::sole()->criado_por_id, AgendaSincronizacao::sole()->atualizado_por_id]); // o agendador não muda quem editou

        // Servidor parado das 11:00 às 15:10: ao voltar, roda uma vez só.
        Carbon::setTestNow(Carbon::parse('2026-11-20 18:10', 'UTC'));
        $this->artisan('protheus:sincronizar-agendadas')->assertSuccessful();
        $this->assertSame(2, Sincronizacao::count());

        // Desligada: não roda.
        $this->put('/admin/banco/agendas/centros_custo', ['intervalo' => '01:00', 'inicio' => '00:00'])->assertSessionHas('ok', fn ($m) => str_contains($m, 'desligada'));
        Carbon::setTestNow(Carbon::parse('2026-11-20 20:00:30', 'UTC'));
        $this->artisan('protheus:sincronizar-agendadas')->expectsOutputToContain('Nenhuma tabela')->assertSuccessful();
        $this->assertSame(2, Sincronizacao::count());
    }
}
