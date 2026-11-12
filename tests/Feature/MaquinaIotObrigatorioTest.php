<?php

namespace Tests\Feature;

use App\Models\DispositivoIot;
use App\Models\Linha;
use App\Models\Maquina;
use App\Models\Planta;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Máquina ativa precisa de canal IoT (dispositivo ativo + canal chd1..6); dispositivo + canal só em uma máquina. */
class MaquinaIotObrigatorioTest extends TestCase
{
    use RefreshDatabase;

    private Linha $linha;

    private DispositivoIot $iot;

    protected function setUp(): void
    {
        parent::setUp();
        $planta = Planta::create(['codigo' => 'P', 'nome' => 'Planta']);
        $this->linha = Linha::create(['planta_id' => $planta->id, 'codigo' => 'L', 'nome' => 'Linha']);
        $this->iot = DispositivoIot::create(['codigo' => 'IOT01', 'nome' => 'Gateway 01', 'tipo' => 'sensor', 'protocolo' => 'mqtt', 'ativo' => true]);
        $this->actingAs(Usuario::create(['entra_oid' => 'oid-a', 'nome' => 'Adm', 'email' => 'adm@empresa.com', 'perfil' => 'admin']));
    }

    private function dados(string $codigo, array $extra = []): array
    {
        return $extra + ['codigo' => $codigo, 'nome' => "Máquina {$codigo}", 'linha_id' => $this->linha->id, 'ativo' => '1'];
    }

    private function canal(int $n, string $canal, ?int $dispositivo = null): array
    {
        return ["iot{$n}_dispositivo_id" => $dispositivo ?? $this->iot->id, "iot{$n}_canal" => $canal, "iot{$n}_tipo" => 'producao'];
    }

    public function test_ativar_exige_um_canal_iot_e_o_canal_e_um_dos_seis(): void
    {
        $this->get('/admin/maquinas/nova')->assertOk()->assertSee('<option value="chd6_value"', false);
        $this->post('/admin/maquinas', $this->dados('M1'))->assertSessionHasErrors('ativo');
        $this->post('/admin/maquinas', $this->dados('M1', $this->canal(1, 'ch1_value')))->assertSessionHasErrors('iot1_canal');
        $this->post('/admin/maquinas', $this->dados('M1', ['ativo' => '0']))->assertSessionHasNoErrors(); // inativa pode
        $m = Maquina::where('codigo', 'M1')->sole();
        $this->put("/admin/maquinas/{$m->id}", $this->dados('M1', $this->canal(1, 'chd3_value')))->assertSessionHasNoErrors();
        $this->assertTrue($m->fresh()->ativo);
    }

    public function test_dispositivo_e_canal_nao_se_repetem_em_outra_maquina(): void
    {
        $this->post('/admin/maquinas', $this->dados('M1', $this->canal(1, 'chd1_value')))->assertSessionHasNoErrors();
        $this->post('/admin/maquinas', $this->dados('M2', $this->canal(2, 'chd1_value')))->assertSessionHasErrors('iot2_canal');
        $this->post('/admin/maquinas', $this->dados('M2', $this->canal(2, 'chd2_value')))->assertSessionHasNoErrors();

        // Vale também para máquina de outra unidade (que o usuário nem enxerga).
        Maquina::where('codigo', 'M1')->update(['unidade' => 'tecnologia']);
        $prod = Usuario::create(['entra_oid' => 'oid-p', 'nome' => 'P', 'email' => 'p@empresa.com', 'perfil' => 'operador']);
        $prod->definirPerfis(['admin_producao']);
        $this->actingAs($prod)->post('/admin/maquinas', $this->dados('M3', $this->canal(1, 'chd1_value')))->assertSessionHasErrors('iot1_canal');
    }

    public function test_apagar_os_canais_inativa_a_maquina(): void
    {
        $this->post('/admin/maquinas', $this->dados('M1', $this->canal(1, 'chd1_value')))->assertSessionHasNoErrors();
        $m = Maquina::where('codigo', 'M1')->sole();

        $vazio = ['iot1_dispositivo_id' => '', 'iot1_canal' => '', 'iot1_tipo' => ''];
        $this->put("/admin/maquinas/{$m->id}", $this->dados('M1', $vazio))->assertSessionHasNoErrors()
            ->assertSessionHas('ok', fn ($msg) => str_contains($msg, 'INATIVADA'));
        $this->assertFalse($m->fresh()->ativo);
    }

    public function test_desativar_o_dispositivo_inativa_as_maquinas_sem_outro_canal(): void
    {
        $outro = DispositivoIot::create(['codigo' => 'IOT02', 'nome' => 'Gateway 02', 'tipo' => 'sensor', 'protocolo' => 'mqtt', 'ativo' => true]);
        $this->post('/admin/maquinas', $this->dados('M1', $this->canal(1, 'chd1_value')))->assertSessionHasNoErrors();
        $this->post('/admin/maquinas', $this->dados('M2', $this->canal(1, 'chd2_value') + $this->canal(2, 'chd1_value', $outro->id)))->assertSessionHasNoErrors();

        $this->put("/admin/dispositivos-iot/{$this->iot->id}", ['codigo' => 'IOT01', 'nome' => 'Gateway 01', 'tipo' => 'sensor',
            'protocolo' => 'mqtt', 'ativo' => '0'])->assertSessionHas('ok', fn ($msg) => str_contains($msg, 'M1'));

        $this->assertFalse(Maquina::where('codigo', 'M1')->value('ativo'));
        $this->assertTrue((bool) Maquina::where('codigo', 'M2')->value('ativo')); // ainda tem o canal do IOT02
    }

    public function test_comando_desativa_as_maquinas_sem_iot_so_com_confirmar(): void
    {
        $com = Maquina::create(['linha_id' => $this->linha->id, 'codigo' => 'COM', 'nome' => 'Com IoT', 'ativo' => true] + $this->canal(1, 'chd1_value'));
        $sem = Maquina::create(['linha_id' => $this->linha->id, 'codigo' => 'SEM', 'nome' => 'Sem IoT', 'ativo' => true]);

        $this->artisan('maquinas:desativar-sem-iot')->expectsOutputToContain('SEM — Sem IoT')->assertSuccessful();
        $this->assertTrue($sem->fresh()->ativo);
        $this->artisan('maquinas:desativar-sem-iot --confirmar')->assertSuccessful();
        $this->assertFalse($sem->fresh()->ativo);
        $this->assertTrue($com->fresh()->ativo);
    }
}
