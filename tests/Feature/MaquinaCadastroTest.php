<?php

namespace Tests\Feature;

use App\Models\CentroCusto;
use App\Models\Linha;
use App\Models\Maquina;
use App\Models\Planta;
use App\Models\Usuario;
use App\Services\ImportadorCentrosCusto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaquinaCadastroTest extends TestCase
{
    use RefreshDatabase;

    private Linha $linha;

    protected function setUp(): void
    {
        parent::setUp();
        $planta = Planta::create(['codigo' => 'MATRIZ', 'nome' => 'Planta Matriz']);
        $this->linha = Linha::create(['planta_id' => $planta->id, 'codigo' => 'L1', 'nome' => 'Linha 1']);
        $this->actingAs(Usuario::create(['entra_oid' => 'oid-admin', 'nome' => 'Admin', 'email' => 'admin@empresa.com', 'perfil' => 'admin']));
    }

    private function dados(array $extra = []): array
    {
        return $extra + ['linha_id' => $this->linha->id, 'codigo' => 'INJ-01', 'nome' => 'Injetora 01', 'ativo' => '1'];
    }

    public function test_cadastra_centro_de_custo_no_formato_do_protheus(): void
    {
        $this->get('/admin/centros-de-custo')->assertOk();
        $this->post('/admin/centros-de-custo', ['ctt_custo' => ' 110101 ', 'ctt_desc01' => 'Injeção', 'ctt_classe' => '2',
            'ctt_bloq' => '2', 'ctt_dtexis' => '2026-01-01'])->assertRedirect('/admin/centros-de-custo');

        $cc = CentroCusto::sole();
        $this->assertSame(['110101', 'Injeção', '', '20260101', ' '], [$cc->ctt_custo, $cc->ctt_desc01, $cc->ctt_filial, $cc->ctt_dtexis, $cc->d_e_l_e_t_]);
        $this->get('/admin/centros-de-custo')->assertSee('110101')->assertSee('Injeção')->assertSee('01/01/2026');

        $this->post('/admin/centros-de-custo', ['ctt_custo' => '110101', 'ctt_desc01' => 'Outro', 'ctt_classe' => '2', 'ctt_bloq' => '2'])
            ->assertSessionHasErrors('ctt_custo');
        $this->post('/admin/centros-de-custo', ['ctt_custo' => '110101', 'ctt_filial' => '02', 'ctt_desc01' => 'Injeção filial 02',
            'ctt_classe' => '2', 'ctt_bloq' => '2'])->assertRedirect(); // mesmo código em outra filial pode
        $this->assertSame(2, CentroCusto::count());
    }

    public function test_maquina_grava_so_o_codigo_do_centro_de_custo_paradas_e_canais_iot(): void
    {
        CentroCusto::create(['ctt_custo' => '110101', 'ctt_desc01' => 'Injeção']);

        $this->get('/admin/maquinas/nova')->assertOk()->assertSee('110101 — Injeção')->assertSee('Micro parada')
            ->assertSee('IoT 3 — Tipo do canal');

        $this->post('/admin/maquinas', $this->dados([
            'centro_custo' => '110101', 'micro_parada_s' => '60', 'macro_parada_s' => '300',
            'iot1_id' => 'Teste_Alumbra', 'iot1_canal' => 'ch1_value', 'iot1_tipo' => 'producao',
            'iot2_id' => 'Teste_Alumbra', 'iot2_canal' => 'ch2_value', 'iot2_tipo' => 'refugo',
        ]))->assertRedirect('/admin/maquinas');

        $m = Maquina::sole();
        $this->assertSame(['110101', 60, 300], [$m->centro_custo, $m->micro_parada_s, $m->macro_parada_s]);
        $this->assertSame(['Teste_Alumbra', 'ch1_value', 'producao'], [$m->iot1_id, $m->iot1_canal, $m->iot1_tipo]);
        $this->assertSame('refugo', $m->iot2_tipo);
        $this->assertNull($m->iot3_id);
        $this->assertCount(2, $m->canaisIot());
        $this->get('/admin/maquinas')->assertSee('110101 — Injeção')->assertSee('Teste_Alumbra · ch1_value · Produção');

        // O formulário sempre envia todos os campos; vazio limpa o valor.
        $vazios = ['centro_custo' => '', 'micro_parada_s' => '', 'macro_parada_s' => ''];
        foreach ([1, 2, 3] as $n) {
            $vazios += ["iot{$n}_id" => '', "iot{$n}_canal" => '', "iot{$n}_tipo" => ''];
        }
        $this->put("/admin/maquinas/{$m->id}", $this->dados(['ativo' => '0'] + $vazios))->assertSessionDoesntHaveErrors()->assertRedirect();
        $m->refresh();
        $this->assertFalse($m->ativo);
        $this->assertNull($m->centro_custo);
        $this->assertNull($m->iot1_id);
    }

    public function test_validacoes_das_paradas_e_dos_canais_iot(): void
    {
        $this->post('/admin/maquinas', $this->dados(['centro_custo' => '999']))->assertSessionHasErrors('centro_custo');
        $this->post('/admin/maquinas', $this->dados(['micro_parada_s' => '300', 'macro_parada_s' => '60']))->assertSessionHasErrors('macro_parada_s');
        $this->post('/admin/maquinas', $this->dados(['macro_parada_s' => '300']))->assertSessionDoesntHaveErrors();
        Maquina::query()->delete();

        $this->post('/admin/maquinas', $this->dados(['iot1_id' => 'Teste_Alumbra']))->assertSessionHasErrors(['iot1_canal', 'iot1_tipo']);
        $this->post('/admin/maquinas', $this->dados(['iot1_id' => 'X', 'iot1_canal' => 'c', 'iot1_tipo' => 'energia']))->assertSessionHasErrors('iot1_tipo');
        $this->post('/admin/maquinas', $this->dados(['iot1_id' => 'X', 'iot1_canal' => 'c', 'iot1_tipo' => 'producao',
            'iot2_id' => 'X', 'iot2_canal' => 'c', 'iot2_tipo' => 'refugo']))->assertSessionHasErrors('iot2_canal');

        $this->post('/admin/maquinas', $this->dados(['iot1_id' => 'X', 'iot1_canal' => 'c', 'iot1_tipo' => 'producao']))->assertSessionDoesntHaveErrors();
        $this->post('/admin/maquinas', $this->dados(['codigo' => 'INJ-02', 'iot3_id' => 'X', 'iot3_canal' => 'c', 'iot3_tipo' => 'producao']))
            ->assertSessionHasErrors('iot3_canal'); // canal já usado na INJ-01
        $this->assertSame(1, Maquina::count());
    }

    public function test_importa_centros_de_custo_da_ctt_do_protheus(): void
    {
        $importador = app(ImportadorCentrosCusto::class);
        $resumo = $importador->importar([
            ['CTT_FILIAL' => '01', 'CTT_CUSTO' => '110101   ', 'CTT_DESC01' => 'INJECAO          ', 'CTT_CLASSE' => '2', 'CTT_BLOQ' => '2',
                'CTT_CCSUP' => '1101 ', 'CTT_DTEXIS' => '20200101', 'CTT_DTEXSF' => '        ', 'D_E_L_E_T_' => ' ', 'R_E_C_N_O_' => 15],
            (object) ['CTT_FILIAL' => '01', 'CTT_CUSTO' => '110102', 'CTT_DESC01' => 'MONTAGEM', 'CTT_CLASSE' => '2', 'CTT_BLOQ' => '1',
                'CTT_CCSUP' => '', 'CTT_DTEXIS' => '', 'CTT_DTEXSF' => '', 'D_E_L_E_T_' => '*', 'R_E_C_N_O_' => 16],
            ['CTT_FILIAL' => '01', 'CTT_CUSTO' => '   '],
        ]);
        $this->assertSame(['novos' => 2, 'atualizados' => 0, 'ignorados' => 1], $resumo);

        $cc = CentroCusto::where('ctt_custo', '110101')->sole();
        $this->assertSame(['01', 'INJECAO', '1101', 15, true], [$cc->ctt_filial, $cc->ctt_desc01, $cc->ctt_ccsup, $cc->r_e_c_n_o_, $cc->veioDoProtheus()]);
        $this->assertTrue(CentroCusto::where('ctt_custo', '110102')->sole()->excluido());
        $this->assertSame(['110101'], CentroCusto::selecionaveis()->pluck('ctt_custo')->all()); // excluído/bloqueado não aparecem

        $de_novo = $importador->importar([['CTT_FILIAL' => '01', 'CTT_CUSTO' => '110101', 'CTT_DESC01' => 'INJECAO PLASTICA', 'R_E_C_N_O_' => 15]]);
        $this->assertSame(['novos' => 0, 'atualizados' => 1, 'ignorados' => 0], $de_novo);
        $this->assertSame('INJECAO PLASTICA', $cc->refresh()->ctt_desc01);
        $this->assertSame(2, CentroCusto::count());
    }
}
