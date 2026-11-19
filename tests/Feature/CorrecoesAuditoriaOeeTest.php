<?php

namespace Tests\Feature;

use App\Models\ApontamentoEstado;
use App\Models\Legenda;
use App\Models\Linha;
use App\Models\Maquina;
use App\Models\Planta;
use App\Models\Usuario;
use App\Services\HorarioApontamento;
use App\Services\SituacaoMaquina;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\IotDeTeste;
use Tests\TestCase;

/** Falhas encontradas na auditoria (OEE, Timeline e apontamento real) e corrigidas: não podem voltar. */
class CorrecoesAuditoriaOeeTest extends TestCase
{
    use IotDeTeste;
    use RefreshDatabase;

    private Maquina $maquina;

    private Legenda $produz;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-05 15:00', 'UTC')); // 12:00 em São Paulo
        $linha = Linha::create(['planta_id' => Planta::create(['codigo' => 'P', 'nome' => 'Planta'])->id, 'codigo' => 'L', 'nome' => 'Linha']);
        $this->maquina = Maquina::create(['linha_id' => $linha->id, 'codigo' => 'INJ-01', 'nome' => 'Injetora 01', 'ativo' => true,
            'unidade' => 'manutencao'] + $this->iotGravado());
        $this->produz = Legenda::create(['codigo' => 'XP1', 'descricao' => 'Produzindo', 'tipo' => 'produzindo', 'cor' => '#2e7d32', 'unidade' => 'manutencao']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function usuario(array $perfis, string $email): Usuario
    {
        $u = Usuario::create(['entra_oid' => "oid-{$email}", 'nome' => 'Fulano', 'email' => $email]);
        $u->definirPerfis($perfis);

        return $u->fresh();
    }

    public function test_apontamento_de_maquina_de_outra_unidade_nao_abre_nem_altera(): void
    {
        $ap = ApontamentoEstado::create(['maquina_id' => $this->maquina->id, 'legenda_id' => $this->produz->id,
            'inicio' => Carbon::parse('2026-11-05 11:00', 'UTC'), 'fim' => Carbon::parse('2026-11-05 12:00', 'UTC')]);
        $ti = $this->usuario(['admin_tecnologia'], 'ti@empresa.com');

        $this->actingAs($ti)->get("/producao/apontamentos/{$ap->id}/editar")->assertNotFound();
        $this->delete("/producao/apontamentos/{$ap->id}")->assertNotFound();
        $this->post('/producao/apontamentos', ['maquina_id' => $this->maquina->id, 'legenda_id' => $this->produz->id,
            'dia' => '2026-11-05', 'hora_inicio' => '10:00:00'])->assertSessionHasErrors(['maquina_id', 'legenda_id']);
        $this->getJson('/producao/apontamentos/proximo-inicio?maquina_id='.$this->maquina->id.'&dia=2026-11-05')->assertNotFound();
        $this->assertSame(1, ApontamentoEstado::count());
    }

    public function test_timeline_e_dashboard_com_legenda_de_outra_unidade_nao_quebram(): void
    {
        // Máquina "Todas" com a legenda 98 do IoT cadastrada só para a Tecnologia: quem é da Manutenção vê a linha normal.
        $this->maquina->update(['unidade' => 'ambos']);
        $semMotivo = Legenda::create(['codigo' => 'X98', 'descricao' => 'Parada sem motivo', 'tipo' => 'parada', 'cor' => '#ff0000', 'unidade' => 'tecnologia']);
        $a = ApontamentoEstado::create(['maquina_id' => $this->maquina->id, 'legenda_id' => $semMotivo->id, 'inicio' => Carbon::parse('2026-11-05 14:00', 'UTC')]);
        app(SituacaoMaquina::class)->aplicar($a);

        $this->actingAs($this->usuario(['admin_producao'], 'adm@empresa.com'));
        $this->get('/dashboards/timeline')->assertOk()->assertSee('Parada sem motivo');
        $this->get('/producao/dashboard')->assertOk();
    }

    public function test_apontamento_aberto_que_passa_da_meia_noite_nao_perde_a_madrugada(): void
    {
        $this->actingAs($this->usuario(['admin_producao'], 'adm@empresa.com'));
        $dt = Legenda::create(['codigo' => 'XDT', 'descricao' => 'Parada', 'tipo' => 'parada', 'cor' => '#c62828', 'unidade' => 'manutencao']);
        // PROD aberto desde 04/11 22:00 (local); DT em 05/11 09:00 (local).
        $aberto = ApontamentoEstado::create(['maquina_id' => $this->maquina->id, 'legenda_id' => $this->produz->id,
            'inicio' => Carbon::parse('2026-11-05 01:00', 'UTC'), 'ordem_producao_id' => null]);
        $this->post('/producao/apontamentos', ['maquina_id' => $this->maquina->id, 'legenda_id' => $dt->id,
            'dia' => '2026-11-05', 'hora_inicio' => '09:00:00'])->assertSessionHasNoErrors();

        $local = fn ($c) => $c->copy()->setTimezone(HorarioApontamento::fuso())->format('d/m H:i:s');
        $lista = ApontamentoEstado::orderBy('inicio')->get();
        $this->assertSame(['04/11 22:00:00', '05/11 00:00:00', '05/11 09:00:00'], $lista->map(fn ($a) => $local($a->inicio))->all());
        $this->assertSame('04/11 23:59:59', $local($aberto->fresh()->fim));
        $this->assertSame([$this->produz->id, '05/11 08:59:59'], [(int) $lista[1]->legenda_id, $local($lista[1]->fim)]); // continuação
        $this->assertStringContainsString('virada do dia', $lista[1]->observacao);
    }
}
