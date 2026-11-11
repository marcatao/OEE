<?php

namespace Tests\Feature;

use App\Models\ApontamentoEstado;
use App\Models\Grupo;
use App\Models\Legenda;
use App\Models\Linha;
use App\Models\Maquina;
use App\Models\Planta;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TimelineTest extends TestCase
{
    use RefreshDatabase;

    private Maquina $maquina;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-05 15:00', 'UTC')); // 12:00 em São Paulo
        config(['oee.fuso_exibicao' => 'America/Sao_Paulo', 'oee.timeline_inicio_dia' => '06:00']);
        $planta = Planta::create(['codigo' => 'MATRIZ', 'nome' => 'Planta Matriz']);
        $linha = Linha::create(['planta_id' => $planta->id, 'codigo' => 'L1', 'nome' => 'Linha 1']);
        $this->maquina = Maquina::create(['linha_id' => $linha->id, 'codigo' => 'INJ-01', 'nome' => 'Injetora 01']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** $perfil: perfil antigo (admin, operador) ou um perfil novo (ex.: mantenedor_producao). */
    private function usuario(string $perfil, array $permissoes = []): Usuario
    {
        $novo = array_key_exists($perfil, Usuario::perfisDisponiveis()) && $perfil !== 'operador';
        $u = Usuario::create(['entra_oid' => 'oid-'.$perfil.count($permissoes), 'nome' => 'Fulano', 'email' => "{$perfil}@empresa.com",
            'perfil' => $novo ? 'operador' : $perfil]);
        if ($novo) {
            $u->definirPerfis([$perfil]);
        }
        if ($permissoes) {
            $grupo = Grupo::create(['nome' => 'Grupo '.$perfil, 'ativo' => true]);
            $grupo->definirPermissoes($permissoes);
            $grupo->usuarios()->attach($u->id);
        }

        return $u;
    }

    private function legenda(string $codigo): Legenda
    {
        return Legenda::where('codigo', $codigo)->sole();
    }

    public function test_legendas_padrao_ja_vem_carregadas_com_cor_e_tipo(): void
    {
        $this->assertSame(10, Legenda::count());
        $this->assertSame(['produzindo', '#43a047'], [$this->legenda('PROD')->tipo, $this->legenda('PROD')->cor]);
        $this->assertSame('parada', $this->legenda('DT')->tipo);
        $this->assertSame('desligada', $this->legenda('OFF')->tipo);
        $this->assertSame('tl-texto-escuro', $this->legenda('REF')->classeTexto()); // amarelo: texto escuro
        $this->assertSame('tl-texto-claro', $this->legenda('DT')->classeTexto());
    }

    public function test_admin_cadastra_legenda_com_cor(): void
    {
        $this->actingAs($this->usuario('admin'));
        $this->get('/admin/legendas')->assertOk()->assertSee('Parada não planejada')->assertSee('#e53935');
        $this->get('/admin/legendas/nova')->assertOk()->assertSee('type="color"', false);

        $this->post('/admin/legendas', ['codigo' => 'lim', 'descricao' => 'Limpeza', 'tipo' => 'planejada', 'cor' => '#00ACC1', 'ordem' => 5, 'ativo' => '1'])
            ->assertRedirect('/admin/legendas');
        $nova = $this->legenda('LIM');
        $this->assertSame('#00acc1', $nova->cor);

        $this->post('/admin/legendas', ['codigo' => 'X', 'descricao' => 'X', 'tipo' => 'parada', 'cor' => 'vermelho', 'ativo' => '1'])
            ->assertSessionHasErrors('cor');
        $this->post('/admin/legendas', ['codigo' => 'dt', 'descricao' => 'Outra', 'tipo' => 'parada', 'cor' => '#000000', 'ativo' => '1'])
            ->assertSessionHasErrors('codigo');

        $this->put("/admin/legendas/{$nova->id}", ['codigo' => 'LIM', 'descricao' => 'Limpeza', 'tipo' => 'planejada', 'cor' => '#123456', 'ativo' => '0'])
            ->assertRedirect();
        $this->assertSame(['#123456', false], [$nova->refresh()->cor, $nova->ativo]);
    }

    public function test_quem_nao_e_admin_nao_altera_legendas(): void
    {
        $this->actingAs($this->usuario('operador', ['timeline', 'apontamentos']));
        $this->get('/admin/legendas')->assertForbidden();
        $this->post('/admin/legendas', [])->assertForbidden();
    }

    public function test_apontamentos_encerram_o_estado_anterior_e_recusam_sobreposicao(): void
    {
        $this->actingAs($this->usuario('operador', ['apontamentos', 'timeline']));

        $this->post('/producao/apontamentos', ['maquina_id' => $this->maquina->id, 'legenda_id' => $this->legenda('PROD')->id,
            'inicio' => '2026-11-05T08:00', 'referencia' => 'OP 509859'])->assertRedirect('/producao/apontamentos');
        $producao = ApontamentoEstado::sole();
        $this->assertSame('2026-11-05 11:00:00', $producao->inicio->format('Y-m-d H:i:s')); // gravado em UTC
        $this->assertNull($producao->fim);

        $this->post('/producao/apontamentos', ['maquina_id' => $this->maquina->id, 'legenda_id' => $this->legenda('DT')->id,
            'inicio' => '2026-11-05T10:00', 'voltar' => 'timeline'])->assertRedirect('/dashboards/timeline?data=2026-11-05');
        $this->assertSame('2026-11-05 13:00:00', $producao->refresh()->fim->format('Y-m-d H:i:s'));

        $this->post('/producao/apontamentos', ['maquina_id' => $this->maquina->id, 'legenda_id' => $this->legenda('OFF')->id,
            'inicio' => '2026-11-05T09:00', 'fim' => '2026-11-05T09:30'])->assertSessionHasErrors('inicio');
        $this->post('/producao/apontamentos', ['maquina_id' => $this->maquina->id, 'legenda_id' => $this->legenda('OFF')->id,
            'inicio' => '2026-11-05T07:00', 'fim' => '2026-11-05T06:00'])->assertSessionHasErrors('fim');
        $this->assertSame(2, ApontamentoEstado::count());

        $this->get('/producao/apontamentos')->assertOk()->assertSee('OP 509859')->assertSee('em andamento');
        $this->get("/producao/apontamentos/{$producao->id}/editar")->assertOk()->assertSee('2026-11-05T08:00');
    }

    public function test_timeline_pinta_as_maquinas_com_a_cor_da_legenda(): void
    {
        $prod = $this->legenda('PROD');
        $dt = $this->legenda('DT');
        // 08:00–10:00 produzindo e 10:00–agora (12:00) parada: disponibilidade 50%
        ApontamentoEstado::create(['maquina_id' => $this->maquina->id, 'legenda_id' => $prod->id, 'referencia' => 'OP 509859',
            'inicio' => Carbon::parse('2026-11-05 11:00', 'UTC'), 'fim' => Carbon::parse('2026-11-05 13:00', 'UTC')]);
        ApontamentoEstado::create(['maquina_id' => $this->maquina->id, 'legenda_id' => $dt->id,
            'inicio' => Carbon::parse('2026-11-05 13:00', 'UTC')]);

        $this->actingAs($this->usuario('mantenedor_producao'));
        $this->get('/dashboards/timeline')->assertOk()
            ->assertSee('Injetora 01')->assertSee('OP 509859')
            ->assertSee('background-color: #43a047', false)->assertSee('background-color: #e53935', false)
            ->assertSee('50,0%')->assertSee('06:00')->assertSee('05:00')
            ->assertDontSee('/producao/apontamentos/'); // sem permissão de apontar, a barra não vira link

        $this->get('/dashboards/timeline?data=2026-11-04')->assertOk()->assertSee('Sem apontamentos neste período');
    }

    public function test_permissoes_da_timeline_e_dos_apontamentos(): void
    {
        // Operador: aponta, mas não vê a timeline (a não ser que um grupo dê).
        $this->actingAs($this->usuario('operador'));
        $this->get('/dashboards/timeline')->assertForbidden();
        $this->get('/producao/apontamentos')->assertOk();

        // Mantenedor de produção: vê os dashboards, mas não aponta.
        $this->actingAs($this->usuario('mantenedor_producao'));
        $this->get('/dashboards/timeline')->assertOk();
        $this->get('/producao/apontamentos')->assertForbidden();
        $this->post('/producao/apontamentos', [])->assertForbidden();
    }
}
