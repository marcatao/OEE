<?php

namespace Tests\Feature;

use App\Models\DispositivoIot;
use App\Models\Linha;
use App\Models\Maquina;
use App\Models\Planta;
use App\Models\Sincronizacao;
use App\Models\Usuario;
use App\Services\Protheus\SincronizadorProtheus;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Máquinas: tabela mista com a SHB do Protheus. O "Protheus" dos testes é um SQLite em memória separado.
 */
class MaquinasProtheusTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $admin;

    private Linha $linha;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.protheus' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('protheus');
        Schema::connection('protheus')->create('SHB', function (Blueprint $t) {
            foreach (['HB_FILIAL', 'HB_COD', 'HB_NOME', 'HB_CC', 'D_E_L_E_T_'] as $c) {
                $t->string($c)->default('');
            }
            $t->integer('R_E_C_N_O_');
        });
        $this->shb('INJ01 ', 'INJETORA 01                             ', '110101   ', 1);
        $this->shb('INJ02 ', 'INJETORA 02                             ', '         ', 2);

        $this->admin = Usuario::create(['entra_oid' => 'oid-admin', 'nome' => 'Admin', 'email' => 'admin@empresa.com', 'perfil' => 'admin']);
        $planta = Planta::create(['codigo' => 'P1', 'nome' => 'Planta 1']);
        $this->linha = Linha::create(['planta_id' => $planta->id, 'codigo' => 'L1', 'nome' => 'Linha 1']);
    }

    private function shb(string $cod, string $nome, string $cc, int $recno, string $deletado = ' '): void
    {
        DB::connection('protheus')->table('SHB')->insert([
            'HB_FILIAL' => '01', 'HB_COD' => $cod, 'HB_NOME' => $nome, 'HB_CC' => $cc, 'D_E_L_E_T_' => $deletado, 'R_E_C_N_O_' => $recno,
        ]);
    }

    private function sincronizar(): Sincronizacao
    {
        return app(SincronizadorProtheus::class)->sincronizar('maquinas', 'comando');
    }

    public function test_maquina_nova_do_protheus_chega_inativa_com_codigo_nome_e_centro_de_custo(): void
    {
        $r = $this->sincronizar();
        $this->assertSame(['sucesso', 2, 2], [$r->situacao, $r->lidos, $r->novos]);

        $m = Maquina::where('codigo', 'INJ01')->sole();
        $this->assertSame(['INJETORA 01', '110101', false, null, '01'], [$m->nome, $m->centro_custo, $m->ativo, $m->linha_id, $m->hb_filial]);
        $this->assertTrue($m->doProtheus());
        $this->assertTrue($m->aguardandoCadastro());
        $this->assertNull(Maquina::where('codigo', 'INJ02')->value('centro_custo'));
    }

    public function test_depois_de_sincronizada_a_maquina_nao_e_mais_alterada(): void
    {
        $this->sincronizar();
        $m = Maquina::where('codigo', 'INJ01')->sole();
        $m->update(['linha_id' => $this->linha->id, 'ativo' => true, 'ciclo_ideal_s' => 12.5]);

        DB::connection('protheus')->table('SHB')->where('R_E_C_N_O_', 1)->update(['HB_NOME' => 'NOME NOVO', 'HB_CC' => '999']);
        $r = $this->sincronizar();

        $this->assertSame([0, 0, 0, 2], [$r->novos, $r->atualizados, $r->excluidos, $r->ignorados]);
        $m->refresh();
        $this->assertSame(['INJETORA 01', '110101', true, 12.5], [$m->nome, $m->centro_custo, $m->ativo, $m->ciclo_ideal_s]);
    }

    public function test_excluida_no_protheus_e_desativada_e_nao_pode_ser_ativada(): void
    {
        $this->sincronizar();
        $m = Maquina::where('codigo', 'INJ01')->sole();
        $m->update(['linha_id' => $this->linha->id, 'ativo' => true]);

        DB::connection('protheus')->table('SHB')->where('R_E_C_N_O_', 1)->update(['D_E_L_E_T_' => '*']);
        $r = $this->sincronizar();
        $this->assertSame(1, $r->excluidos);

        $m->refresh();
        $this->assertTrue($m->excluidaNoProtheus());
        $this->assertFalse($m->ativo);
        $this->assertNotNull($m->excluida_protheus_em);

        $this->actingAs($this->admin)->get("/admin/maquinas/{$m->id}/editar")->assertOk()->assertSee('excluída no Protheus');
        $this->put("/admin/maquinas/{$m->id}", ['linha_id' => $this->linha->id, 'ativo' => '1'])->assertSessionHasErrors('ativo');
        $this->assertFalse($m->fresh()->ativo);

        // Sincronizar de novo não conta a exclusão outra vez.
        $this->assertSame(0, $this->sincronizar()->excluidos);
    }

    public function test_excluir_e_recriar_no_protheus_nao_marca_a_maquina_como_excluida(): void
    {
        $this->sincronizar();
        DB::connection('protheus')->table('SHB')->where('R_E_C_N_O_', 1)->update(['D_E_L_E_T_' => '*']);
        $this->shb('INJ01', 'INJETORA 01 NOVA', '110101', 10); // recriada com outro R_E_C_N_O_

        $r = $this->sincronizar();
        $this->assertSame(0, $r->excluidos);
        $this->assertFalse(Maquina::where('codigo', 'INJ01')->sole()->excluidaNoProtheus());
    }

    public function test_excluida_no_protheus_que_nunca_veio_para_o_portal_e_ignorada(): void
    {
        $this->shb('VELHA', 'MAQUINA ANTIGA', '', 3, '*');

        $r = $this->sincronizar();
        $this->assertSame([2, 0], [$r->novos, $r->excluidos]);
        $this->assertFalse(Maquina::where('codigo', 'VELHA')->exists());
    }

    public function test_maquina_cadastrada_a_mao_com_o_mesmo_codigo_e_vinculada_sem_duplicar(): void
    {
        $manual = Maquina::create(['codigo' => 'INJ01', 'nome' => 'Injetora um', 'linha_id' => $this->linha->id, 'ativo' => true, 'ciclo_ideal_s' => 8]);

        $r = $this->sincronizar();
        $this->assertSame([1, 1], [$r->novos, $r->atualizados]);

        $manual->refresh();
        $this->assertTrue($manual->doProtheus());
        $this->assertSame(['INJETORA 01', '110101', true, 8.0, $this->linha->id], [$manual->nome, $manual->centro_custo, $manual->ativo, $manual->ciclo_ideal_s, $manual->linha_id]);
        $this->assertSame(2, Maquina::count());
    }

    public function test_usuario_completa_o_cadastro_e_ativa_mas_nao_altera_os_campos_do_protheus(): void
    {
        $this->sincronizar();
        $m = Maquina::where('codigo', 'INJ01')->sole();

        $this->actingAs($this->admin)->get('/admin/maquinas')->assertOk()
            ->assertSee('2 máquinas do Protheus aguardam')->assertSee('Aguardando cadastro')->assertSee('Completar');
        $this->get("/admin/maquinas/{$m->id}/editar")->assertOk()->assertSee('Máquina vinda do Protheus');

        // Ativar sem linha: não deixa.
        $this->put("/admin/maquinas/{$m->id}", ['ativo' => '1'])->assertSessionHasErrors(['linha_id' => 'Para ativar a máquina, escolha a linha.']);

        // Completa (com um canal IoT) e ativa; tentativa de mudar código/nome/centro de custo é ignorada.
        $iot = DispositivoIot::create(['codigo' => 'S1', 'nome' => 'Sensor 1', 'tipo' => 'sensor', 'protocolo' => 'mqtt', 'ativo' => true]);
        $this->put("/admin/maquinas/{$m->id}", [
            'linha_id' => $this->linha->id, 'ciclo_ideal_s' => '15', 'ativo' => '1',
            'iot1_dispositivo_id' => $iot->id, 'iot1_canal' => 'chd1_value', 'iot1_tipo' => 'producao',
            'codigo' => 'OUTRO', 'nome' => 'Outro nome', 'centro_custo' => '999',
        ])->assertSessionDoesntHaveErrors()->assertRedirect('/admin/maquinas');

        $m->refresh();
        $this->assertSame([true, $this->linha->id, 15.0, 'INJ01', 'INJETORA 01', '110101'],
            [$m->ativo, $m->linha_id, $m->ciclo_ideal_s, $m->codigo, $m->nome, $m->centro_custo]);
        $this->assertFalse($m->aguardandoCadastro());

        $this->get('/admin/maquinas?situacao=aguardando')->assertSee('<code>INJ02</code>', false)->assertDontSee('<code>INJ01</code>', false); // código vira link para a edição
    }

    public function test_maquina_manual_sem_linha_so_pode_ficar_inativa(): void
    {
        $this->actingAs($this->admin)->post('/admin/maquinas', ['codigo' => 'M1', 'nome' => 'Manual', 'ativo' => '1'])
            ->assertSessionHasErrors('linha_id');
        $this->post('/admin/maquinas', ['codigo' => 'M1', 'nome' => 'Manual', 'ativo' => '0'])->assertSessionDoesntHaveErrors();
        $this->assertFalse(Maquina::where('codigo', 'M1')->sole()->doProtheus());
    }

    public function test_tela_banco_mostra_maquinas_como_tabela_mista(): void
    {
        config(['database.connections.protheus.database' => 'Compras']);

        $this->actingAs($this->admin)->get('/admin/banco')->assertOk()
            ->assertSee('Máquinas')->assertSee('Compras.SHB')->assertSee('origem-mista', false);
    }
}
