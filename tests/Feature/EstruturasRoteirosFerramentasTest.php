<?php

namespace Tests\Feature;

use App\Models\EstruturaProduto;
use App\Models\Ferramenta;
use App\Models\Grupo;
use App\Models\RoteiroOperacao;
use App\Models\Usuario;
use App\Services\Protheus\ImportadorEstruturas;
use App\Services\Protheus\ImportadorFerramentas;
use App\Services\Protheus\ImportadorProdutos;
use App\Services\Protheus\ImportadorRoteiros;
use App\Services\Protheus\SincronizadorProtheus;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * SG1 (estruturas), SG2 (roteiros) e SH4 (ferramentas, mista) do Protheus. O "Protheus" é um SQLite em memória.
 */
class EstruturasRoteirosFerramentasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.protheus' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('protheus');
        $numericos = ['R_E_C_N_O_', 'G1_QUANT', 'G1_PERDA', 'G2_SETUP', 'G2_TEMPAD', 'G2_LOTEPAD', 'G2_MAOOBRA', 'H4_QUANT', 'H4_VIDAUTI', 'H4_QTDCAV'];
        foreach (['SB1' => ImportadorProdutos::CAMPOS, 'SG1' => ImportadorEstruturas::CAMPOS, 'SG2' => ImportadorRoteiros::CAMPOS,
            'SH4' => [...ImportadorFerramentas::CAMPOS, 'H4_QTDCAV']] as $tabela => $campos) {
            Schema::connection('protheus')->create($tabela, function (Blueprint $t) use ($campos, $numericos) {
                foreach ($campos as $c) {
                    in_array($c, $numericos, true) ? $t->float($c)->default(0) : $t->string($c)->default('');
                }
            });
        }
        $p = DB::connection('protheus');
        foreach ([['PA001', 'TAMPA 28MM'], ['PI001', 'PRE-FORMA'], ['MP001', 'RESINA PP']] as $i => [$c, $d]) {
            $p->table('SB1')->insert(['B1_COD' => $c, 'B1_DESC' => $d, 'B1_UM' => 'UN', 'B1_MSBLQL' => '2', 'D_E_L_E_T_' => ' ', 'R_E_C_N_O_' => $i + 1]);
        }
        $g1 = fn ($cod, $comp, $q, $rec, $extra = []) => $p->table('SG1')->insert($extra + ['G1_FILIAL' => '01', 'G1_COD' => $cod,
            'G1_COMP' => $comp, 'G1_TRT' => '001', 'G1_QUANT' => $q, 'G1_PERDA' => 2, 'G1_INI' => '20200101', 'G1_FIM' => '20491231',
            'G1_FIXVAR' => 'V', 'D_E_L_E_T_' => ' ', 'R_E_C_N_O_' => $rec]);
        $g1('PA001', 'PI001', 1, 1);
        $g1('PI001', 'MP001', 0.0025, 2);
        $g1('PA001', 'MP001', 9, 3, ['G1_TRT' => '002', 'G1_FIM' => '20201231']); // vencida
        $p->table('SG2')->insert(['G2_FILIAL' => '01', 'G2_PRODUTO' => 'PA001', 'G2_CODIGO' => '01', 'G2_OPERAC' => '10',
            'G2_DESCRI' => 'INJECAO', 'G2_RECURSO' => 'R01', 'G2_CTRAB' => 'INJ01', 'G2_FERRAM' => 'MOLD01', 'G2_TPOPER' => '1',
            'G2_SETUP' => 0.5, 'G2_TEMPAD' => 0.02, 'G2_LOTEPAD' => 1, 'D_E_L_E_T_' => ' ', 'R_E_C_N_O_' => 1]);
        $p->table('SH4')->insert(['H4_FILIAL' => '01', 'H4_CODIGO' => 'MOLD01', 'H4_DESCRI' => 'MOLDE TAMPA 28MM 8 CAV',
            'H4_QUANT' => 1, 'H4_VIDAUTI' => 500000, 'H4_QTDCAV' => 8, 'D_E_L_E_T_' => ' ', 'R_E_C_N_O_' => 1]);
    }

    private function sincronizar(string $chave)
    {
        return app(SincronizadorProtheus::class)->sincronizar($chave, 'comando');
    }

    private function usuario(array $perfis, string $email = 'u@empresa.com'): Usuario
    {
        $u = Usuario::create(['entra_oid' => "oid-{$email}", 'nome' => 'Fulano', 'email' => $email]);
        $u->definirPerfis($perfis);

        return $u->fresh();
    }

    public function test_sincroniza_estruturas_roteiros_e_ferramentas(): void
    {
        foreach (['produtos', 'estruturas', 'ferramentas', 'roteiros'] as $chave) {
            $this->assertSame('sucesso', $this->sincronizar($chave)->situacao, $chave);
        }
        $this->assertSame(3, EstruturaProduto::count());
        $this->assertEqualsWithDelta(0.0025, EstruturaProduto::where('g1_cod', 'PI001')->value('g1_quant'), 0.000001);
        $this->assertSame('MOLD01', RoteiroOperacao::value('g2_ferram'));

        // Ferramenta nova: cavidades ativas = cavidades do Protheus; unidade padrão.
        $f = Ferramenta::sole();
        $this->assertSame([8, 8, 'manutencao'], [$f->h4_qtdcav, $f->h4_qtdcavativas, $f->unidade]);
    }

    public function test_sincronizacao_nao_sobrepoe_as_cavidades_ativas(): void
    {
        $this->sincronizar('ferramentas');
        Ferramenta::query()->update(['h4_qtdcavativas' => 6, 'unidade' => 'ambos', 'disponivel' => false]);

        // No Protheus muda a descrição e as cavidades: o portal atualiza os campos h4_*, mas não as ativas nem a unidade.
        DB::connection('protheus')->table('SH4')->update(['H4_DESCRI' => 'MOLDE NOVO', 'H4_QTDCAV' => 4]);
        $r = $this->sincronizar('ferramentas');

        $f = Ferramenta::sole();
        $this->assertSame([1, 'MOLDE NOVO', 4, 6, 'ambos'], [$r->atualizados, $f->h4_descri, $f->h4_qtdcav, $f->h4_qtdcavativas, $f->unidade]);
        $this->assertTrue($f->cavidadesAcima()); // ficou acima: a tela avisa para ajustar
    }

    public function test_telas_e_edicao_das_cavidades_ativas(): void
    {
        foreach (['produtos', 'estruturas', 'ferramentas', 'roteiros'] as $chave) {
            $this->sincronizar($chave);
        }
        $f = Ferramenta::sole();

        // Operador não vê (sem permissão).
        $this->actingAs($this->usuario(['operador'], 'op@empresa.com'));
        foreach (['/producao/estruturas', '/producao/roteiros', '/producao/ferramentas'] as $url) {
            $this->get($url)->assertForbidden();
        }

        $this->actingAs($this->usuario(['admin_producao'], 'adm@empresa.com'));
        $this->get('/')->assertSee('Estruturas de produto')->assertSee('Roteiros de operação')->assertSee('Ferramentas');
        $this->get('/producao/estruturas')->assertOk()->assertSee('PRE-FORMA')->assertSee('RESINA PP')
            ->assertDontSee('20/12/2020'); // vencida fica fora das vigentes
        $this->get('/producao/estruturas?validade=todas')->assertSee('31/12/2020');
        $this->get('/producao/estruturas?produto=PI001')->assertSee('RESINA PP')->assertDontSee('TAMPA 28MM'); // só o nível do PI001
        // Quantidade com as casas decimais do Protheus (G1_QUANT 0.0025 não vira "0,00").
        $this->get('/producao/estruturas?produto=PI001')->assertSee('0,0025')->assertDontSee('>0,00 <', false);
        EstruturaProduto::where('g1_cod', 'PI001')->update(['g1_quant' => 0.000604]);
        $this->get('/producao/estruturas?produto=PI001')->assertSee('0,000604');
        $this->get('/producao/roteiros')->assertOk()->assertSee('INJECAO')->assertSee('MOLD01')->assertSee('8/8 cavidades');
        $this->get('/producao/ferramentas')->assertOk()->assertSee('MOLDE TAMPA 28MM 8 CAV')->assertSee('Editar');

        // Edição: cavidades ativas de 0 até as cavidades da ferramenta.
        $this->get("/producao/ferramentas/{$f->id}/editar")->assertOk()->assertSee('INJECAO');
        $this->put("/producao/ferramentas/{$f->id}", ['h4_qtdcavativas' => 9])->assertSessionHasErrors('h4_qtdcavativas');
        $this->put("/producao/ferramentas/{$f->id}", ['h4_qtdcavativas' => 7])->assertRedirect('/producao/ferramentas')->assertSessionHas('ok');
        $this->assertSame([7, 8, 'manutencao'], [$f->fresh()->h4_qtdcavativas, $f->fresh()->h4_qtdcav, $f->fresh()->unidade]);
        $this->get('/producao/ferramentas')->assertSee('1 desativada(s)');
    }

    public function test_so_administrador_edita_ferramenta(): void
    {
        $this->sincronizar('ferramentas');
        $f = Ferramenta::sole();
        $u = $this->usuario(['mantenedor_producao'], 'm@empresa.com');
        $grupo = Grupo::create(['nome' => 'Ferr', 'ativo' => true]);
        $grupo->definirPermissoes(['ferramentas']);
        $grupo->usuarios()->attach($u->id);

        $this->actingAs($u)->get('/producao/ferramentas')->assertOk()->assertDontSee('>Editar<', false);
        $this->get("/producao/ferramentas/{$f->id}/editar")->assertForbidden();
        $this->put("/producao/ferramentas/{$f->id}", ['h4_qtdcavativas' => 1])->assertForbidden();
    }

    public function test_ferramenta_disponivel_editavel_e_filtro_de_disponiveis(): void
    {
        $this->sincronizar('ferramentas');
        $f = Ferramenta::sole();
        $this->assertTrue($f->disponivel);                                       // nasce disponível

        $this->actingAs($this->usuario(['admin_producao'], 'adm@empresa.com'));
        $this->get('/producao/ferramentas?disponivel=0')->assertOk()->assertDontSee($f->h4_descri)->assertSee('Indisponíveis (0)');
        $this->put("/producao/ferramentas/{$f->id}", ['h4_qtdcavativas' => 8, 'disponivel' => '0'])->assertRedirect('/producao/ferramentas');
        $this->assertFalse($f->fresh()->disponivel);
        $this->get('/producao/ferramentas?disponivel=0')->assertSee($f->h4_descri)->assertSee('Indisponíveis (1)');
        $this->get('/producao/ferramentas?disponivel=1')->assertDontSee($f->h4_descri);

        // Sem o campo (aba Ferramenta da OP só manda as cavidades): continua como está.
        $this->put("/producao/ferramentas/{$f->id}", ['h4_qtdcavativas' => 7])->assertRedirect();
        $this->assertFalse($f->fresh()->disponivel);
        // A sincronização não muda a disponibilidade.
        DB::connection('protheus')->table('SH4')->update(['H4_DESCRI' => 'MOLDE RENOVADO']);
        $this->sincronizar('ferramentas');
        $this->assertSame(['MOLDE RENOVADO', false], [$f->fresh()->h4_descri, $f->fresh()->disponivel]);
    }
}
