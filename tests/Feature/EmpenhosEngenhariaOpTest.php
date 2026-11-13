<?php

namespace Tests\Feature;

use App\Models\EmpenhoOp;
use App\Models\OrdemProducao;
use App\Models\Usuario;
use App\Services\EngenhariaOrdemProducao;
use App\Services\Protheus\ImportadorEmpenhos;
use App\Services\Protheus\ImportadorEstruturas;
use App\Services\Protheus\ImportadorFerramentas;
use App\Services\Protheus\ImportadorOrdensProducao;
use App\Services\Protheus\ImportadorProdutos;
use App\Services\Protheus\ImportadorRoteiros;
use App\Services\Protheus\SincronizadorProtheus;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Empenhos (SD4) e, na tela da OP, empenhos, estrutura atual (SG1), roteiro (SG2) e ferramenta/molde (SH4). */
class EmpenhosEngenhariaOpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-05 15:00', 'UTC'));
        config(['database.connections.protheus' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('protheus');
        $numericos = ['R_E_C_N_O_', 'C2_QUANT', 'C2_QUJE', 'C2_PERDA', 'D4_QTDEORI', 'D4_QUANT', 'G1_QUANT', 'G1_PERDA', 'H4_QUANT',
            'H4_VIDAUTI', 'H4_QTDCAV', 'G2_SETUP', 'G2_TEMPAD', 'G2_LOTEPAD', 'G2_MAOOBRA'];
        foreach (['SB1' => ImportadorProdutos::class, 'SC2' => ImportadorOrdensProducao::class, 'SD4' => ImportadorEmpenhos::class,
            'SG1' => ImportadorEstruturas::class, 'SG2' => ImportadorRoteiros::class, 'SH4' => ImportadorFerramentas::class] as $tabela => $classe) {
            Schema::connection('protheus')->create($tabela, function (Blueprint $t) use ($classe, $numericos) {
                foreach (app($classe)->campos() as $c) {
                    in_array($c, $numericos, true) ? $t->float($c)->default(0) : $t->string($c)->default('');
                }
            });
        }
        // SB9 e SB2 (custo meta e custo médio, lidos na sincronização das OPs): vazias aqui.
        foreach (['SB9' => ['B9_FILIAL', 'B9_COD', 'B9_LOCAL', 'B9_DATA', 'B9_QINI', 'B9_VINI1', 'B9_CM1', 'D_E_L_E_T_', 'R_E_C_N_O_'],
            'SB2' => ['B2_FILIAL', 'B2_COD', 'B2_LOCAL', 'B2_QATU', 'B2_VATU1', 'B2_CM1', 'D_E_L_E_T_', 'R_E_C_N_O_']] as $tabela => $campos) {
            Schema::connection('protheus')->create($tabela, fn (Blueprint $t) => array_map(fn ($c) => $t->string($c)->default(''), $campos));
        }
        $p = DB::connection('protheus');
        foreach ([['PA001', 'TAMPA 50MM', 'PA'], ['MP001', 'RESINA PP', 'MP'], ['PI001', 'MASTER AZUL', 'PI'], ['MP002', 'PIGMENTO', 'MP'], ['MP003', 'ANTIGO', 'MP']] as $i => [$c, $d, $tp]) {
            $p->table('SB1')->insert(['B1_COD' => $c, 'B1_DESC' => $d, 'B1_TIPO' => $tp, 'B1_UM' => 'KG', 'B1_MSBLQL' => '2', 'D_E_L_E_T_' => ' ', 'R_E_C_N_O_' => $i + 1]);
        }
        $p->table('SC2')->insert(['C2_FILIAL' => '01', 'C2_NUM' => '509859', 'C2_ITEM' => '01', 'C2_SEQUEN' => '001', 'C2_PRODUTO' => 'PA001',
            'C2_UM' => 'UN', 'C2_QUANT' => 1000, 'C2_EMISSAO' => '20261101', 'C2_ROTEIRO' => '02', 'D_E_L_E_T_' => ' ', 'R_E_C_N_O_' => 1]);
        // Empenhos: D4_OP com o item de grade; um excluído; um de outra OP.
        foreach ([[1, 'MP001', '001', 2.5, 1.0, ' ', '50985901001   '], [2, 'PI001', '002', 0.1, 0.1, ' ', '50985901001'],
            [3, 'MP002', '003', 9, 9, '*', '50985901001'], [4, 'MP001', '001', 7, 7, ' ', '50999901001']] as [$r, $cod, $trt, $ori, $saldo, $del, $op]) {
            $p->table('SD4')->insert(['D4_FILIAL' => '01', 'D4_COD' => $cod, 'D4_OP' => $op, 'D4_LOCAL' => '01', 'D4_DATA' => '20261101',
                'D4_QTDEORI' => $ori, 'D4_QUANT' => $saldo, 'D4_TRT' => $trt, 'D_E_L_E_T_' => $del, 'R_E_C_N_O_' => $r]);
        }
        // Estrutura: 2 vigentes, 1 vencida; fixa no PI001; o PI001 tem estrutura própria.
        foreach ([[1, 'PA001', 'MP001', '001', 0.0025, '', '', 'V'], [2, 'PA001', 'PI001', '002', 0.1, '', '', 'F'],
            [3, 'PA001', 'MP003', '004', 1, '20200101', '20251231', 'V'], [4, 'PI001', 'MP002', '001', 0.02, '', '', 'V']] as [$r, $cod, $comp, $trt, $q, $ini, $fim, $fv]) {
            $p->table('SG1')->insert(['G1_COD' => $cod, 'G1_COMP' => $comp, 'G1_TRT' => $trt, 'G1_QUANT' => $q, 'G1_INI' => $ini, 'G1_FIM' => $fim,
                'G1_FIXVAR' => $fv, 'D_E_L_E_T_' => ' ', 'R_E_C_N_O_' => $r]);
        }
        // Roteiros 01 e 02 do produto: a OP usa o 02 (C2_ROTEIRO), com o molde MOLD02.
        foreach ([[1, '01', '10', 'INJETAR', 'MOLD01'], [2, '02', '10', 'INJETAR NA 02', 'MOLD02'], [3, '02', '20', 'EMBALAR', '']] as [$r, $cod, $oper, $desc, $ferr]) {
            $p->table('SG2')->insert(['G2_PRODUTO' => 'PA001', 'G2_CODIGO' => $cod, 'G2_OPERAC' => $oper, 'G2_DESCRI' => $desc, 'G2_CTRAB' => 'INJ02',
                'G2_FERRAM' => $ferr, 'G2_TEMPAD' => 12, 'D_E_L_E_T_' => ' ', 'R_E_C_N_O_' => $r]);
        }
        foreach ([[1, 'MOLD01', 'MOLDE TAMPA 4 CAV', 4], [2, 'MOLD02', 'MOLDE TAMPA 8 CAV', 8]] as [$r, $cod, $desc, $cav]) {
            $p->table('SH4')->insert(['H4_CODIGO' => $cod, 'H4_DESCRI' => $desc, 'H4_QTDCAV' => $cav, 'H4_QUANT' => 1, 'D_E_L_E_T_' => ' ', 'R_E_C_N_O_' => $r]);
        }
        foreach (['produtos', 'estruturas', 'ferramentas', 'roteiros', 'ordens_producao', 'empenhos_op'] as $chave) {
            $r = app(SincronizadorProtheus::class)->sincronizar($chave, 'comando');
            $this->assertSame('sucesso', $r->situacao, $chave.': '.$r->mensagem);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): Usuario
    {
        return Usuario::create(['entra_oid' => 'adm', 'nome' => 'Admin', 'email' => 'adm@empresa.com', 'perfil' => 'admin']);
    }

    public function test_sincroniza_a_sd4_e_relaciona_com_a_op(): void
    {
        $this->assertSame(3, EmpenhoOp::count()); // o excluído que nunca veio não entra
        $this->assertSame(['50985901001', '50985901001', '50999901001'], EmpenhoOp::orderBy('r_e_c_n_o_')->pluck('op_numero')->all());
        $op = OrdemProducao::sole();
        $this->assertSame(['02', ''], [$op->c2_roteiro, $op->c2_revisao]);

        $eng = app(EngenhariaOrdemProducao::class);
        $empenhos = $eng->empenhos($op);
        $this->assertSame(['MP001', 'PI001'], $empenhos->pluck('d4_cod')->all());
        $this->assertEqualsWithDelta(1.5, $empenhos->first()->baixado(), 0.0001);

        // Estrutura vigente: MP001 (variável: 0,0025 × 1000 = 2,5) e PI001 (fixa: 0,1); MP003 vencida fica fora.
        $estrutura = $eng->estrutura($op);
        $this->assertSame(['MP001', 'PI001'], $estrutura->pluck('g1_comp')->all());
        $this->assertEqualsWithDelta(2.5, $estrutura[0]->necessidade, 0.0001);
        $this->assertEqualsWithDelta(0.1, $estrutura[1]->necessidade, 0.0001);
        $this->assertEqualsWithDelta(2.5, $estrutura[0]->empenhado, 0.0001);
        $this->assertTrue($estrutura[1]->tem_estrutura);

        // Roteiro 02 (da OP), com o molde MOLD02; o 01 aparece como outro roteiro do produto.
        $roteiro = $eng->roteiro($op);
        $this->assertSame(['02', true, ['01']], [$roteiro['codigo'], $roteiro['informado'], $roteiro['outros']]);
        $this->assertSame(['10', '20'], $roteiro['operacoes']->pluck('g2_operac')->all());
        $this->assertSame(['MOLD02'], $roteiro['ferramentas']->keys()->all());

        // Sem roteiro na OP: usa o 01.
        $op->c2_roteiro = '';
        $this->assertSame('01', $eng->roteiro($op)['codigo']);
    }

    public function test_tela_da_op_e_tela_de_empenhos(): void
    {
        $this->actingAs($this->admin());
        $op = OrdemProducao::sole();
        $this->get("/producao/ordens-de-producao/{$op->id}")->assertOk()
            ->assertSeeInOrder(['Kardex da OP', 'Empenhos da OP', 'Estrutura atual do produto', 'Roteiro de operações', 'Ferramenta / molde da OP'])
            ->assertSee('MOLDE TAMPA 8 CAV')->assertSee('INJETAR NA 02')->assertDontSee('MOLDE TAMPA 4 CAV')
            ->assertSee('RESINA PP')->assertSee('tem estrutura própria')->assertDontSee('ANTIGO');

        $this->get('/')->assertSee('Empenhos OP');
        $this->get('/producao/empenhos-op')->assertOk()->assertSee('50985901001')->assertSee('RESINA PP')
            ->assertDontSee('50999901001'); // OP que não está no portal fica fora com "só OPs não fechadas"
        $this->get('/producao/empenhos-op?abertas=0')->assertSee('50999901001');
        $this->get('/producao/empenhos-op?abertas=0&busca=PI001')->assertSee('MASTER AZUL')->assertDontSee('RESINA PP');

        // Operador sem a permissão não vê a tela.
        $op = Usuario::create(['entra_oid' => 'op', 'nome' => 'Op', 'email' => 'op@empresa.com', 'perfil' => 'operador']);
        $this->actingAs($op)->get('/producao/empenhos-op')->assertForbidden();
    }
}
