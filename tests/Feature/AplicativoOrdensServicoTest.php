<?php

namespace Tests\Feature;

use App\Models\CentroCusto;
use App\Models\Linha;
use App\Models\Manutencao\ApontamentoHoras;
use App\Models\Manutencao\AreaExecutante;
use App\Models\Manutencao\AssinaturaOs;
use App\Models\Manutencao\ChecklistOs;
use App\Models\Manutencao\HistoricoOs;
use App\Models\Manutencao\Mantenedor;
use App\Models\Manutencao\OrdemServico;
use App\Models\Manutencao\SolicitacaoServico;
use App\Models\Maquina;
use App\Models\Planta;
use App\Models\Usuario;
use App\Services\Aplicativo\LoginAplicativo;
use App\Support\AcessoOs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CalendarioDeTeste;
use Tests\TestCase;

/** Aplicativo › Ordens de serviço: assumir SS, abrir SS, apontamento com cronômetro, concluir e avaliação assinada. */
class AplicativoOrdensServicoTest extends TestCase
{
    use CalendarioDeTeste;
    use RefreshDatabase;

    private const VERIFICADOR = 'segredo-do-app-com-pelo-menos-quarenta-e-tres-caracteres-123';

    private Maquina $injetora;

    private AreaExecutante $mecanica;

    private Usuario $solicitante;

    private Usuario $joao;

    private Usuario $pedro;

    private Mantenedor $mJoao;

    private Mantenedor $mPedro;

    /** 1x1 PNG transparente. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        AcessoOs::limparCache();
        CentroCusto::create(['ctt_custo' => '1001', 'ctt_desc01' => 'INJECAO']);
        $planta = Planta::create(['codigo' => 'P', 'nome' => 'Planta']);
        $linha = Linha::create(['planta_id' => $planta->id, 'codigo' => 'L', 'nome' => 'Linha']);
        $this->injetora = Maquina::create(['linha_id' => $linha->id, 'codigo' => 'INJ-01', 'nome' => 'Injetora 01', 'ativo' => false, 'centro_custo' => '1001']);
        $this->mecanica = AreaExecutante::create(['codigo' => 'MEC', 'nome' => 'Mecânica', 'unidade' => 'manutencao']);
        $this->solicitante = $this->usuario(['solicitante_manutencao'], 'ana@alumbra.com.br', '1001');
        $this->solicitante->forceFill(['setor' => 'Injeção'])->save();
        $this->joao = $this->usuario(['mantenedor_producao'], 'joao@alumbra.com.br');
        $this->pedro = $this->usuario(['mantenedor_producao'], 'pedro@alumbra.com.br');
        $this->mJoao = Mantenedor::create(['nome' => 'João', 'usuario_id' => $this->joao->id] + $this->jornadaGravada());
        $this->mPedro = Mantenedor::create(['nome' => 'Pedro', 'usuario_id' => $this->pedro->id] + $this->jornadaGravada());
    }

    private function usuario(array $perfis, string $email, ?string $cc = null): Usuario
    {
        $u = Usuario::create(['entra_oid' => "oid-{$email}", 'nome' => ucfirst(strtok($email, '@')), 'email' => $email, 'local_escritorio' => $cc]);
        $u->definirPerfis($perfis);

        return $u->fresh();
    }

    private function token(Usuario $u): string
    {
        $login = app(LoginAplicativo::class);

        return $login->trocarCodigo($login->criarCodigo($u, LoginAplicativo::desafioDe(self::VERIFICADOR)), self::VERIFICADOR, null, null)['token'];
    }

    private function api(Usuario $u, string $metodo, string $url, array $dados = [])
    {
        app('auth')->forgetGuards();

        return $this->json($metodo, '/api/app/os/'.$url, $dados, ['Authorization' => 'Bearer '.$this->token($u)]);
    }

    /** SS aberta pelo solicitante (no portal), pendente para os mantenedores. */
    private function abrirSs(): SolicitacaoServico
    {
        return SolicitacaoServico::create(['titulo' => 'Vazamento de óleo', 'unidade' => 'manutencao', 'solicitante_id' => $this->solicitante->id,
            'tipo' => 'corretiva', 'area_executante_id' => $this->mecanica->id, 'maquina_id' => $this->injetora->id, 'centro_custo' => '1001',
            'prioridade' => 'alta', 'maquina_parada' => true, 'descricao' => 'Vazamento no cilindro', 'status' => 'pendente']);
    }

    public function test_os_no_app_so_para_quem_tem_perfil_de_mantenedor(): void
    {
        // Só solicitante: não entra nas Ordens de serviço do app.
        $this->api($this->solicitante, 'GET', 'resumo')->assertForbidden();
        $this->api($this->solicitante, 'GET', 'formulario')->assertForbidden();

        // Mantenedor que também é solicitante abre SS pelo app, com as regras do portal (CC da máquina).
        $this->joao->definirPerfis(['mantenedor_producao', 'solicitante_manutencao']);
        $this->api($this->joao->fresh(), 'GET', 'formulario')->assertOk()->assertJsonPath('areas.0.nome', 'Mecânica');
        $this->api($this->joao->fresh(), 'POST', 'solicitacoes', [
            'titulo' => 'Vazamento de óleo', 'tipo' => 'corretiva', 'area_executante_id' => $this->mecanica->id,
            'maquina_id' => $this->injetora->id, 'prioridade' => 'alta', 'maquina_parada' => true, 'descricao' => 'Vazamento no cilindro',
        ])->assertOk()->assertJsonPath('ok', true);
        $ss = SolicitacaoServico::latest('id')->firstOrFail();
        $this->assertSame('pendente', $ss->status);
        $this->assertSame('1001', $ss->centro_custo);
    }

    public function test_primeiro_que_assume_leva_a_ss(): void
    {
        $ss = $this->abrirSs();

        $pendentes = $this->api($this->pedro, 'GET', 'resumo')->assertOk()->json('pendentes');
        $this->assertSame($ss->id, $pendentes[0]['id']);
        $this->assertTrue($pendentes[0]['maquina_parada']);

        $this->api($this->joao, 'POST', "solicitacoes/{$ss->id}/assumir")->assertOk()->assertJsonPath('ordem.responsavel', 'Joao');
        $this->api($this->pedro, 'POST', "solicitacoes/{$ss->id}/assumir")->assertUnprocessable()
            ->assertJsonFragment(['Esta solicitação já foi assumida por Joao.']);

        $this->assertSame(1, OrdemServico::count());
        $this->assertSame([], $this->api($this->pedro, 'GET', 'resumo')->json('pendentes'));
    }

    public function test_apontamento_com_cronometro_encerra_conclui_e_marca_maquina_parada(): void
    {
        $ss = $this->abrirSs();
        $os = OrdemServico::findOrFail($this->api($this->joao, 'POST', "solicitacoes/{$ss->id}/assumir")->json('ordem.id'));

        $this->travel(-90)->minutes();
        $json = $this->api($this->joao, 'POST', "ordens/{$os->id}/iniciar")->assertOk()->json();
        $this->travelBack();
        $this->assertSame('Ana', $json['solicitante']);
        $this->assertSame('Injeção', $json['setor']);
        $this->assertSame('1001 · INJECAO', $json['centro_custo']);
        $ap = ApontamentoHoras::findOrFail($json['meu_apontamento']['id']);
        $this->assertSame($ap->id, $this->api($this->joao, 'GET', 'resumo')->json('rodando.id'));

        // O celular manda o fim de 30 min atrás (ficou sem rede ao encerrar).
        $fim = now()->subMinutes(30)->startOfMinute();
        $this->api($this->pedro, 'POST', "apontamentos/{$ap->id}/encerrar", ['descricao' => 'x'])->assertForbidden();
        $this->api($this->joao, 'POST', "apontamentos/{$ap->id}/encerrar", [
            'descricao' => 'Troca da vedação do cilindro', 'concluir_os' => true, 'maquina_parada' => false, 'fim_utc' => $fim->toIso8601String(),
        ])->assertOk()->assertJsonPath('status', 'concluida');

        $this->assertSame($fim->getTimestamp(), $ap->fresh()->fim->getTimestamp());
        $this->assertFalse($os->fresh()->maquina_parada);
        $this->assertNull($this->api($this->joao, 'GET', 'resumo')->json('rodando'));
        $this->assertSame($os->id, $this->api($this->joao, 'GET', 'avaliacoes')->json('ordens.0.id'));
    }

    public function test_avaliacao_do_solicitante_no_celular_do_mantenedor_com_assinatura(): void
    {
        $ss = $this->abrirSs();
        $osId = $this->api($this->joao, 'POST', "solicitacoes/{$ss->id}/assumir")->json('ordem.id');
        $apId = $this->api($this->joao, 'POST', "ordens/{$osId}/iniciar")->json('meu_apontamento.id');
        $this->api($this->joao, 'POST', "apontamentos/{$apId}/encerrar", ['descricao' => 'Feito', 'concluir_os' => true])->assertOk();

        $this->api($this->joao, 'POST', "ordens/{$osId}/avaliar", ['avaliacao' => 'boa', 'assinatura' => 'nao-e-png'])->assertUnprocessable();
        $this->api($this->joao, 'POST', "ordens/{$osId}/avaliar", ['avaliacao' => 'ruim', 'assinatura' => self::PNG.str_repeat('A', 120)])
            ->assertUnprocessable(); // ruim exige motivo (ou assinatura inválida)
        $this->api($this->joao, 'POST', "ordens/{$osId}/avaliar", ['avaliacao' => 'excelente', 'assinatura' => 'data:image/png;base64,'.$this->pngGrande()])
            ->assertOk()->assertJsonPath('status', 'encerrada');

        $os = OrdemServico::findOrFail($osId);
        $this->assertSame('excelente', $os->avaliacao);
        $this->assertSame($this->solicitante->id, $os->aceite_por_id); // quem avaliou é o solicitante
        $assinatura = AssinaturaOs::firstOrFail();
        $this->assertSame($this->joao->id, $assinatura->registrado_por_id);
        $this->assertSame('Ana', $assinatura->nome_assinante);

        // A tela da OS (web) mostra a avaliação, o comentário e a assinatura feita no app; a impressão também.
        $this->actingAs($this->solicitante)->get("/ordens-de-servico/ordens/{$osId}")->assertOk()
            ->assertSee('Avaliação do solicitante')->assertSee('Excelente')->assertSee('Sem comentário.')
            ->assertSee('data:image/png;base64,'.$assinatura->imagem_png, false)->assertSee('assinado no aplicativo');
        $this->get("/ordens-de-servico/ordens/{$osId}/imprimir")->assertOk()->assertSee('data:image/png;base64,', false);
    }

    /** No SQL Server os ids chegam como texto: o dono do apontamento tem de continuar sendo reconhecido. */
    public function test_encerrar_com_ids_em_texto_como_no_sql_server(): void
    {
        $ss = $this->abrirSs();
        $osId = $this->api($this->joao, 'POST', "solicitacoes/{$ss->id}/assumir")->json('ordem.id');
        $apId = $this->api($this->joao, 'POST', "ordens/{$osId}/iniciar")->json('meu_apontamento.id');
        ApontamentoHoras::retrieved(fn ($ap) => $ap->setRawAttributes(array_map(
            fn ($v) => is_int($v) ? (string) $v : $v, $ap->getAttributes()), true));

        $this->api($this->joao, 'GET', "ordens/{$osId}")->assertOk()->assertJsonPath('meu_apontamento.id', $apId);
        $this->api($this->joao, 'POST', "apontamentos/{$apId}/encerrar", ['descricao' => 'Feito', 'concluir_os' => true])
            ->assertOk()->assertJsonPath('status', 'concluida');
    }

    public function test_mantenedor_nao_ve_nem_e_alertado_por_ss_de_outra_unidade(): void
    {
        // Área de Tecnologia no cadastro do João e SS do centro de custo dele, mas ele só tem perfil da Manutenção.
        $ti = AreaExecutante::create(['codigo' => 'TI', 'nome' => 'Suporte de TI', 'unidade' => 'tecnologia']);
        $this->mJoao->definirAreas([$this->mecanica->id, $ti->id]);
        $this->joao->forceFill(['local_escritorio' => '1001'])->save();
        $ss = SolicitacaoServico::create(['titulo' => 'Notebook', 'unidade' => 'tecnologia', 'solicitante_id' => $this->solicitante->id,
            'tipo' => 'corretiva', 'area_executante_id' => $ti->id, 'centro_custo' => '1001', 'prioridade' => 'alta',
            'maquina_parada' => true, 'descricao' => 'Não liga', 'status' => 'pendente']);

        $this->assertSame([], $this->api($this->joao, 'GET', 'resumo')->json('pendentes'));
        $this->api($this->joao, 'POST', "solicitacoes/{$ss->id}/assumir")->assertNotFound();
        $this->assertSame([], $this->api($this->joao, 'GET', 'solicitacoes')->json('pendentes'));
    }

    public function test_alerta_para_de_tocar_quando_outro_assume(): void
    {
        $ss = $this->abrirSs();
        $this->assertSame($ss->id, $this->api($this->pedro, 'GET', 'alerta')->json('pendentes.0.id'));

        $this->api($this->joao, 'POST', "solicitacoes/{$ss->id}/assumir")->assertOk();
        $this->assertSame([], $this->api($this->pedro, 'GET', 'alerta')->json('pendentes'));
    }

    public function test_recusar_so_aquela_ss_para_de_tocar_e_vai_para_o_historico(): void
    {
        $ss1 = $this->abrirSs();
        // "insert or ignore" não existe no SQL Server (o servidor de produção): não pode ser usado.
        $semIgnore = [];
        DB::listen(function ($q) use (&$semIgnore) {
            if (stripos($q->sql, 'or ignore') !== false) {
                $semIgnore[] = $q->sql;
            }
        });
        $this->api($this->pedro, 'POST', "solicitacoes/{$ss1->id}/recusar")->assertOk()->assertJsonPath('pendentes', []);
        $this->api($this->pedro, 'POST', "solicitacoes/{$ss1->id}/recusar")->assertOk(); // de novo: não duplica
        $this->assertSame([], $semIgnore);

        $this->assertSame(1, HistoricoOs::where('solicitacao_id', $ss1->id)->where('acao', 'nao_aceita')->count());
        $this->assertStringContainsString('SS não aceita por Pedro',
            HistoricoOs::where('acao', 'nao_aceita')->value('observacao'));

        // Para o João continua tocando; para o Pedro, a próxima SS volta a tocar.
        $this->assertSame($ss1->id, $this->api($this->joao, 'GET', 'alerta')->json('pendentes.0.id'));
        $ss2 = $this->abrirSs();
        $this->assertSame([$ss2->id], array_column($this->api($this->pedro, 'GET', 'alerta')->json('pendentes'), 'id'));

        // Na lista ela continua (marcada como recusada) e ele ainda pode assumir.
        $lista = collect($this->api($this->pedro, 'GET', 'solicitacoes')->json('pendentes'))->keyBy('id');
        $this->assertTrue($lista[$ss1->id]['recusada']);
        $this->assertFalse($lista[$ss2->id]['recusada']);
        $this->api($this->pedro, 'POST', "solicitacoes/{$ss1->id}/assumir")->assertOk();
    }

    public function test_quem_nao_e_mantenedor_nao_inicia(): void
    {
        $ss = $this->abrirSs();
        $osId = $this->api($this->joao, 'POST', "solicitacoes/{$ss->id}/assumir")->json('ordem.id');
        $this->api($this->solicitante, 'POST', "ordens/{$osId}/iniciar")->assertForbidden(); // não é mantenedor
    }

    public function test_app_nao_mostra_nem_alerta_ss_de_unidade_fora_do_login(): void
    {
        // Login só com Tecnologia (até administrador), mesmo com o cadastro de mantenedor ligado a uma área da Manutenção.
        $rafa = $this->usuario(['solicitante_tecnologia', 'mantenedor_tecnologia', 'admin_tecnologia'], 'rafaela@alumbra.com.br', '1001');
        Mantenedor::create(['nome' => 'Rafaela', 'usuario_id' => $rafa->id] + $this->jornadaGravada())->definirAreas([$this->mecanica->id]);
        $ss = $this->abrirSs();

        $this->api($rafa, 'GET', 'resumo')->assertOk()->assertJsonCount(0, 'pendentes');
        $this->api($rafa, 'GET', 'solicitacoes')->assertOk()->assertJsonCount(0, 'pendentes');
        $this->api($rafa, 'POST', "solicitacoes/{$ss->id}/assumir")->assertNotFound();
        $this->api($this->joao, 'GET', 'resumo')->assertOk()->assertJsonCount(1, 'pendentes'); // o da Manutenção é alertado
    }

    public function test_checklist_da_preventiva_e_adicionar_mantenedor_no_app(): void
    {
        $os = OrdemServico::create(['unidade' => 'manutencao', 'tipo' => 'preventiva', 'titulo' => 'Lubrificação', 'area_executante_id' => $this->mecanica->id,
            'maquina_id' => $this->injetora->id, 'prioridade' => 'baixa', 'descricao' => 'Preventiva', 'status' => 'aberta', 'responsavel_id' => $this->mJoao->id]);
        $os->mantenedores()->attach($this->mJoao->id, ['criado_em' => now()]);
        $oleo = ChecklistOs::create(['ordem_servico_id' => $os->id, 'ordem' => 10, 'descricao' => 'Verificar nível de óleo']);
        $guias = ChecklistOs::create(['ordem_servico_id' => $os->id, 'ordem' => 20, 'descricao' => 'Lubrificar guias']);

        // O detalhe traz o checklist, os mantenedores da OS e quem pode ser incluído.
        $this->api($this->joao, 'GET', "ordens/{$os->id}")->assertOk()
            ->assertJsonPath('checklist.0.descricao', 'Verificar nível de óleo')->assertJsonPath('checklist.0.resposta', null)
            ->assertJsonPath('pode_responder_checklist', true)->assertJsonPath('respostas_checklist.1.valor', 'nok')
            ->assertJsonPath('mantenedores.0.nome', 'Joao')->assertJsonPath('mantenedores.0.principal', true)
            ->assertJsonPath('pode_adicionar_mantenedor', true)->assertJsonPath('mantenedores_disponiveis.0.nome', 'Pedro');
        // Pedro ainda não vê a OS em "Minhas OS" (já tem dono).
        $this->assertSame([], collect($this->api($this->pedro, 'GET', 'ordens')->json('ordens'))->pluck('id')->all());

        // Concluir com checklist em branco é recusado; responde um item e depois o outro.
        $this->api($this->joao, 'POST', "ordens/{$os->id}/acao", ['acao' => 'concluir'])->assertStatus(422)->assertJsonValidationErrors('checklist');
        $this->api($this->joao, 'POST', "ordens/{$os->id}/checklist", ['respostas' => [['id' => $oleo->id, 'resposta' => 'xx']]])
            ->assertStatus(422);
        $this->api($this->joao, 'POST', "ordens/{$os->id}/checklist", ['respostas' => [['id' => $oleo->id, 'resposta' => 'ok']]])
            ->assertOk()->assertJsonPath('checklist.0.resposta', 'ok')->assertJsonPath('checklist.0.respondido_por', 'Joao')
            ->assertJsonPath('checklist.1.resposta', null);
        $this->api($this->joao, 'POST', "ordens/{$os->id}/checklist", ['respostas' => [['id' => $guias->id, 'resposta' => 'nok', 'observacao' => 'Guia riscada']]])
            ->assertOk()->assertJsonPath('checklist.0.resposta', 'ok')->assertJsonPath('checklist.1.observacao', 'Guia riscada');

        // João inclui o Pedro: a OS aparece em "Minhas OS" dele e o histórico registra quem incluiu.
        $this->api($this->joao, 'POST', "ordens/{$os->id}/mantenedores", [])->assertStatus(422)->assertJsonValidationErrors('mantenedor_id');
        $this->api($this->joao, 'POST', "ordens/{$os->id}/mantenedores", ['mantenedor_id' => $this->mPedro->id])->assertOk()
            ->assertJsonPath('mantenedores.1.nome', 'Pedro')->assertJsonPath('pode_adicionar_mantenedor', false);
        $this->api($this->joao, 'POST', "ordens/{$os->id}/mantenedores", ['mantenedor_id' => $this->mPedro->id])->assertStatus(422);
        $this->assertSame([$os->id], collect($this->api($this->pedro, 'GET', 'ordens')->json('ordens'))->pluck('id')->map(fn ($i) => (int) $i)->all());
        $this->assertTrue(HistoricoOs::where('ordem_servico_id', $os->id)->where('observacao', 'like', '%Pedro incluído por Joao (aplicativo)%')->exists());
        // Mantenedor inativo ou de outra área não entra.
        $inativo = Mantenedor::create(['nome' => 'Inativo', 'ativo' => false] + $this->jornadaGravada());
        $this->api($this->joao, 'POST', "ordens/{$os->id}/mantenedores", ['mantenedor_id' => $inativo->id])->assertStatus(422);

        // Agora o Pedro também responde e conclui.
        $this->api($this->pedro, 'POST', "ordens/{$os->id}/acao", ['acao' => 'concluir'])->assertOk()->assertJsonPath('status', 'concluida');
        $this->api($this->pedro, 'POST', "ordens/{$os->id}/checklist", ['respostas' => [['id' => $oleo->id, 'resposta' => 'na']]])->assertStatus(422);
    }

    /** PNG válido com mais de 100 bytes (uma imagem 20x20). */
    private function pngGrande(): string
    {
        $img = imagecreatetruecolor(20, 20);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        ob_start();
        imagepng($img);

        return base64_encode((string) ob_get_clean());
    }
}
