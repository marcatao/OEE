<?php

namespace Tests\Feature;

use App\Models\ApontamentoEstado;
use App\Models\FiltroUsuario;
use App\Models\Grupo;
use App\Models\Legenda;
use App\Models\Linha;
use App\Models\Maquina;
use App\Models\Planta;
use App\Models\Usuario;
use App\Services\Timeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Timeline: filtros De/Até, plantas, linhas e máquinas, atualização parcial e filtros salvos (filtros_usuarios).
 */
class TimelineFiltrosTest extends TestCase
{
    use RefreshDatabase;

    private Maquina $m1;

    private Maquina $m2;

    private Linha $linhaA;

    private Linha $linhaB;

    private Planta $plantaA;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-05 15:00', 'UTC')); // 12:00 em São Paulo
        config(['oee.fuso_exibicao' => 'America/Sao_Paulo']);

        $this->plantaA = Planta::create(['codigo' => 'A', 'nome' => 'Planta A']);
        $plantaB = Planta::create(['codigo' => 'B', 'nome' => 'Planta B']);
        $this->linhaA = Linha::create(['planta_id' => $this->plantaA->id, 'codigo' => 'LA', 'nome' => 'Linha A']);
        $this->linhaB = Linha::create(['planta_id' => $plantaB->id, 'codigo' => 'LB', 'nome' => 'Linha B']);
        $this->m1 = Maquina::create(['linha_id' => $this->linhaA->id, 'codigo' => 'INJ-01', 'nome' => 'Injetora 01', 'ativo' => true]);
        $this->m2 = Maquina::create(['linha_id' => $this->linhaB->id, 'codigo' => 'PRE-01', 'nome' => 'Prensa 01', 'ativo' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function usuario(string $email = 'op@empresa.com'): Usuario
    {
        $u = Usuario::create(['entra_oid' => 'oid-'.$email, 'nome' => 'Fulano', 'email' => $email, 'perfil' => 'operador']);
        $grupo = Grupo::firstOrCreate(['nome' => 'Timeline'], ['ativo' => true]);
        $grupo->definirPermissoes(['timeline']);
        $grupo->usuarios()->attach($u->id);

        return $u;
    }

    public function test_padrao_e_hoje_de_01h_ate_23h59_com_todas_as_maquinas(): void
    {
        $this->actingAs($this->usuario())->get('/dashboards/timeline')->assertOk()
            ->assertSee('value="2026-11-05T01:00"', false)->assertSee('value="2026-11-05T23:59"', false)
            ->assertSee('05/11/2026 01:00 até 05/11/2026 23:59')
            ->assertSee('Injetora 01')->assertSee('Prensa 01')
            ->assertSee('Ao vivo'); // o período inclui agora: a tela se atualiza sozinha
    }

    public function test_filtra_por_plantas_linhas_e_maquinas(): void
    {
        $this->actingAs($this->usuario());

        $this->get('/dashboards/timeline?plantas[]='.$this->plantaA->id)->assertSee('Injetora 01')->assertDontSee('>Prensa 01<', false);
        $this->get('/dashboards/timeline?linhas[]='.$this->linhaB->id)->assertSee('Prensa 01')->assertDontSee('>Injetora 01<', false);
        $this->get('/dashboards/timeline?maquinas[]='.$this->m1->id.'&maquinas[]='.$this->m2->id)->assertSee('2 máquinas');
        // Os filtros se somam: planta A + máquina da planta B não dá nada.
        $this->get('/dashboards/timeline?plantas[]='.$this->plantaA->id.'&maquinas[]='.$this->m2->id)
            ->assertSee('Nenhuma máquina ativa com estes filtros');
    }

    public function test_atualizacao_parcial_devolve_so_a_area_de_resultado(): void
    {
        $html = $this->actingAs($this->usuario())->get('/dashboards/timeline?parcial=1&linhas[]='.$this->linhaA->id)
            ->assertOk()->getContent();

        $this->assertStringContainsString('tl-resultado-conteudo', $html);
        $this->assertStringContainsString('Injetora 01', $html);
        $this->assertStringNotContainsString('<html', $html);
        $this->assertStringNotContainsString('tl-filtros', $html);
    }

    public function test_periodo_de_ate_com_hora_corta_os_apontamentos(): void
    {
        $prod = Legenda::where('codigo', 'PROD')->sole();
        // 08:00–10:00 (São Paulo) produzindo
        ApontamentoEstado::create(['maquina_id' => $this->m1->id, 'legenda_id' => $prod->id, 'referencia' => 'OP 1',
            'inicio' => Carbon::parse('2026-11-05 11:00', 'UTC'), 'fim' => Carbon::parse('2026-11-05 13:00', 'UTC')]);
        $this->actingAs($this->usuario());

        $this->get('/dashboards/timeline?de=2026-11-05T07:00&ate=2026-11-05T11:59')->assertSee('OP 1')->assertSee('100,0%');
        $this->get('/dashboards/timeline?de=2026-11-05T10:30&ate=2026-11-05T11:59')->assertDontSee('OP 1')
            ->assertSee('Sem apontamentos neste período');
    }

    public function test_periodo_invalido_volta_para_o_padrao_com_aviso(): void
    {
        $this->actingAs($this->usuario());
        $this->get('/dashboards/timeline?de=2026-11-05T10:00&ate=2026-11-05T09:00')
            ->assertSee('O &quot;Até&quot; precisa ser depois do &quot;De&quot;', false)->assertSee('value="2026-11-05T01:00"', false);
        $this->get('/dashboards/timeline?de=2026-09-01T00:00&ate=2026-11-05T00:00')->assertSee('Período maior que 31 dias');
    }

    public function test_link_antigo_com_data_mostra_o_dia_inteiro(): void
    {
        $this->actingAs($this->usuario())->get('/dashboards/timeline?data=2026-11-04')
            ->assertSee('04/11/2026 01:00 até 04/11/2026 23:59');
    }

    public function test_salva_filtro_do_dia_atual_como_padrao_e_aplica_ao_abrir(): void
    {
        $u = $this->usuario();
        $this->actingAs($u)->post('/dashboards/timeline/filtros', [
            'nome' => 'Só planta A', 'periodo' => 'hoje', 'padrao' => '1',
            'de' => '2026-11-05T06:00', 'ate' => '2026-11-05T14:00', 'plantas' => [$this->plantaA->id],
        ])->assertRedirect()->assertSessionHas('ok');

        $f = FiltroUsuario::sole();
        $this->assertSame([$u->id, 'timeline', 'Só planta A', true], [$f->usuario_id, $f->tela, $f->nome, $f->padrao]);
        $this->assertSame(['periodo' => 'hoje', 'hora_de' => '06:00', 'hora_ate' => '14:00', 'plantas' => [$this->plantaA->id], 'linhas' => [], 'maquinas' => []], $f->filtros);

        // No dia seguinte, ao abrir a tela sem parâmetros, aplica o padrão no dia atual.
        Carbon::setTestNow(Carbon::parse('2026-11-06 15:00', 'UTC'));
        $this->get('/dashboards/timeline')->assertSee('06/11/2026 06:00 até 06/11/2026 14:00')
            ->assertSee('Injetora 01')->assertDontSee('>Prensa 01<', false)->assertSee('Só planta A ★ padrão');

        // Com parâmetros na URL, vale a URL (não o padrão).
        $this->get('/dashboards/timeline?linhas[]='.$this->linhaB->id)->assertSee('Prensa 01');
    }

    public function test_filtro_com_datas_fixas_mesmo_nome_atualiza_e_so_um_padrao(): void
    {
        $u = $this->usuario();
        $this->actingAs($u);
        $this->post('/dashboards/timeline/filtros', ['nome' => 'Semana', 'periodo' => 'fixo', 'padrao' => '1',
            'de' => '2026-11-01T01:00', 'ate' => '2026-11-03T23:59']);
        $this->post('/dashboards/timeline/filtros', ['nome' => 'Outro', 'periodo' => 'hoje', 'padrao' => '1',
            'de' => '2026-11-05T01:00', 'ate' => '2026-11-05T23:59']);
        $this->post('/dashboards/timeline/filtros', ['nome' => 'Semana', 'periodo' => 'fixo',
            'de' => '2026-11-01T01:00', 'ate' => '2026-11-04T23:59']);

        $this->assertSame(2, FiltroUsuario::count());
        $this->assertSame(['Outro'], FiltroUsuario::where('padrao', true)->pluck('nome')->all());
        $semana = FiltroUsuario::where('nome', 'Semana')->sole();
        $this->assertSame('2026-11-04T23:59', $semana->filtros['ate']);

        $this->get('/dashboards/timeline?filtro='.$semana->id)->assertSee('01/11/2026 01:00 até 04/11/2026 23:59')
            ->assertSee('01/11')->assertSee('03/11'); // eixo de vários dias mostra as datas
    }

    public function test_filtros_sao_de_cada_usuario(): void
    {
        $dono = $this->usuario('dono@empresa.com');
        $outro = $this->usuario('outro@empresa.com');
        $this->actingAs($dono)->post('/dashboards/timeline/filtros', ['nome' => 'Meu', 'periodo' => 'hoje',
            'de' => '2026-11-05T01:00', 'ate' => '2026-11-05T23:59']);
        $f = FiltroUsuario::sole();

        $this->actingAs($outro)->get('/dashboards/timeline')->assertDontSee('>Meu<', false);
        $this->get('/dashboards/timeline?filtro='.$f->id)->assertSee('05/11/2026 01:00'); // ignora filtro de outro usuário
        $this->delete('/dashboards/timeline/filtros/'.$f->id)->assertNotFound();
        $this->assertSame(1, FiltroUsuario::count());

        $this->actingAs($dono)->delete('/dashboards/timeline/filtros/'.$f->id)->assertRedirect()->assertSessionHas('ok');
        $this->assertSame(0, FiltroUsuario::count());
    }

    public function test_sem_permissao_nao_salva_filtro(): void
    {
        $u = Usuario::create(['entra_oid' => 'x', 'nome' => 'X', 'email' => 'x@empresa.com', 'perfil' => 'operador']);
        $this->actingAs($u)->post('/dashboards/timeline/filtros', ['nome' => 'X', 'periodo' => 'hoje'])->assertForbidden();
    }

    public function test_marcas_do_eixo_acompanham_o_tamanho_do_periodo(): void
    {
        $t = app(Timeline::class);
        $dia = $t->marcas(Carbon::parse('2026-11-05 04:00', 'UTC'), Carbon::parse('2026-11-06 03:00', 'UTC'));
        $this->assertCount(23, $dia); // 01:00..23:00 de hora em hora
        $semana = $t->marcas(Carbon::parse('2026-11-01 03:00', 'UTC'), Carbon::parse('2026-11-08 03:00', 'UTC'));
        $this->assertLessThanOrEqual(30, count($semana));
        $this->assertContains('02/11', array_column($semana, 'rotulo'));
    }
}
