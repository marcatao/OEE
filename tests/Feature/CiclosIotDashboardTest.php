<?php

namespace Tests\Feature;

use App\Models\Linha;
use App\Models\Maquina;
use App\Models\Planta;
use App\Models\Usuario;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\IotDeTeste;
use Tests\TestCase;

/** Gráfico "Ciclos da máquina" do Dashboard por máquina, lido da IOT001 (só leitura). */
class CiclosIotDashboardTest extends TestCase
{
    use IotDeTeste, RefreshDatabase;

    private Maquina $maquina;

    protected function setUp(): void
    {
        parent::setUp();
        $linha = Linha::create(['planta_id' => Planta::create(['codigo' => 'P', 'nome' => 'Planta'])->id, 'codigo' => 'L', 'nome' => 'Linha']);
        $this->maquina = Maquina::create(['linha_id' => $linha->id, 'codigo' => 'INJ-01', 'nome' => 'Injetora 01', 'ativo' => true,
            'micro_parada_ms' => 500, 'macro_parada_ms' => 120000] + $this->iotGravado());
        $u = Usuario::create(['entra_oid' => 'oid-a', 'nome' => 'Ana', 'email' => 'ana@empresa.com', 'perfil' => 'admin']);
        $this->actingAs($u->fresh());
    }

    private function criarIot001(): void
    {
        config(['oee.iot_leituras.tabela' => 'iot001']);
        Schema::create('iot001', function ($t) {
            $t->id();
            $t->bigInteger('pid')->default(0);
            $t->string('device_id', 100);
            $t->bigInteger('ts_unix');
            foreach (config('oee.iot_canais') as $c) {
                $t->decimal($c, 18, 4)->nullable();
            }
        });
    }

    public function test_mostra_os_ciclos_do_dia_escolhido_e_as_paradas_grandes(): void
    {
        $this->criarIot001();
        $ontem = CarbonImmutable::now(config('oee.fuso_exibicao'))->subDay()->setTime(8, 0);
        $t = $ontem->getTimestamp();
        $linhas = [];
        foreach ([0 => 100, 10 => 101, 20 => 102, 30 => 103, 630 => 104, 640 => 105] as $s => $v) {
            $linhas[] = ['device_id' => 'IOT-T1', 'ts_unix' => $t + $s, 'chd1_value' => $v];
        }
        $linhas[] = ['device_id' => 'OUTRO', 'ts_unix' => $t + 5, 'chd1_value' => 999]; // outro aparelho: fora
        DB::table('iot001')->insert($linhas);

        $r = $this->get(route('dashboard.maquina', ['maquina' => $this->maquina, 'periodo' => '7dias', 'ciclos_dia' => $ontem->format('Y-m-d')]));
        $r->assertOk()->assertSee('Ciclos da máquina — '.$ontem->format('d/m/Y'))
            ->assertSee('data-grafico-ciclos', false)
            ->assertSee('Paradas grandes do dia (1; maior: 10 min)');
        $this->assertStringContainsString('['.($t + 630).',104,1,600,600,2]', $r->getContent());
        $this->assertStringNotContainsString('999', $r->getContent());
        // O gráfico fica acima do cartão "Dispositivos IoT".
        $this->assertLessThan(strpos($r->getContent(), '<h3>Dispositivos IoT</h3>'), strpos($r->getContent(), 'Ciclos da máquina'));

        // Dia fora do período volta para o mais recente (hoje).
        $this->get(route('dashboard.maquina', ['maquina' => $this->maquina, 'periodo' => 'hoje', 'ciclos_dia' => $ontem->format('Y-m-d')]))
            ->assertOk()->assertSee('Ciclos da máquina — '.CarbonImmutable::now(config('oee.fuso_exibicao'))->format('d/m/Y'))
            ->assertSee('Nenhuma mudança do contador');
    }

    public function test_sem_acesso_a_iot001_avisa_sem_quebrar_a_tela(): void
    {
        config(['oee.iot_leituras.tabela' => 'tabela_que_nao_existe']);
        $this->get(route('dashboard.maquina', $this->maquina))->assertOk()
            ->assertSee('Não foi possível ler a tabela tabela_que_nao_existe')
            ->assertSee('Dispositivos IoT');
    }
}
