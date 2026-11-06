<?php

namespace Tests\Feature;

use App\Models\Apontamento;
use App\Models\Linha;
use App\Models\Maquina;
use App\Models\MotivoParada;
use App\Models\Parada;
use App\Models\Planta;
use App\Services\IndicadoresOee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class IndicadoresOeeTest extends TestCase
{
    use RefreshDatabase;

    private Maquina $maquina;

    protected function setUp(): void
    {
        parent::setUp();
        $planta = Planta::create(['codigo' => 'P1', 'nome' => 'Planta 1']);
        $linha = Linha::create(['planta_id' => $planta->id, 'codigo' => 'L1', 'nome' => 'Linha 1']);
        $this->maquina = Maquina::create(['linha_id' => $linha->id, 'codigo' => 'M1', 'nome' => 'Injetora 1', 'ciclo_ideal_s' => 1]);
    }

    private function parada(string $inicio, ?string $fim, ?MotivoParada $motivo): void
    {
        Parada::create(['maquina_id' => $this->maquina->id, 'motivo_id' => $motivo?->id,
            'inicio' => Carbon::parse($inicio, 'UTC'), 'fim' => $fim ? Carbon::parse($fim, 'UTC') : null]);
    }

    public function test_oee_da_maquina_com_paradas_planejadas_e_nao_planejadas(): void
    {
        $quebra = MotivoParada::create(['codigo' => 'QUEB', 'descricao' => 'Quebra mecânica', 'planejada' => false]);
        $refeicao = MotivoParada::create(['codigo' => 'REF', 'descricao' => 'Refeição', 'planejada' => true]);

        Apontamento::create(['maquina_id' => $this->maquina->id, 'inicio' => Carbon::parse('2026-11-05 08:00', 'UTC'),
            'fim' => Carbon::parse('2026-11-05 16:00', 'UTC'), 'ciclo_ideal_s' => 1, 'total_produzido' => 19271, 'pecas_boas' => 18848]);
        $this->parada('2026-11-05 10:00', '2026-11-05 10:47', $quebra);     // 47 min não planejada
        $this->parada('2026-11-05 12:00', '2026-11-05 12:30', $refeicao);   // 30 min planejada
        $this->parada('2026-11-05 17:00', '2026-11-05 18:00', $quebra);     // fora da janela de produção: ignorada

        $ind = app(IndicadoresOee::class)->daMaquina($this->maquina->id,
            Carbon::parse('2026-11-05 00:00', 'UTC'), Carbon::parse('2026-11-06 00:00', 'UTC'));

        $this->assertEqualsWithDelta(480, $ind->tempoProgramadoMin, 1e-6);
        $this->assertEqualsWithDelta(450, $ind->tempoPlanejadoMin(), 1e-6);
        $this->assertEqualsWithDelta(403, $ind->tempoOperandoMin(), 1e-6);
        $this->assertEqualsWithDelta(403 / 450, $ind->disponibilidade(), 1e-6);
        $this->assertEqualsWithDelta((19271 / 60) / 403, $ind->performance(), 1e-6);
        $this->assertEqualsWithDelta(18848 / 19271, $ind->qualidade(), 1e-6);
        $this->assertSame('Quebra mecânica', $ind->pareto[0]['motivo']);
        $this->assertEqualsWithDelta(47, $ind->pareto[0]['minutos'], 1e-6);
        $this->assertSame(1, $ind->pareto[0]['ocorrencias']);
    }

    public function test_paradas_sobrepostas_nao_contam_duas_vezes_e_sem_motivo_conta_como_nao_planejada(): void
    {
        Apontamento::create(['maquina_id' => $this->maquina->id, 'inicio' => Carbon::parse('2026-11-05 08:00', 'UTC'),
            'fim' => Carbon::parse('2026-11-05 10:00', 'UTC'), 'ciclo_ideal_s' => 1, 'total_produzido' => 100, 'pecas_boas' => 100]);
        $this->parada('2026-11-05 07:30', '2026-11-05 08:20', null); // começa antes da janela: conta 20 min
        $this->parada('2026-11-05 08:10', '2026-11-05 08:30', null); // sobrepõe: total unido 30 min

        $ind = app(IndicadoresOee::class)->daMaquina($this->maquina->id,
            Carbon::parse('2026-11-05 00:00', 'UTC'), Carbon::parse('2026-11-06 00:00', 'UTC'));

        $this->assertEqualsWithDelta(30, $ind->paradasNaoPlanejadasMin, 1e-6);
        $this->assertEqualsWithDelta(90 / 120, $ind->disponibilidade(), 1e-6);
        $this->assertSame('Sem justificativa', $ind->pareto[0]['motivo']);
    }

    public function test_sem_apontamentos_no_periodo_e_sem_dados(): void
    {
        $ind = app(IndicadoresOee::class)->daMaquina($this->maquina->id, now()->startOfDay(), now()->endOfDay());

        $this->assertFalse($ind->temDados());
        $this->assertNull($ind->oee());
    }
}
