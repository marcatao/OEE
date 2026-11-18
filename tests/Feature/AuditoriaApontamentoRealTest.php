<?php

namespace Tests\Feature;

use App\Models\ApontamentoEstado;
use App\Models\DispositivoIot;
use App\Models\Legenda;
use App\Models\Linha;
use App\Models\Maquina;
use App\Models\OrdemProducao;
use App\Models\Planta;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CalendarioDeTeste;
use Tests\Concerns\IotDeTeste;
use Tests\TestCase;

/**
 * Apontamento real: quem alterou e quando (atualizado_por_id / atualizado_em) em todo caminho de edição do portal, e
 * a origem IoT (dispositivo + canal) gravada pelo Integrador MQTT, que o formulário não altera.
 */
class AuditoriaApontamentoRealTest extends TestCase
{
    use CalendarioDeTeste;
    use IotDeTeste;
    use RefreshDatabase;

    private Maquina $maquina;

    private int $prod;

    private int $parada;

    private Usuario $autor;

    private Usuario $editor;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-18 20:00', 'UTC')); // 17:00 em São Paulo
        config(['oee.fuso_exibicao' => 'America/Sao_Paulo']);
        $planta = Planta::create(['codigo' => 'P', 'nome' => 'Planta']);
        $linha = Linha::create(['planta_id' => $planta->id, 'codigo' => 'L', 'nome' => 'Linha']);
        $this->maquina = Maquina::create(['linha_id' => $linha->id, 'codigo' => 'INJ-01', 'nome' => 'Injetora 01', 'ativo' => true]
            + $this->jornadaGravada() + $this->iotGravado());
        $this->prod = Legenda::where('codigo', 'PROD')->value('id');
        $this->parada = Legenda::where('codigo', 'DT')->value('id');
        $this->autor = Usuario::create(['entra_oid' => 'oid-a', 'nome' => 'Ana Autora', 'email' => 'ana@empresa.com', 'perfil' => 'admin']);
        $this->editor = Usuario::create(['entra_oid' => 'oid-b', 'nome' => 'Beto Editor', 'email' => 'beto@empresa.com', 'perfil' => 'admin']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Apontamento como o integrador grava: sem usuário, com a marca [IoT] e a origem (dispositivo + canal). */
    private function apontamentoIot(array $campos = []): ApontamentoEstado
    {
        $ap = new ApontamentoEstado;
        $ap->forceFill($campos + ['maquina_id' => $this->maquina->id, 'legenda_id' => $this->prod,
            'inicio' => Carbon::parse('2026-11-18 09:00', 'UTC'), 'fim' => Carbon::parse('2026-11-18 10:00', 'UTC'),
            'observacao' => '[IoT] Produção Normal', 'iot_dispositivo_id' => $this->maquina->iot1_dispositivo_id,
            'iot_canal' => 'chd1_value'])->save();

        return $ap;
    }

    private function formulario(array $dados = []): array
    {
        return $dados + ['maquina_id' => $this->maquina->id, 'legenda_id' => $this->parada, 'dia' => '2026-11-18',
            'hora_inicio' => '06:00:00', 'hora_fim' => '07:00:00'];
    }

    public function test_migracao_cria_as_colunas_da_origem_iot(): void
    {
        $this->assertTrue(Schema::hasColumns('apontamentos_estado', ['iot_dispositivo_id', 'iot_canal', 'atualizado_por_id', 'atualizado_em']));
    }

    public function test_edicao_pelo_formulario_grava_quem_alterou_e_quando(): void
    {
        $this->actingAs($this->autor)->post('/producao/apontamentos', $this->formulario())->assertSessionHasNoErrors();
        $ap = ApontamentoEstado::sole();
        $this->assertSame($this->autor->id, (int) $ap->criado_por_id);
        $this->assertNull($ap->atualizado_por_id);

        Carbon::setTestNow(Carbon::parse('2026-11-18 21:30', 'UTC'));
        $this->actingAs($this->editor)->put("/producao/apontamentos/{$ap->id}", $this->formulario(['hora_fim' => '07:30:00']))
            ->assertSessionHasNoErrors();

        $ap->refresh();
        $this->assertSame($this->autor->id, (int) $ap->criado_por_id);
        $this->assertSame($this->editor->id, (int) $ap->atualizado_por_id);
        $this->assertSame('2026-11-18 21:30:00', $ap->atualizado_em->utc()->format('Y-m-d H:i:s'));

        // Formulário: "Registrado por" e "Alterado por" no fuso local; lista: coluna "Alterado".
        $this->get("/producao/apontamentos/{$ap->id}/editar")->assertOk()
            ->assertSee('Registrado por Ana Autora')->assertSee('Alterado por Beto Editor em 18/11/2026 18:30')
            ->assertDontSee('Automático (IoT');
        $this->get('/producao/apontamentos')->assertOk()->assertSee('<th>Alterado</th>', false)
            ->assertSee('Beto Editor')->assertSee('18/11 18:30');
    }

    public function test_sem_edicao_nao_mostra_alterado_por(): void
    {
        $this->actingAs($this->autor)->post('/producao/apontamentos', $this->formulario())->assertSessionHasNoErrors();
        $this->get('/producao/apontamentos/'.ApontamentoEstado::sole()->id.'/editar')->assertOk()->assertDontSee('Alterado por');
    }

    public function test_encerramento_automatico_do_anterior_grava_quem_alterou(): void
    {
        $this->actingAs($this->autor)->post('/producao/apontamentos', $this->formulario(['hora_fim' => null]))->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->post('/producao/apontamentos', $this->formulario(['legenda_id' => $this->prod,
            'hora_inicio' => '08:00:00', 'hora_fim' => null]))->assertSessionHasNoErrors();

        $anterior = ApontamentoEstado::orderBy('inicio')->first();
        $this->assertNotNull($anterior->fim);
        $this->assertSame($this->editor->id, (int) $anterior->atualizado_por_id);
        $this->assertNotNull($anterior->atualizado_em);
    }

    public function test_informar_op_na_timeline_grava_quem_alterou(): void
    {
        $legenda = Legenda::create(['codigo' => 'PX', 'descricao' => 'Produzindo com OP', 'tipo' => 'produzindo', 'cor' => '#2e7d32', 'exige_op' => true]);
        $op = OrdemProducao::create(['numero' => '00010001001', 'c2_num' => '000100', 'c2_item' => '01', 'c2_sequen' => '001',
            'c2_produto' => 'PA1', 'c2_datrf' => '', 'status' => 'aberta', 'd_e_l_e_t_' => ' ', 'r_e_c_n_o_' => 1]);
        $ap = $this->apontamentoIot(['legenda_id' => $legenda->id, 'fim' => null]);

        $this->actingAs($this->editor)->postJson("/producao/apontamentos/{$ap->id}/informar-op", ['ordem_producao' => $op->numero])->assertOk();

        $ap->refresh();
        $this->assertSame($op->id, (int) $ap->ordem_producao_id);
        $this->assertSame($this->editor->id, (int) $ap->atualizado_por_id);
        $this->assertSame('chd1_value', $ap->iot_canal); // a origem IoT continua
    }

    public function test_apontamento_do_iot_mostra_dispositivo_e_canal(): void
    {
        $ap = $this->apontamentoIot();
        $codigo = DispositivoIot::withoutGlobalScopes()->find($this->maquina->iot1_dispositivo_id)->codigo;

        $this->assertTrue($ap->automaticoIot());
        $this->assertSame("Automático (IoT: {$codigo} / chd1_value)", $ap->fresh()->textoOrigem());

        $this->actingAs($this->autor)->get("/producao/apontamentos/{$ap->id}/editar")->assertOk()
            ->assertSee("Automático (IoT: {$codigo} / chd1_value)")->assertDontSee('Alterado por');
        $this->get('/producao/apontamentos')->assertOk()->assertSee('selo-iot', false)
            ->assertSee('title="Automático (IoT: '.$codigo.' / chd1_value)"', false);
    }

    public function test_marca_iot_sem_origem_gravada_e_manual(): void
    {
        $ap = new ApontamentoEstado(['observacao' => '[IoT] Parada']);
        $this->assertTrue($ap->automaticoIot());
        $this->assertSame('Automático (IoT)', $ap->textoOrigem());

        $manual = new ApontamentoEstado(['observacao' => 'Troca de molde']);
        $this->assertFalse($manual->automaticoIot());
        $this->assertNull($manual->textoOrigem());
    }

    public function test_formulario_nao_altera_a_origem_iot(): void
    {
        $ap = $this->apontamentoIot();
        $outro = DispositivoIot::withoutGlobalScopes()->create(['codigo' => 'OUTRO', 'nome' => 'Outro', 'tipo' => 'sensor', 'protocolo' => 'mqtt', 'ativo' => true]);

        $this->actingAs($this->editor)->put("/producao/apontamentos/{$ap->id}", $this->formulario([
            'legenda_id' => $this->parada, 'hora_inicio' => '06:00:00', 'hora_fim' => '07:00:00',
            'observacao' => '[IoT] Produção Normal', 'iot_dispositivo_id' => $outro->id, 'iot_canal' => 'chd6_value',
        ]))->assertSessionHasNoErrors();

        $ap->refresh();
        $this->assertSame($this->parada, (int) $ap->legenda_id);
        $this->assertSame($this->maquina->iot1_dispositivo_id, (int) $ap->iot_dispositivo_id);
        $this->assertSame('chd1_value', $ap->iot_canal);
        $this->assertSame($this->editor->id, (int) $ap->atualizado_por_id);

        // Nem por atribuição em massa.
        $ap->fill(['iot_dispositivo_id' => $outro->id, 'iot_canal' => 'chd6_value']);
        $this->assertSame('chd1_value', $ap->iot_canal);
    }
}
