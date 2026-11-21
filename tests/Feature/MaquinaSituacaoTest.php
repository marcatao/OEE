<?php

namespace Tests\Feature;

use App\Models\ApontamentoEstado;
use App\Models\DispositivoIot;
use App\Models\Legenda;
use App\Models\Linha;
use App\Models\Maquina;
use App\Models\OrdemProducao;
use App\Models\Planta;
use App\Models\ProdutoProtheus;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CalendarioDeTeste;
use Tests\TestCase;

/** Situação (produzindo | parada) e OP atual no cadastro da máquina, atualizadas pelo apontamento real mais recente. */
class MaquinaSituacaoTest extends TestCase
{
    use CalendarioDeTeste;
    use RefreshDatabase;

    private Maquina $maquina;

    private OrdemProducao $op;

    protected function setUp(): void
    {
        parent::setUp();
        $planta = Planta::create(['codigo' => 'P', 'nome' => 'Planta']);
        $linha = Linha::create(['planta_id' => $planta->id, 'codigo' => 'L', 'nome' => 'Linha']);
        $iot = DispositivoIot::create(['codigo' => 'S1', 'nome' => 'Sensor 1', 'tipo' => 'sensor', 'protocolo' => 'mqtt', 'ativo' => true]);
        $this->maquina = Maquina::create(['linha_id' => $linha->id, 'codigo' => 'INJ-12', 'nome' => 'Injetora 12', 'ativo' => true,
            'iot1_dispositivo_id' => $iot->id, 'iot1_canal' => 'chd1_value', 'iot1_tipo' => 'producao'] + $this->jornadaGravada());
        $this->op = OrdemProducao::create(['c2_num' => '062678', 'c2_item' => '01', 'c2_sequen' => '001', 'numero' => '06267801001',
            'c2_produto' => 'PA001', 'status' => 'aberta']);
        ProdutoProtheus::create(['b1_cod' => 'PA001', 'b1_desc' => 'INTERRUPTOR PARALELO', 'b1_um' => 'PC']);
        Legenda::create(['codigo' => '2', 'descricao' => 'Produção Normal', 'tipo' => 'produzindo', 'cor' => '#43a047']);
        Legenda::create(['codigo' => '3', 'descricao' => 'Manutenção', 'tipo' => 'parada', 'cor' => '#e53935']);
        Legenda::create(['codigo' => '16', 'descricao' => 'Refeição', 'tipo' => 'planejada', 'cor' => '#90a4ae']);
        $this->actingAs(Usuario::create(['entra_oid' => 'oid-a', 'nome' => 'Adm', 'email' => 'adm@empresa.com', 'perfil' => 'admin']));
    }

    private function apontar(string $legenda, string $hora, ?string $op = null)
    {
        return $this->post('/producao/apontamentos', ['maquina_id' => $this->maquina->id, 'legenda_id' => Legenda::where('codigo', $legenda)->value('id'),
            'inicio' => now('America/Sao_Paulo')->format('Y-m-d').'T'.$hora, 'ordem_producao' => $op])->assertSessionHasNoErrors();
    }

    public function test_apontamento_atualiza_situacao_e_op_da_maquina(): void
    {
        // Apontamento de produção com OP: máquina produzindo e com a OP.
        $this->apontar('2', '01:00', '06267801001');
        $m = $this->maquina->fresh();
        $this->assertSame(['produzindo', $this->op->id], [$m->situacao, $m->ordem_producao_id]);

        // Apontamento novo para a máquina já vem com a OP dela.
        $this->get('/producao/apontamentos/novo?maquina_id='.$this->maquina->id)->assertOk()
            ->assertSee('value="06267801001"', false)->assertSee('data-op="06267801001"', false);

        // Timeline e lista de máquinas mostram.
        $this->get('/dashboards/timeline')->assertSee('estado-produzindo', false)->assertSee('OP 06267801001')->assertSee('INTERRUPTOR PARALELO');
        $this->get('/admin/maquinas')->assertSee('maq-produzindo', false)->assertSee('06267801001');

        // Apontamento de parada SEM OP: máquina parada e a OP desvincula.
        $this->apontar('3', '02:00');
        $m = $this->maquina->fresh();
        $this->assertSame(['parada', null], [$m->situacao, $m->ordem_producao_id]);
        $this->get('/dashboards/timeline')->assertSee('estado-parada', false)->assertDontSee('<strong>OP 06267801001</strong>', false);
        $this->get('/admin/maquinas')->assertSee('maq-parada', false);

        // Parada planejada: situação "parada", cartão em âmbar na timeline.
        $this->apontar('16', '03:00');
        $this->assertSame('parada', $this->maquina->fresh()->situacao);
        $this->get('/dashboards/timeline')->assertSee('estado-planejada', false)->assertSee('Parada planejada');

        // Excluir o apontamento mais recente: a máquina volta a seguir o anterior.
        $ultimo = ApontamentoEstado::orderByDesc('inicio')->first();
        $this->delete("/producao/apontamentos/{$ultimo->id}");
        $this->assertSame('3', $this->maquina->fresh()->situacaoLegenda->codigo);
    }

    public function test_editar_apontamento_antigo_nao_muda_a_maquina(): void
    {
        $this->apontar('2', '01:00', '06267801001');
        $this->apontar('3', '02:00');
        $antigo = ApontamentoEstado::orderBy('inicio')->first();
        $this->put("/producao/apontamentos/{$antigo->id}", ['maquina_id' => $this->maquina->id, 'legenda_id' => $antigo->legenda_id,
            'inicio' => $antigo->inicio->setTimezone('America/Sao_Paulo')->format('Y-m-d\TH:i'),
            'fim' => $antigo->fim->setTimezone('America/Sao_Paulo')->format('Y-m-d\TH:i'), 'ordem_producao' => ''])->assertSessionHasNoErrors();

        $this->assertSame(['parada', null], [$this->maquina->fresh()->situacao, $this->maquina->fresh()->ordem_producao_id]);
    }

    public function test_cadastro_da_maquina_informa_situacao_e_op(): void
    {
        $dados = ['codigo' => 'INJ-12', 'nome' => 'Injetora 12', 'linha_id' => $this->maquina->linha_id, 'ativo' => '1'];

        $this->get("/admin/maquinas/{$this->maquina->id}/editar")->assertOk()->assertSee('Máquina trabalhando ou parada')->assertSee('OP atual');
        $this->put("/admin/maquinas/{$this->maquina->id}", $dados + ['situacao' => 'produzindo', 'ordem_producao' => '06267801001'])
            ->assertSessionHasNoErrors();
        $m = $this->maquina->fresh();
        $this->assertSame(['produzindo', $this->op->id, null], [$m->situacao, $m->ordem_producao_id, $m->situacao_legenda_id]);
        $this->assertNotNull($m->situacao_desde);
        $this->get('/dashboards/timeline')->assertSee('informada no cadastro')->assertSee('OP 06267801001');

        $this->put("/admin/maquinas/{$this->maquina->id}", $dados + ['situacao' => 'parada', 'ordem_producao' => 'XYZ'])
            ->assertSessionHasErrors('ordem_producao');
        $this->put("/admin/maquinas/{$this->maquina->id}", $dados + ['situacao' => 'parada', 'ordem_producao' => ''])->assertSessionHasNoErrors();
        $this->assertSame(['parada', null], [$this->maquina->fresh()->situacao, $this->maquina->fresh()->ordem_producao_id]);

        // O apontamento mais recente volta a mandar.
        $this->apontar('2', '01:00', '06267801001');
        $this->assertSame(['produzindo', $this->op->id], [$this->maquina->fresh()->situacao, $this->maquina->fresh()->ordem_producao_id]);
    }

    public function test_comando_preenche_pelos_apontamentos_so_com_confirmar(): void
    {
        ApontamentoEstado::create(['maquina_id' => $this->maquina->id, 'legenda_id' => Legenda::where('codigo', '2')->value('id'),
            'ordem_producao_id' => $this->op->id, 'inicio' => now()->subHour()]);

        $this->artisan('maquinas:situacao-pelos-apontamentos')->assertSuccessful();
        $this->assertNull($this->maquina->fresh()->situacao);
        $this->artisan('maquinas:situacao-pelos-apontamentos --confirmar')->assertSuccessful();
        $this->assertSame(['produzindo', $this->op->id], [$this->maquina->fresh()->situacao, $this->maquina->fresh()->ordem_producao_id]);
    }

    public function test_campo_de_op_busca_pela_op_ou_pelo_produto(): void
    {
        OrdemProducao::create(['c2_num' => '062679', 'c2_item' => '01', 'c2_sequen' => '001', 'numero' => '06267901001',
            'c2_produto' => 'PA002', 'status' => 'fechada', 'c2_datrf' => '20261101']);

        // Os dois formulários usam a lista com busca compacta, ligada à busca de OP.
        $this->get('/producao/apontamentos/novo')->assertOk()->assertSee('data-busca-url="'.route('ordens-producao.busca').'"', false)
            ->assertSee('data-compacto', false)->assertDontSee('<datalist', false);
        $this->get("/admin/maquinas/{$this->maquina->id}/editar")->assertOk()->assertSee('data-busca-url="'.route('ordens-producao.busca').'"', false);

        // Pelo número, pelo código e pela descrição do produto; só OPs que ainda podem ser apontadas (não fechadas).
        $this->getJson('/producao/ordens-de-producao/busca?q=062678')->assertOk()
            ->assertJson([['valor' => '06267801001', 'texto' => '06267801001 · PA001 INTERRUPTOR PARALELO · Aberta']]);
        $this->getJson('/producao/ordens-de-producao/busca?q=interruptor')->assertJsonCount(1)->assertJsonPath('0.valor', '06267801001');
        $this->getJson('/producao/ordens-de-producao/busca?q=PA00')->assertJsonCount(1);  // a 062679 (fechada) não vem
        $this->getJson('/producao/ordens-de-producao/busca?q=0')->assertExactJson([]);   // mínimo 2 letras
        // Qualquer parte do número, do SKU ou da descrição, e várias palavras juntas (cada uma em qualquer campo).
        $this->getJson('/producao/ordens-de-producao/busca?q=7801')->assertJsonCount(1)->assertJsonPath('0.valor', '06267801001');
        $this->getJson('/producao/ordens-de-producao/busca?q=A001')->assertJsonCount(1);
        $this->getJson('/producao/ordens-de-producao/busca?q=paralelo interr')->assertJsonCount(1);
        $this->getJson('/producao/ordens-de-producao/busca?q=PA001 paralelo')->assertJsonCount(1);
        $this->getJson('/producao/ordens-de-producao/busca?q=paralelo tomada')->assertExactJson([]);
        $this->getJson('/producao/ordens-de-producao/busca?q=50%25')->assertExactJson([]); // % é texto, não curinga
        $this->get('/producao/ordens-de-producao?busca=interruptor')->assertOk()->assertSee('06267801001');

        // Quem não aponta nem administra não usa a busca.
        $u = Usuario::create(['entra_oid' => 'oid-m', 'nome' => 'M', 'email' => 'm@empresa.com', 'perfil' => 'operador']);
        $u->definirPerfis(['mantenedor_producao']);
        $this->actingAs($u)->getJson('/producao/ordens-de-producao/busca?q=0626')->assertForbidden();
    }
}
