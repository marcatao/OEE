<?php

namespace Tests\Feature;

use App\Models\CentroCusto;
use App\Models\Sincronizacao;
use App\Models\Usuario;
use App\Services\Protheus\ImportadorMovimentosProducao;
use App\Services\Protheus\ImportadorNotasFiscaisOp;
use App\Services\Protheus\ImportadorOrdensProducao;
use App\Services\Protheus\ImportadorProdutos;
use App\Services\Protheus\SincronizadorProtheus;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Sincronização com o Protheus. O "Protheus" dos testes é um SQLite em memória separado, com uma tabela CTT
 * igual à do banco Compras.
 */
class SincronizacaoProtheusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.protheus' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('protheus');
        Schema::connection('protheus')->create('CTT', function (Blueprint $t) {
            foreach (['CTT_FILIAL', 'CTT_CUSTO', 'CTT_DESC01', 'CTT_CLASSE', 'CTT_BLOQ', 'CTT_CCSUP', 'CTT_DTEXIS', 'CTT_DTEXSF', 'D_E_L_E_T_'] as $c) {
                $t->string($c)->default('');
            }
            $t->integer('R_E_C_N_O_');
        });
        // SHB (máquinas) vazia: estes testes tratam dos centros de custo; as máquinas têm teste próprio.
        Schema::connection('protheus')->create('SHB', function (Blueprint $t) {
            foreach (['HB_FILIAL', 'HB_COD', 'HB_NOME', 'HB_CC', 'D_E_L_E_T_'] as $c) {
                $t->string($c)->default('');
            }
            $t->integer('R_E_C_N_O_');
        });
        // SB1, SC2, SD3 e SD2 vazias (produtos, OPs e movimentos têm teste próprio).
        foreach (['SB1' => ImportadorProdutos::CAMPOS, 'SC2' => ImportadorOrdensProducao::CAMPOS,
            'SD3' => [...ImportadorMovimentosProducao::CAMPOS, 'D3_ORDEM'], 'SD2' => [...ImportadorNotasFiscaisOp::CAMPOS, 'D2_OP']] as $tabela => $campos) {
            Schema::connection('protheus')->create($tabela, function (Blueprint $t) use ($campos) {
                foreach ($campos as $c) {
                    $c === 'R_E_C_N_O_' ? $t->integer($c) : $t->string($c)->default('');
                }
            });
        }
        $this->ctt('01', '1001      ', 'USINAGEM                                ', 1);
        $this->ctt('01', '1002      ', 'MONTAGEM                                ', 2);
        $this->ctt('01', '          ', 'SEM CODIGO                              ', 3);
    }

    private function ctt(string $filial, string $custo, string $desc, int $recno, string $deletado = ' '): void
    {
        DB::connection('protheus')->table('CTT')->insert([
            'CTT_FILIAL' => $filial, 'CTT_CUSTO' => $custo, 'CTT_DESC01' => $desc, 'CTT_CLASSE' => '2', 'CTT_BLOQ' => '2',
            'CTT_CCSUP' => '', 'CTT_DTEXIS' => '20200101', 'CTT_DTEXSF' => '', 'D_E_L_E_T_' => $deletado, 'R_E_C_N_O_' => $recno,
        ]);
    }

    private function admin(): Usuario
    {
        return Usuario::create(['entra_oid' => 'oid-admin', 'nome' => 'Admin', 'email' => 'admin@empresa.com', 'perfil' => 'admin']);
    }

    public function test_botao_sincroniza_centros_de_custo_e_grava_o_historico(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/banco/sincronizar')
            ->assertRedirect('/admin/banco')
            ->assertSessionHas('ok', fn ($m) => str_contains($m, '3 lidos no Protheus, 2 novos'));

        $this->assertSame(['1001', '1002'], CentroCusto::orderBy('ctt_custo')->pluck('ctt_custo')->all());
        $this->assertSame('USINAGEM', rtrim(CentroCusto::where('ctt_custo', '1001')->value('ctt_desc01')));

        $this->assertSame(['centros_custo', 'maquinas', 'produtos', 'ordens_producao', 'movimentos_producao', 'notas_fiscais_op'],
            Sincronizacao::orderBy('id')->pluck('chave')->all());
        $s = Sincronizacao::where('chave', 'centros_custo')->sole();
        $this->assertSame(['centros_custo', 'manual', 'sucesso', 3, 2, 0, 1, $admin->id],
            [$s->chave, $s->disparo, $s->situacao, $s->lidos, $s->novos, $s->atualizados, $s->ignorados, $s->usuario_id]);
        $this->assertNotNull($s->terminado_em);
    }

    public function test_segunda_sincronizacao_so_grava_o_que_mudou(): void
    {
        $sincronizador = app(SincronizadorProtheus::class);
        $sincronizador->sincronizar('centros_custo', 'comando');

        DB::connection('protheus')->table('CTT')->where('R_E_C_N_O_', 2)->update(['CTT_DESC01' => 'MONTAGEM FINAL']);
        $this->ctt('01', '1003', 'PINTURA', 4);

        $r = $sincronizador->sincronizar('centros_custo', 'comando');
        $this->assertSame([1, 1], [$r->novos, $r->atualizados]);
        $this->assertSame('MONTAGEM FINAL', CentroCusto::where('ctt_custo', '1002')->value('ctt_desc01'));
    }

    public function test_falha_no_protheus_fica_registrada_e_aparece_na_tela(): void
    {
        Schema::connection('protheus')->drop('CTT');

        $this->actingAs($this->admin())->post('/admin/banco/sincronizar')
            ->assertSessionHas('erro', fn ($m) => str_contains($m, 'Centros de custo: falhou'));

        $s = Sincronizacao::where('chave', 'centros_custo')->sole();
        $this->assertSame('erro', $s->situacao);
        $this->assertNotEmpty($s->mensagem);
        $this->get('/admin/banco')->assertOk()->assertSee('Erro');
    }

    public function test_nao_roda_duas_sincronizacoes_ao_mesmo_tempo(): void
    {
        // Uma em andamento, com sinal de vida recente.
        Sincronizacao::create(['chave' => 'centros_custo', 'origem' => 'Compras.CTT', 'disparo' => 'manual',
            'iniciado_em' => now()->subMinutes(5), 'sinal_em' => now()->subSeconds(20), 'situacao' => 'executando', 'lidos' => 3000]);

        $r = app(SincronizadorProtheus::class)->sincronizar('centros_custo', 'agendado');
        $this->assertSame('erro', $r->situacao);
        $this->assertStringContainsString('em andamento', $r->mensagem);
        $this->assertStringContainsString('3000 linhas lidas', $r->mensagem);
        $this->assertSame(0, CentroCusto::count());
    }

    public function test_sincronizacao_que_morreu_nao_trava_a_proxima(): void
    {
        // O processo anterior morreu (ex.: falta de memória): ficou "executando", sem sinal há 4 minutos,
        // e a trava antiga de cache ficou presa.
        $morta = Sincronizacao::create(['chave' => 'centros_custo', 'origem' => 'Compras.CTT', 'disparo' => 'manual',
            'iniciado_em' => now()->subMinutes(6), 'sinal_em' => now()->subMinutes(4), 'situacao' => 'executando']);
        Cache::lock('protheus-sincronizar-centros_custo', 600)->get();

        $r = app(SincronizadorProtheus::class)->sincronizar('centros_custo', 'manual');

        $this->assertSame('sucesso', $r->situacao);
        $this->assertSame(2, CentroCusto::count());
        $this->assertSame('erro', $morta->fresh()->situacao);
        $this->assertStringContainsString('Interrompida', $morta->fresh()->mensagem);
        $this->assertNotNull($r->sinal_em);
    }

    public function test_tela_mostra_tabelas_do_sistema_e_do_protheus(): void
    {
        config(['database.connections.protheus.database' => 'Compras']);
        app(SincronizadorProtheus::class)->sincronizar('centros_custo', 'comando');

        $this->actingAs($this->admin())->get('/admin/banco')->assertOk()
            ->assertSee('Integração com o Protheus')
            ->assertSee('Sincronizar com o Protheus')
            ->assertSee('Compras.CTT')
            ->assertSee('Sistema OEE')
            ->assertSee('origem-protheus', false)
            ->assertSee('Últimas sincronizações');
    }

    public function test_sincroniza_so_a_tabela_escolhida_e_rejeita_tabela_desconhecida(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/admin/banco/sincronizar', ['tabela' => 'centros_custo'])->assertSessionHas('ok');
        $this->actingAs($admin)->post('/admin/banco/sincronizar', ['tabela' => 'usuarios'])->assertSessionHasErrors('tabela');
        $this->assertSame(1, Sincronizacao::count());
    }

    public function test_so_admin_sincroniza(): void
    {
        $op = Usuario::create(['entra_oid' => 'oid-op', 'nome' => 'Op', 'email' => 'op@empresa.com', 'perfil' => 'operador']);
        $this->actingAs($op)->post('/admin/banco/sincronizar')->assertForbidden();
        $this->assertSame(0, Sincronizacao::count());
    }

    public function test_comando_sincronizar(): void
    {
        $this->artisan('protheus:sincronizar')->expectsOutputToContain('3 lidos, 2 novos')->assertSuccessful();
        $this->artisan('protheus:sincronizar centros_custo --agendado')->assertSuccessful();
        $this->artisan('protheus:sincronizar inexistente')->assertFailed();

        $this->assertSame([...array_fill(0, 6, 'comando'), 'agendado'], Sincronizacao::orderBy('id')->pluck('disparo')->all());
    }
}
