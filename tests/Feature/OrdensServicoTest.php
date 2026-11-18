<?php

namespace Tests\Feature;

use App\Models\CentroCusto;
use App\Models\Linha;
use App\Models\Manutencao\AnexoInteracaoOs;
use App\Models\Manutencao\ApontamentoHoras;
use App\Models\Manutencao\AreaExecutante;
use App\Models\Manutencao\Ferramenta;
use App\Models\Manutencao\Mantenedor;
use App\Models\Manutencao\OrdemServico;
use App\Models\Manutencao\PlanoPreventiva;
use App\Models\Manutencao\SolicitacaoServico;
use App\Models\Maquina;
use App\Models\Planta;
use App\Models\Usuario;
use App\Services\Manutencao\GeradorPreventivas;
use App\Services\Manutencao\PainelManutencao;
use App\Support\AcessoOs;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CalendarioDeTeste;
use Tests\TestCase;

/**
 * Fluxo das Ordens de serviço: SS → aprovação → OS → horas → conclusão → aceite; rejeição; unidades; PAMP; relatórios.
 */
class OrdensServicoTest extends TestCase
{
    use CalendarioDeTeste;
    use RefreshDatabase;

    private Maquina $injetora;

    private AreaExecutante $mecanica;

    private AreaExecutante $ti;

    /** Consultas com a mesma coluna repetida no ORDER BY: o SQLite aceita, o SQL Server recusa (erro 169). */
    private array $ordenacaoRepetida = [];

    protected function setUp(): void
    {
        parent::setUp();
        DB::listen(function ($consulta) {
            if (preg_match('/order by (.+?)(?: limit | offset |\)|$)/i', $consulta->sql, $m)) {
                $colunas = array_map(fn ($c) => strtolower(trim(preg_replace('/\s+(asc|desc)$/i', '', trim($c)))), explode(',', $m[1]));
                if (count($colunas) !== count(array_unique($colunas))) {
                    $this->ordenacaoRepetida[] = $consulta->sql;
                }
            }
        });
        AcessoOs::limparCache();
        CentroCusto::create(['ctt_custo' => '1001', 'ctt_desc01' => 'INJECAO']);
        CentroCusto::create(['ctt_custo' => '2002', 'ctt_desc01' => 'ADMINISTRATIVO']);
        $planta = Planta::create(['codigo' => 'P', 'nome' => 'Planta']);
        $linha = Linha::create(['planta_id' => $planta->id, 'codigo' => 'L', 'nome' => 'Linha']);
        $this->injetora = Maquina::create(['linha_id' => $linha->id, 'codigo' => 'INJ-01', 'nome' => 'Injetora 01', 'ativo' => true, 'centro_custo' => '1001']);
        $this->mecanica = AreaExecutante::create(['codigo' => 'MEC', 'nome' => 'Mecânica', 'unidade' => 'manutencao']);
        $this->ti = AreaExecutante::create(['codigo' => 'TI', 'nome' => 'Suporte de TI', 'unidade' => 'tecnologia']);
    }

    protected function tearDown(): void
    {
        $repetidas = $this->ordenacaoRepetida;
        parent::tearDown();
        $this->assertSame([], $repetidas, 'Coluna repetida no ORDER BY (o SQL Server recusa).');
    }

    private function usuario(array $perfis, string $email, ?string $cc = null): Usuario
    {
        $u = Usuario::create(['entra_oid' => "oid-{$email}", 'nome' => ucfirst(strtok($email, '@')), 'email' => $email, 'local_escritorio' => $cc]);
        $u->definirPerfis($perfis);

        return $u->fresh();
    }

    private function abrirSs(Usuario $u, array $dados = []): SolicitacaoServico
    {
        $this->actingAs($u)->post('/ordens-de-servico/solicitacoes', $dados + [
            'titulo' => 'Vazamento de óleo', 'tipo' => 'corretiva', 'area_executante_id' => $this->mecanica->id, 'maquina_id' => $this->injetora->id,
            'prioridade' => 'alta', 'maquina_parada' => '1', 'descricao' => 'Vazamento de óleo no cilindro',
        ])->assertRedirect()->assertSessionHasNoErrors();

        return SolicitacaoServico::latest('id')->firstOrFail();
    }

    public function test_fluxo_completo_da_corretiva(): void
    {
        $operador = $this->usuario(['operador', 'solicitante_manutencao', 'solicitante_tecnologia'], 'operador@empresa.com');
        $lider = $this->usuario(['admin_producao'], 'lider@empresa.com');
        $mecanico = $this->usuario(['mantenedor_producao'], 'mecanico@empresa.com');
        $mantenedor = Mantenedor::create(['nome' => 'João Mecânico', 'usuario_id' => $mecanico->id, 'area_executante_id' => $this->mecanica->id]);

        $ss = $this->abrirSs($operador);
        $this->assertSame('pendente', $ss->status);
        $this->assertSame('manutencao', $ss->unidade);
        $this->assertSame('1001', $ss->centro_custo); // veio da máquina
        $this->get('/ordens-de-servico/solicitacoes')->assertOk()->assertSee($ss->numeroFormatado());

        // Operador não aprova.
        $this->post("/ordens-de-servico/solicitacoes/{$ss->id}/aprovar")->assertForbidden();

        // Líder aprova e distribui: a SS vira OS com os mesmos dados.
        $this->actingAs($lider)->post("/ordens-de-servico/solicitacoes/{$ss->id}/aprovar", ['mantenedores' => [$mantenedor->id]])->assertRedirect();
        $os = OrdemServico::where('solicitacao_id', $ss->id)->firstOrFail();
        $this->assertSame('aberta', $os->status);
        $this->assertSame($lider->id, $ss->fresh()->analisado_por_id);
        $this->assertSame('Vazamento de óleo no cilindro', $os->descricao);
        $this->assertSame('Vazamento de óleo', $os->titulo);
        $this->assertTrue($os->maquina_parada);
        $this->assertSame([$mantenedor->id], $os->mantenedores()->pluck('os_mantenedores.id')->map(fn ($i) => (int) $i)->all());
        $this->assertSame($mantenedor->id, $os->responsavel_id);

        // Sem botão "Iniciar execução": a OS só entra em atendimento no primeiro apontamento de horas.
        $this->actingAs($mecanico)->get("/ordens-de-servico/ordens/{$os->id}")->assertOk()->assertDontSee('value="iniciar"', false);
        $this->post("/ordens-de-servico/ordens/{$os->id}/acao", ['acao' => 'iniciar'])->assertSessionHasErrors('acao');
        $this->assertSame('aberta', $os->fresh()->status);

        // Mecânico inicia o apontamento (a OS entra em atendimento), requisita material e encerra o apontamento.
        $this->actingAs($mecanico)->get('/ordens-de-servico/minhas')->assertOk()->assertSee($os->numeroFormatado());
        $this->post("/ordens-de-servico/ordens/{$os->id}/apontamentos/iniciar")->assertRedirect();
        $this->assertSame('em_execucao', $os->fresh()->status);
        $this->assertSame(1, $os->historico()->where('acao', 'iniciar')->count()); // "Em atendimento" na linha do tempo
        $this->post("/ordens-de-servico/ordens/{$os->id}/materiais", ['descricao' => 'Retentor 40mm', 'quantidade' => 2, 'um' => 'PC'])->assertRedirect();
        $ap = ApontamentoHoras::firstOrFail();
        $ap->forceFill(['inicio' => now()->startOfMinute()->subMinutes(90)])->save();
        $this->post("/ordens-de-servico/apontamentos/{$ap->id}/encerrar", ['descricao' => 'Trocado o retentor'])->assertRedirect();
        $ap->refresh();
        $this->assertSame(90, (int) $ap->minutos_normais + (int) $ap->minutos_extras);
        $this->assertSame(90, $os->fresh()->total_minutos);

        // Conclui: data do último apontamento.
        $this->post("/ordens-de-servico/ordens/{$os->id}/acao", ['acao' => 'concluir', 'observacao' => 'Retentor trocado e testado'])->assertRedirect();
        $os->refresh();
        $this->assertSame('concluida', $os->status);
        $this->assertEquals($ap->fim->getTimestamp(), $os->concluida_em->getTimestamp());

        // Solicitante vê a OS dele e dá o aceite.
        // O solicitante vê o pedido de avaliação; Ruim exige motivo e reabre; Excelente encerra.
        $this->actingAs($operador)->get('/ordens-de-servico/solicitacoes')->assertOk()->assertSee('esperando a sua avaliação');
        $this->get("/ordens-de-servico/ordens/{$os->id}")->assertOk()->assertSee('avalie o atendimento');
        $this->post("/ordens-de-servico/ordens/{$os->id}/avaliar", ['avaliacao' => 'ruim'])->assertSessionHasErrors('observacao');
        $this->post("/ordens-de-servico/ordens/{$os->id}/avaliar", ['avaliacao' => 'ruim', 'observacao' => 'Ainda vaza óleo'])->assertRedirect();
        $this->assertSame('em_execucao', $os->fresh()->status);
        $this->assertSame('ruim', $os->fresh()->avaliacao);
        $this->get("/ordens-de-servico/ordens/{$os->id}")->assertOk()->assertSee('Ainda vaza óleo');
        $this->actingAs($mecanico)->post("/ordens-de-servico/ordens/{$os->id}/acao", ['acao' => 'concluir'])->assertRedirect();
        $this->actingAs($operador)->post("/ordens-de-servico/ordens/{$os->id}/avaliar", ['avaliacao' => 'excelente'])->assertRedirect();
        $this->assertSame('encerrada', $os->fresh()->status);
        $this->assertSame('excelente', $os->fresh()->avaliacao);
        $this->assertSame($operador->id, $os->fresh()->aceite_por_id);
        // Histórico mostra a abertura da SS e as avaliações; linha do tempo completa.
        $pagina = $this->get("/ordens-de-servico/ordens/{$os->id}")->assertOk()->assertSee('Avaliação: Excelente')->getContent();
        // Agora junto das interações, em balões e em ordem cronológica (do mais antigo para o mais novo).
        $historico = substr($pagina, strpos($pagina, '<h2>Interações e histórico</h2>'));
        $this->assertStringContainsString('chat-evento', $historico);
        $this->assertTrue(strpos($historico, 'SS aberta') < strpos($historico, 'SS aprovada'));
        $this->assertTrue(strpos($historico, 'SS aprovada') < strpos($historico, 'Avaliada como Ruim'));
        $this->assertTrue(strpos($historico, 'Avaliada como Ruim') < strpos($historico, 'Avaliada pelo solicitante'));
        foreach (['SS aberta', 'Aprovada', 'Em atendimento', 'Concluída', 'Avaliada'] as $marco) {
            $this->assertStringContainsString($marco, $pagina);
        }
        $this->get("/ordens-de-servico/ordens/{$os->id}/imprimir")->assertOk()->assertSee('Solicitante');
        $this->assertGreaterThanOrEqual(5, $os->historico()->count());
    }

    public function test_mantenedor_abre_e_aprova_e_fica_atribuido_com_outros(): void
    {
        $joao = $this->usuario(['mantenedor_producao', 'solicitante_manutencao'], 'joao@empresa.com');
        $pedro = $this->usuario(['mantenedor_producao'], 'pedro@empresa.com');
        $mJoao = Mantenedor::create(['nome' => 'Joao', 'usuario_id' => $joao->id]);
        $mPedro = Mantenedor::create(['nome' => 'Pedro', 'usuario_id' => $pedro->id]);

        $ss = $this->abrirSs($joao);
        // Na aprovação, o mantenedor logado já vem marcado.
        $this->get("/ordens-de-servico/solicitacoes/{$ss->id}")->assertOk()->assertSee('você — fica atribuído');
        // Mesmo sem se marcar, ele fica atribuído; e pode incluir outros.
        $this->post("/ordens-de-servico/solicitacoes/{$ss->id}/aprovar", ['mantenedores' => [$mPedro->id]])->assertRedirect();
        $os = OrdemServico::where('solicitacao_id', $ss->id)->firstOrFail();
        $this->assertEqualsCanonicalizing([$mJoao->id, $mPedro->id], $os->mantenedores()->pluck('os_mantenedores.id')->map(fn ($i) => (int) $i)->all());
        $this->assertSame($mJoao->id, $os->responsavel_id);
        // A OS aparece em "Apontar horas" dos dois.
        $this->actingAs($pedro)->get('/ordens-de-servico/minhas')->assertOk()->assertSee($os->numeroFormatado());

        // Aprovador que não é mantenedor pode deixar para definir depois e distribuir depois.
        $lider = $this->usuario(['admin_producao', 'solicitante_manutencao'], 'lider@empresa.com');
        $ss2 = $this->abrirSs($lider);
        $this->post("/ordens-de-servico/solicitacoes/{$ss2->id}/aprovar")->assertRedirect();
        $os2 = OrdemServico::where('solicitacao_id', $ss2->id)->firstOrFail();
        $this->assertSame(0, $os2->mantenedores()->count());
        $this->assertNull($os2->responsavel_id);
        $this->post("/ordens-de-servico/ordens/{$os2->id}/distribuir", ['mantenedores' => [$mPedro->id, $mJoao->id]])->assertRedirect();
        $this->assertSame(2, $os2->mantenedores()->count());
        $this->assertSame($mPedro->id, $os2->fresh()->responsavel_id);
    }

    public function test_mantenedor_aprova_e_atende_so_as_areas_do_cadastro(): void
    {
        $eletrica = AreaExecutante::create(['codigo' => 'ELE', 'nome' => 'Elétrica', 'unidade' => 'manutencao']);
        $operador = $this->usuario(['solicitante_manutencao'], 'operador@empresa.com', '1001');
        // Mecânico de duas áreas e eletricista que é do mesmo centro de custo do operador (vê pela regra do CC).
        $mecanico = $this->usuario(['mantenedor_producao'], 'mecanico@empresa.com');
        $mMec = Mantenedor::create(['nome' => 'Mecânico', 'usuario_id' => $mecanico->id]);
        $mMec->definirAreas([$this->mecanica->id, $eletrica->id]);
        $eletricista = $this->usuario(['mantenedor_producao'], 'eletricista@empresa.com', '1001');
        Mantenedor::create(['nome' => 'Eletricista', 'usuario_id' => $eletricista->id])->definirAreas([$eletrica->id]);

        $ss = $this->abrirSs($operador); // área Mecânica
        // O eletricista vê (mesmo CC), mas não aprova nem rejeita: não é da área.
        $this->actingAs($eletricista)->get("/ordens-de-servico/solicitacoes/{$ss->id}")->assertOk()->assertDontSee('Aprovar e emitir OS');
        $this->post("/ordens-de-servico/solicitacoes/{$ss->id}/aprovar")->assertForbidden();
        $this->post("/ordens-de-servico/solicitacoes/{$ss->id}/rejeitar", ['motivo_rejeicao' => 'x'])->assertForbidden();

        // O mecânico (Mecânica + Elétrica) aprova; a lista de mantenedores mostra só os da área Mecânica.
        $this->actingAs($mecanico)->get("/ordens-de-servico/solicitacoes/{$ss->id}")->assertOk()->assertSee('Aprovar e emitir OS')
            ->assertSee('da área Mecânica')->assertDontSee('Eletricista');
        $this->post("/ordens-de-servico/solicitacoes/{$ss->id}/aprovar")->assertRedirect();
        $os = OrdemServico::where('solicitacao_id', $ss->id)->firstOrFail();

        // O eletricista não inicia apontamento nem requisita material numa OS da Mecânica.
        $this->actingAs($eletricista)->post("/ordens-de-servico/ordens/{$os->id}/apontamentos/iniciar")->assertForbidden();
        $this->post("/ordens-de-servico/ordens/{$os->id}/materiais", ['descricao' => 'Fita', 'quantidade' => 1])->assertForbidden();
        $this->post("/ordens-de-servico/ordens/{$os->id}/acao", ['acao' => 'concluir'])->assertForbidden();
        // ...a não ser que seja atribuído à OS.
        $os->incluirMantenedor(Mantenedor::where('usuario_id', $eletricista->id)->value('id'));
        $this->post("/ordens-de-servico/ordens/{$os->id}/apontamentos/iniciar")->assertRedirect();
    }

    public function test_ferramentas_avulsas_e_amarradas_ao_mantenedor(): void
    {
        $admin = $this->usuario(['admin_producao'], 'adm@empresa.com');
        $maria = $this->usuario(['mantenedor_producao'], 'maria@empresa.com');
        $this->actingAs($admin);
        $this->post('/ordens-de-servico/cadastros/equipamentos', ['unidade' => 'manutencao', 'codigo' => 'fer-01', 'descricao' => 'Multímetro', 'ativo' => '1'])->assertRedirect();
        $this->post('/ordens-de-servico/cadastros/equipamentos', ['unidade' => 'manutencao', 'codigo' => 'FER-02', 'descricao' => 'Torquímetro', 'ativo' => '1'])->assertRedirect();
        $multimetro = Ferramenta::where('codigo', 'FER-01')->firstOrFail();
        $this->assertTrue($multimetro->avulsa());

        // No cadastro do mantenedor, as avulsas aparecem para amarrar.
        $this->get('/ordens-de-servico/cadastros/mantenedores/novo')->assertOk()->assertSee('FER-01 — Multímetro')->assertSee('Torquímetro');
        $this->post('/ordens-de-servico/cadastros/mantenedores', ['unidade' => 'manutencao', 'usuario_id' => $maria->id, 'jornada_inicio' => '07:00',
            'jornada_fim' => '17:00', 'intervalo_min' => 60, 'dias' => [1, 2, 3, 4, 5], 'areas' => [$this->mecanica->id],
            'ferramentas' => [$multimetro->id], 'ativo' => '1', 'calendario_id' => $this->jornada()['calendario_id']])->assertRedirect();
        $m = Mantenedor::where('usuario_id', $maria->id)->firstOrFail();
        $this->assertSame($m->id, $multimetro->fresh()->mantenedor_id);
        $this->assertNotNull($multimetro->fresh()->atribuida_em);
        $this->get('/ordens-de-servico/cadastros/equipamentos?situacao=avulsas')->assertOk()->assertSee('Torquímetro')->assertDontSee('Multímetro');
        $this->get('/ordens-de-servico/cadastros/equipamentos?situacao=com_mantenedor')->assertOk()->assertSee('Multímetro')->assertSee('Maria');
        $this->get('/ordens-de-servico/cadastros/mantenedores')->assertOk()->assertSee('1 equipamento');

        // Desmarcar no mantenedor devolve (fica avulsa); pelo cadastro da ferramenta também se troca o dono.
        $this->put("/ordens-de-servico/cadastros/mantenedores/{$m->id}", ['unidade' => 'manutencao', 'usuario_id' => $maria->id, 'jornada_inicio' => '07:00',
            'jornada_fim' => '17:00', 'intervalo_min' => 60, 'dias' => [1, 2, 3, 4, 5], 'areas' => [$this->mecanica->id], 'ativo' => '1'])->assertRedirect();
        $this->assertNull($multimetro->fresh()->mantenedor_id);
        $this->put("/ordens-de-servico/cadastros/equipamentos/{$multimetro->id}", ['unidade' => 'manutencao', 'codigo' => 'FER-01', 'descricao' => 'Multímetro',
            'mantenedor_id' => $m->id, 'ativo' => '1'])->assertRedirect();
        $this->assertSame($m->id, $multimetro->fresh()->mantenedor_id);
    }

    public function test_interacoes_com_anexos_so_para_quem_ve_a_os(): void
    {
        Storage::fake('local');
        $operador = $this->usuario(['solicitante_manutencao'], 'operador@empresa.com');
        $lider = $this->usuario(['admin_producao'], 'lider@empresa.com');
        $estranho = $this->usuario(['solicitante_manutencao'], 'estranho@empresa.com', '2002');
        $ss = $this->abrirSs($operador);
        $this->actingAs($lider)->post("/ordens-de-servico/solicitacoes/{$ss->id}/aprovar")->assertRedirect();
        $os = OrdemServico::where('solicitacao_id', $ss->id)->firstOrFail();

        // Vazio não vale; texto com link e print anexado vale.
        $this->actingAs($operador)->post("/ordens-de-servico/ordens/{$os->id}/interacoes", ['texto' => ' '])->assertSessionHasErrors('texto');
        $this->post("/ordens-de-servico/ordens/{$os->id}/interacoes", [
            'texto' => 'Segue o print, veja https://exemplo.com/manual',
            'anexos' => [UploadedFile::fake()->image('print.png', 200, 100), UploadedFile::fake()->create('laudo.pdf', 100, 'application/pdf')],
        ])->assertRedirect();
        $this->post("/ordens-de-servico/ordens/{$os->id}/interacoes", ['anexos' => [UploadedFile::fake()->create('virus.exe', 10)]])->assertSessionHasErrors('anexos.0');
        $this->actingAs($lider)->post("/ordens-de-servico/ordens/{$os->id}/interacoes", ['texto' => 'Recebido, vamos verificar.'])->assertRedirect();

        $pagina = $this->get("/ordens-de-servico/ordens/{$os->id}")->assertOk();
        $pagina->assertSee('Recebido, vamos verificar.')->assertSee('laudo.pdf')->assertSee('href="https://exemplo.com/manual"', false);
        $anexo = AnexoInteracaoOs::where('nome', 'print.png')->firstOrFail();
        Storage::disk('local')->assertExists($anexo->caminho);
        $this->get("/ordens-de-servico/anexos/{$anexo->id}")->assertOk();
        // Quem não vê a OS não comenta nem baixa anexo.
        $this->actingAs($estranho)->get("/ordens-de-servico/anexos/{$anexo->id}")->assertNotFound();
        $this->post("/ordens-de-servico/ordens/{$os->id}/interacoes", ['texto' => 'oi'])->assertNotFound();
        // Texto com HTML é escapado.
        $this->actingAs($operador)->post("/ordens-de-servico/ordens/{$os->id}/interacoes", ['texto' => '<script>alert(1)</script>'])->assertRedirect();
        $this->get("/ordens-de-servico/ordens/{$os->id}")->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_os_fechada_so_reabre_e_so_o_solicitante_avalia(): void
    {
        $operador = $this->usuario(['solicitante_manutencao'], 'operador@empresa.com');
        $adm = $this->usuario(['admin_producao'], 'adm@empresa.com');
        $mecanico = $this->usuario(['mantenedor_producao'], 'mecanico@empresa.com');
        $m = Mantenedor::create(['nome' => 'Mecânico', 'usuario_id' => $mecanico->id]);
        $m->definirAreas([$this->mecanica->id]);
        $ss = $this->abrirSs($operador);
        $this->actingAs($mecanico)->post("/ordens-de-servico/solicitacoes/{$ss->id}/aprovar")->assertRedirect();
        $os = OrdemServico::where('solicitacao_id', $ss->id)->firstOrFail();
        $this->post("/ordens-de-servico/ordens/{$os->id}/acao", ['acao' => 'concluir'])->assertRedirect();

        // Fechada (concluída): ninguém conversa, aponta, requisita material, edita nem distribui.
        $this->post("/ordens-de-servico/ordens/{$os->id}/interacoes", ['texto' => 'oi'])->assertSessionHasErrors('os');
        $this->post("/ordens-de-servico/ordens/{$os->id}/apontamentos/iniciar")->assertSessionHasErrors('os');
        $this->post("/ordens-de-servico/ordens/{$os->id}/materiais", ['descricao' => 'Fita', 'quantidade' => 1])->assertSessionHasErrors('os');
        // Nem o administrador altera os dados da OS (não existe mais edição da OS).
        $this->actingAs($adm)->put("/ordens-de-servico/ordens/{$os->id}", ['descricao' => 'mudou'])->assertStatus(405);
        $this->get("/ordens-de-servico/ordens/{$os->id}/editar")->assertNotFound();
        $this->post("/ordens-de-servico/ordens/{$os->id}/distribuir", ['mantenedores' => [$m->id]])->assertSessionHasErrors('os');
        $this->get("/ordens-de-servico/ordens/{$os->id}")->assertOk()->assertSee('reabra a OS')->assertDontSee('id="chat-form"', false);
        $this->assertSame('concluida', $os->fresh()->status);

        // Só o solicitante avalia: nem o administrador da área.
        $this->post("/ordens-de-servico/ordens/{$os->id}/avaliar", ['avaliacao' => 'boa'])->assertForbidden();
        $this->get("/ordens-de-servico/ordens/{$os->id}")->assertDontSee('avalie o atendimento');
        $this->actingAs($operador)->post("/ordens-de-servico/ordens/{$os->id}/avaliar", ['avaliacao' => 'boa'])->assertRedirect();
        $this->assertSame('encerrada', $os->fresh()->status);

        // Avaliada (encerrada): ninguém reabre, nem o administrador; o botão some.
        $this->actingAs($adm)->post("/ordens-de-servico/ordens/{$os->id}/acao", ['acao' => 'reabrir'])->assertSessionHasErrors('acao');
        $this->assertSame('encerrada', $os->fresh()->status);
        $this->get("/ordens-de-servico/ordens/{$os->id}")->assertOk()->assertSee('não pode ser reaberta')->assertDontSee('value="reabrir"', false);
        $this->post("/ordens-de-servico/ordens/{$os->id}/interacoes", ['texto' => 'Reaberta para ajuste'])->assertSessionHasErrors('os');
        $this->assertSame(0, $os->interacoes()->count());
    }

    public function test_painel_da_area_com_area_padrao(): void
    {
        $operador = $this->usuario(['solicitante_manutencao', 'solicitante_tecnologia'], 'operador@empresa.com');
        $mecanico = $this->usuario(['mantenedor_producao'], 'mecanico@empresa.com');
        $m = Mantenedor::create(['nome' => 'Mecânico', 'usuario_id' => $mecanico->id]);
        $m->definirAreas([$this->mecanica->id]);
        $adm = $this->usuario(['admin_producao', 'admin_tecnologia'], 'adm-ti@empresa.com');

        $pendente = $this->abrirSs($operador, ['titulo' => 'Pendente na fila']);
        $ss = $this->abrirSs($operador, ['titulo' => 'Atendida pelo mecânico']);
        $this->abrirSs($operador, ['titulo' => 'Chamado de TI', 'area_executante_id' => $this->ti->id, 'maquina_id' => '', 'centro_custo' => '2002']);
        $this->actingAs($mecanico)->post("/ordens-de-servico/solicitacoes/{$ss->id}/aprovar")->assertRedirect();
        $os = OrdemServico::where('solicitacao_id', $ss->id)->firstOrFail();
        $this->actingAs($operador)->post("/ordens-de-servico/ordens/{$os->id}/interacoes", ['texto' => 'Alguma previsão?'])->assertRedirect();

        // Mantenedor: painel da Manutenção, com a fila, a OS dele e a mensagem sem resposta.
        $this->actingAs($mecanico)->get('/ordens-de-servico/painel')->assertOk()
            ->assertSee('Painel — Manutenção')->assertSee('Pendente na fila')->assertSee('Alguma previsão?')->assertSee('Mecanico')
            ->assertDontSee('Chamado de TI')->assertDontSee('área padrão');
        // Respondida pelo mecânico: sai da lista.
        $this->post("/ordens-de-servico/ordens/{$os->id}/interacoes", ['texto' => 'Amanhã cedo.'])->assertRedirect();
        $this->get('/ordens-de-servico/painel')->assertDontSee('Alguma previsão?');

        // Quem tem as duas áreas escolhe e grava a área padrão.
        $this->actingAs($adm)->get('/ordens-de-servico/painel')->assertOk()->assertSee('Painel — Manutenção')->assertSee('Usar Manutenção como área padrão');
        $this->post('/ordens-de-servico/painel/area-padrao', ['unidade' => 'tecnologia'])->assertRedirect();
        $this->get('/ordens-de-servico/painel')->assertOk()->assertSee('Painel — Tecnologia')->assertSee('Chamado de TI')->assertDontSee('Pendente na fila');
        $this->get('/ordens-de-servico/painel?unidade=manutencao')->assertOk()->assertSee('Pendente na fila');

        // Solicitante sem perfil de gestão não vê o painel.
        $this->actingAs($operador)->get('/ordens-de-servico/painel')->assertForbidden();
        $this->assertNotNull($pendente->id);
    }

    public function test_aprovador_revisa_a_ss_no_detalhe_e_a_os_nao_muda_mais(): void
    {
        $operador = $this->usuario(['solicitante_manutencao'], 'operador@empresa.com');
        $adm = $this->usuario(['admin_producao'], 'adm@empresa.com');
        $mecanico = $this->usuario(['mantenedor_producao'], 'mecanico@empresa.com');
        $m = Mantenedor::create(['nome' => 'Mecânico', 'usuario_id' => $mecanico->id]);
        $m->definirAreas([$this->mecanica->id]);
        $ss = $this->abrirSs($operador);

        // O aprovador vê os campos editáveis no detalhe; "só salvar" mantém a SS pendente.
        $this->actingAs($adm)->get("/ordens-de-servico/solicitacoes/{$ss->id}")->assertOk()->assertSee('Revise a solicitação')->assertSee('name="titulo"', false);
        $campos = ['titulo' => 'Vazamento no cilindro principal', 'tipo' => 'corretiva', 'area_executante_id' => $this->mecanica->id,
            'maquina_id' => $this->injetora->id, 'prioridade' => 'alta', 'descricao' => 'Vazamento forte de óleo'];
        $this->post("/ordens-de-servico/solicitacoes/{$ss->id}/aprovar", $campos + ['acao' => 'salvar'])->assertRedirect();
        $this->assertSame('pendente', $ss->fresh()->status);
        $this->assertSame('alta', $ss->fresh()->prioridade);
        // Aprovar com novas alterações: a OS nasce com os dados revisados.
        $this->post("/ordens-de-servico/solicitacoes/{$ss->id}/aprovar", ['titulo' => 'Título final'] + $campos + ['acao' => 'aprovar', 'mantenedores' => [$m->id]])->assertRedirect();
        $os = OrdemServico::where('solicitacao_id', $ss->id)->firstOrFail();
        $this->assertSame('Título final', $os->titulo);
        $this->assertSame('alta', $os->prioridade);
        $this->assertTrue($ss->historico()->where('acao', 'alterada')->exists());

        // OS emitida: sem edição; só máquina parada, horas, mantenedores e mensagens enquanto aberta.
        $this->get("/ordens-de-servico/ordens/{$os->id}")->assertOk()->assertDontSee('/editar"', false);
        $this->actingAs($mecanico)->post("/ordens-de-servico/ordens/{$os->id}/maquina-parada", ['maquina_parada' => 0])->assertRedirect();
        $this->assertFalse($os->fresh()->maquina_parada);

        // Encerrar apontamento sem concluir mantém a OS aberta; com "Concluir a OS também", conclui.
        $this->post("/ordens-de-servico/ordens/{$os->id}/apontamentos/iniciar")->assertRedirect();
        $ap = ApontamentoHoras::where('ordem_servico_id', $os->id)->whereNull('fim')->firstOrFail();
        $this->post("/ordens-de-servico/apontamentos/{$ap->id}/encerrar", ['descricao' => 'Desmontado o cilindro'])->assertRedirect();
        $this->assertSame('em_execucao', $os->fresh()->status);
        $this->post("/ordens-de-servico/ordens/{$os->id}/apontamentos/iniciar")->assertRedirect();
        $ap2 = ApontamentoHoras::where('ordem_servico_id', $os->id)->whereNull('fim')->firstOrFail();
        $this->post("/ordens-de-servico/apontamentos/{$ap2->id}/encerrar", ['descricao' => 'Trocadas as vedações e testado', 'concluir_os' => '1'])->assertRedirect();
        $this->assertSame('concluida', $os->fresh()->status);
        $this->assertSame('Trocadas as vedações e testado', $os->fresh()->solucao);
        // Concluída: nem máquina parada muda mais.
        $this->post("/ordens-de-servico/ordens/{$os->id}/maquina-parada", ['maquina_parada' => 1])->assertSessionHasErrors('os');
    }

    public function test_area_ferramentaria_com_os_mesmos_perfis(): void
    {
        $ferr = AreaExecutante::create(['codigo' => 'MOL', 'nome' => 'Moldes', 'unidade' => 'ferramentaria']);
        $solicitante = $this->usuario(['solicitante_ferramentaria'], 'sf@empresa.com');
        $mantenedor = $this->usuario(['mantenedor_ferramentaria'], 'mf@empresa.com');
        $admF = $this->usuario(['admin_ferramentaria'], 'af@empresa.com');
        $mantProd = $this->usuario(['mantenedor_producao'], 'mp@empresa.com');

        // Solicitante de Ferramentaria só abre para as áreas da Ferramentaria.
        $this->actingAs($solicitante)->get('/ordens-de-servico/solicitacoes/nova')->assertOk()->assertSee('Moldes')->assertDontSee('Mecânica');
        $ss = $this->abrirSs($solicitante, ['area_executante_id' => $ferr->id, 'titulo' => 'Molde 12 com rebarba', 'maquina_id' => '', 'centro_custo' => '1001']);
        $this->assertSame('ferramentaria', $ss->unidade);
        // Mantenedor de Ferramentaria vê; o de Produção não; o admin de Ferramentaria aprova.
        $this->actingAs($mantenedor)->get("/ordens-de-servico/solicitacoes/{$ss->id}")->assertOk();
        $this->actingAs($mantProd)->get("/ordens-de-servico/solicitacoes/{$ss->id}")->assertNotFound();
        $this->actingAs($admF)->post("/ordens-de-servico/solicitacoes/{$ss->id}/aprovar")->assertRedirect();
        $this->assertSame('ferramentaria', OrdemServico::where('solicitacao_id', $ss->id)->value('unidade'));
        // Admin de Ferramentaria cadastra só na Ferramentaria e vê o painel dela.
        $this->assertSame(['ferramentaria'], $admF->unidadesParaCadastro());
        $this->get('/ordens-de-servico/painel')->assertOk()->assertSee('Painel — Ferramentaria');
        $this->get('/admin/maquinas')->assertOk();
    }

    public function test_na_abertura_so_as_maquinas_da_area_executante(): void
    {
        $servidor = Maquina::create(['linha_id' => $this->injetora->linha_id, 'codigo' => 'SRV-01', 'nome' => 'Servidor ERP', 'ativo' => true,
            'centro_custo' => '1001', 'unidade' => 'tecnologia']);
        $ambos = Maquina::create(['linha_id' => $this->injetora->linha_id, 'codigo' => 'NOB-01', 'nome' => 'Nobreak geral', 'ativo' => true,
            'centro_custo' => '1001', 'unidade' => 'ambos']);
        $ambos2 = $this->usuario(['solicitante_manutencao', 'solicitante_tecnologia'], 'duas@empresa.com');
        $soManutencao = $this->usuario(['solicitante_manutencao'], 'uma@empresa.com');

        // A tela leva a unidade de cada máquina e de cada área (o filtro é feito ao escolher a unidade) e só lista as
        // unidades em que a pessoa é solicitante.
        $this->actingAs($ambos2)->get('/ordens-de-servico/solicitacoes/nova')->assertOk()
            ->assertSee('data-unidade="tecnologia"', false)->assertSee('SRV-01')->assertSee('name="unidade"', false)
            ->assertSee('<option value="manutencao"', false)->assertSee('<option value="tecnologia"', false)->assertDontSee('<option value="ferramentaria"', false);
        // Área de outra unidade é recusada.
        $this->post('/ordens-de-servico/solicitacoes', ['unidade' => 'tecnologia', 'titulo' => 'x', 'tipo' => 'corretiva', 'area_executante_id' => $this->mecanica->id,
            'prioridade' => 'baixa', 'descricao' => 'x', 'centro_custo' => '1001'])->assertSessionHasErrors('area_executante_id');
        // Unidade que a pessoa não solicita é recusada.
        $this->actingAs($soManutencao)->post('/ordens-de-servico/solicitacoes', ['unidade' => 'tecnologia', 'titulo' => 'x', 'tipo' => 'corretiva',
            'area_executante_id' => $this->ti->id, 'prioridade' => 'baixa', 'descricao' => 'x', 'centro_custo' => '1001'])->assertSessionHasErrors('unidade');
        $this->actingAs($ambos2);
        // Máquina de outra área é recusada; a de "Todas" vale para qualquer área.
        $this->post('/ordens-de-servico/solicitacoes', ['titulo' => 'x', 'tipo' => 'corretiva', 'area_executante_id' => $this->mecanica->id,
            'maquina_id' => $servidor->id, 'prioridade' => 'baixa', 'descricao' => 'x'])->assertSessionHasErrors('maquina_id');
        $this->abrirSs($ambos2, ['area_executante_id' => $this->ti->id, 'maquina_id' => $ambos->id]);
        // Quem só solicita para a Manutenção nem recebe as máquinas da Tecnologia.
        $this->actingAs($soManutencao)->get('/ordens-de-servico/solicitacoes/nova')->assertOk()->assertSee('INJ-01')->assertDontSee('SRV-01');
    }

    public function test_rejeicao_volta_ao_solicitante_com_motivo(): void
    {
        $operador = $this->usuario(['operador', 'solicitante_manutencao', 'solicitante_tecnologia'], 'operador@empresa.com');
        $lider = $this->usuario(['admin_producao'], 'lider@empresa.com');
        $ss = $this->abrirSs($operador);

        $this->actingAs($lider)->post("/ordens-de-servico/solicitacoes/{$ss->id}/rejeitar", [])->assertSessionHasErrors('motivo_rejeicao');
        $this->post("/ordens-de-servico/solicitacoes/{$ss->id}/rejeitar", ['motivo_rejeicao' => 'Já existe a SS 12'])->assertRedirect();
        $this->assertSame('rejeitada', $ss->fresh()->status);
        $this->assertSame(0, OrdemServico::count());
        $this->actingAs($operador)->get("/ordens-de-servico/solicitacoes/{$ss->id}")->assertOk()->assertSee('Já existe a SS 12');
    }

    public function test_quem_ve_cada_solicitacao(): void
    {
        $operador = $this->usuario(['operador', 'solicitante_manutencao', 'solicitante_tecnologia'], 'operador@empresa.com', '1001 - Injeção');
        $colega = $this->usuario(['solicitante_manutencao'], 'colega@empresa.com', '1001'); // mesmo centro de custo
        $outro = $this->usuario(['operador', 'solicitante_manutencao'], 'outro@empresa.com', '2002');
        $mecanico = $this->usuario(['mantenedor_producao'], 'mecanico@empresa.com');
        Mantenedor::create(['nome' => 'Mecânico', 'usuario_id' => $mecanico->id])->definirAreas([$this->mecanica->id]);
        $eletrica = AreaExecutante::create(['codigo' => 'ELE', 'nome' => 'Elétrica', 'unidade' => 'manutencao']);
        $eletricista = $this->usuario(['mantenedor_producao'], 'eletricista@empresa.com');
        Mantenedor::create(['nome' => 'Eletricista', 'usuario_id' => $eletricista->id])->definirAreas([$eletrica->id]);
        $mantTi = $this->usuario(['mantenedor_tecnologia'], 'mt@empresa.com');
        $adminProd = $this->usuario(['admin_producao'], 'adm@empresa.com');

        $ssMec = $this->abrirSs($operador, ['maquina_id' => '', 'centro_custo' => '']); // sem máquina: CC do solicitante
        $this->assertSame('1001', $ssMec->centro_custo);
        $ssTi = $this->abrirSs($operador, ['area_executante_id' => $this->ti->id, 'maquina_id' => '', 'titulo' => 'Computador não liga']);
        $this->assertSame('tecnologia', $ssTi->unidade);

        $ver = fn (Usuario $u, SolicitacaoServico $ss) => $this->actingAs($u)->get("/ordens-de-servico/solicitacoes/{$ss->id}")->status();
        // Autor e colega do mesmo centro de custo veem; outro centro de custo não.
        $this->assertSame(200, $ver($operador, $ssMec));
        $this->assertSame(200, $ver($colega, $ssMec));
        $this->assertSame(404, $ver($outro, $ssMec));
        // Mantenedor vê as da área executante dele.
        $this->assertSame(200, $ver($mecanico, $ssMec));
        $this->assertSame(404, $ver($eletricista, $ssMec));
        $this->assertSame(404, $ver($mecanico, $ssTi));
        // Mantenedor de tecnologia sem cadastro de áreas vê as da unidade dele.
        $this->assertSame(200, $ver($mantTi, $ssTi));
        $this->assertSame(404, $ver($mantTi, $ssMec));
        // Administrador da área vê todas da unidade dele.
        $this->assertSame(200, $ver($adminProd, $ssMec));
        $this->assertSame(404, $ver($adminProd, $ssTi));
    }

    public function test_so_solicitante_da_area_abre_e_centro_de_custo_da_maquina_trava(): void
    {
        // Administrador abre SS para a área dele sem precisar do perfil Solicitante (Produção: só Manutenção).
        $adm = $this->usuario(['admin_producao'], 'adm-sem@empresa.com');
        $this->actingAs($adm)->get('/ordens-de-servico/solicitacoes/nova')->assertOk()->assertSee('Mecânica')->assertDontSee('Suporte de TI');
        $this->post('/ordens-de-servico/solicitacoes', ['titulo' => 'PC', 'tipo' => 'corretiva', 'area_executante_id' => $this->ti->id,
            'prioridade' => 'baixa', 'descricao' => 'x', 'centro_custo' => '2002'])->assertSessionHasErrors('area_executante_id');
        $this->assertNotNull($this->abrirSs($adm)->id);

        $soManutencao = $this->usuario(['solicitante_manutencao'], 'sm@empresa.com');
        // Não é solicitante de Tecnologia: não abre para a área de TI.
        $this->actingAs($soManutencao)->post('/ordens-de-servico/solicitacoes', ['titulo' => 'PC', 'tipo' => 'corretiva', 'area_executante_id' => $this->ti->id,
            'prioridade' => 'baixa', 'descricao' => 'x', 'centro_custo' => '2002'])->assertSessionHasErrors('area_executante_id');
        $this->get('/ordens-de-servico/solicitacoes/nova')->assertOk()->assertSee('Mecânica')->assertDontSee('Suporte de TI');

        // Máquina com CC: vale o da máquina, mesmo que venha outro.
        $ss = $this->abrirSs($soManutencao, ['centro_custo' => '2002']);
        $this->assertSame('1001', $ss->centro_custo);

        // Sem máquina e sem CC no cadastro do usuário: obrigatório escolher, e da lista.
        $base = ['titulo' => 'Porta', 'tipo' => 'servico_geral', 'area_executante_id' => $this->mecanica->id, 'prioridade' => 'baixa', 'descricao' => 'Porta emperrada'];
        $this->post('/ordens-de-servico/solicitacoes', $base)->assertSessionHasErrors('centro_custo');
        $this->post('/ordens-de-servico/solicitacoes', $base + ['centro_custo' => '9999'])->assertSessionHasErrors('centro_custo');
        $this->post('/ordens-de-servico/solicitacoes', $base + ['centro_custo' => '2002'])->assertRedirect();
        $this->assertSame('2002', SolicitacaoServico::latest('id')->value('centro_custo'));
    }

    public function test_cancelar_e_reabrir_so_autor_ou_administrador_da_area(): void
    {
        $operador = $this->usuario(['solicitante_manutencao'], 'operador@empresa.com');
        $mecanico = $this->usuario(['mantenedor_producao', 'solicitante_manutencao'], 'mecanico@empresa.com');
        Mantenedor::create(['nome' => 'Mecânico', 'usuario_id' => $mecanico->id])->definirAreas([$this->mecanica->id]);
        $adminProd = $this->usuario(['admin_producao'], 'adm@empresa.com');

        // Mantenedor não cancela a SS de outro.
        $ss = $this->abrirSs($operador);
        $this->actingAs($mecanico)->post("/ordens-de-servico/solicitacoes/{$ss->id}/cancelar")->assertForbidden();
        // Mas cancela a que ele mesmo abriu.
        $propria = $this->abrirSs($mecanico);
        $this->post("/ordens-de-servico/solicitacoes/{$propria->id}/cancelar")->assertRedirect();
        $this->assertSame('cancelada', $propria->fresh()->status);

        // OS: mantenedor aprova e executa, mas não cancela nem reabre; o administrador da área sim.
        $this->post("/ordens-de-servico/solicitacoes/{$ss->id}/aprovar")->assertRedirect();
        $os = OrdemServico::where('solicitacao_id', $ss->id)->firstOrFail();
        $this->post("/ordens-de-servico/ordens/{$os->id}/acao", ['acao' => 'cancelar', 'observacao' => 'não precisa'])->assertForbidden();
        $this->get("/ordens-de-servico/ordens/{$os->id}")->assertOk()->assertDontSee('Cancelar OS');
        $this->post("/ordens-de-servico/ordens/{$os->id}/acao", ['acao' => 'concluir'])->assertRedirect();
        $this->post("/ordens-de-servico/ordens/{$os->id}/acao", ['acao' => 'reabrir'])->assertForbidden();
        $this->actingAs($adminProd)->post("/ordens-de-servico/ordens/{$os->id}/acao", ['acao' => 'reabrir'])->assertRedirect();
        $this->assertSame('em_execucao', $os->fresh()->status);
        // O autor da solicitação também pode cancelar.
        $this->actingAs($operador)->post("/ordens-de-servico/ordens/{$os->id}/acao", ['acao' => 'cancelar', 'observacao' => 'resolvido sozinho'])->assertRedirect();
        $this->assertSame('cancelada', $os->fresh()->status);
    }

    public function test_preventiva_gera_os_com_checklist_uma_vez_por_data(): void
    {
        $admin = $this->usuario(['admin_producao'], 'adm@empresa.com');
        $this->actingAs($admin)->post('/ordens-de-servico/preventivas', [
            'unidade' => 'manutencao', 'codigo' => 'prev-inj', 'descricao' => 'Lubrificação da injetora', 'maquina_id' => $this->injetora->id,
            'area_executante_id' => $this->mecanica->id, 'prioridade' => 'baixa', 'periodicidade' => 'meses', 'meses' => range(1, 12),
            'dia_mes' => 10, 'antecedencia_dias' => 7, 'ativo' => '1',
            'itens' => [['descricao' => 'Verificar nível de óleo'], ['descricao' => 'Lubrificar guias'], ['descricao' => '']],
        ])->assertRedirect('/ordens-de-servico/preventivas');
        $plano = PlanoPreventiva::where('codigo', 'PREV-INJ')->firstOrFail();
        $this->assertSame(2, $plano->itens()->count());

        $plano->forceFill(['proxima_em' => '2026-11-10'])->save();
        $gerador = app(GeradorPreventivas::class);
        $criadas = $gerador->gerar(CarbonImmutable::parse('2026-11-05', config('oee.fuso_exibicao')));
        $this->assertCount(1, $criadas);
        $this->assertSame(2, $criadas[0]->checklist()->count());
        $this->assertSame('2026-12-10', $plano->fresh()->proxima_em->format('Y-m-d'));
        // Rodar de novo no mesmo dia não duplica.
        $this->assertCount(0, $gerador->gerar(CarbonImmutable::parse('2026-11-05', config('oee.fuso_exibicao'))));

        $this->get('/ordens-de-servico/preventivas')->assertOk()->assertSee('PREV-INJ');
    }

    public function test_telas_de_relatorio_e_cadastros(): void
    {
        $admin = $this->usuario(['admin_producao', 'solicitante_manutencao'], 'adm@empresa.com');
        $this->actingAs($admin);
        foreach (['/ordens-de-servico/ordens', '/ordens-de-servico/materiais', '/ordens-de-servico/relatorios/horas', '/ordens-de-servico/relatorios/setores',
            '/ordens-de-servico/cadastros/areas', '/ordens-de-servico/cadastros/mantenedores', '/ordens-de-servico/cadastros/mantenedores/novo',
            '/ordens-de-servico/cadastros/sugestoes', '/ordens-de-servico/cadastros/equipamentos', '/ordens-de-servico/cadastros/equipamentos/novo', '/ordens-de-servico/solicitacoes/nova', '/ordens-de-servico/preventivas/nova'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/ordens-de-servico/relatorios/horas?formato=csv')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        // Mantenedor vem do cadastro de usuários (nome, foto, e-mail) e atende várias áreas.
        $maria = $this->usuario(['mantenedor_producao'], 'maria@empresa.com');
        $this->actingAs($admin)->post('/ordens-de-servico/cadastros/mantenedores', ['unidade' => 'manutencao', 'jornada_inicio' => '06:00',
            'jornada_fim' => '14:00', 'intervalo_min' => 60, 'dias' => [1, 2, 3, 4, 5], 'ativo' => '1'])->assertSessionHasErrors(['usuario_id', 'areas']);
        $this->post('/ordens-de-servico/cadastros/mantenedores', ['unidade' => 'manutencao', 'usuario_id' => $maria->id, 'jornada_inicio' => '06:00',
            'jornada_fim' => '14:00', 'intervalo_min' => 60, 'dias' => [1, 2, 3, 4, 5], 'areas' => [$this->mecanica->id, $this->ti->id], 'ativo' => '1', 'calendario_id' => $this->jornada()['calendario_id']])->assertRedirect();
        $m = Mantenedor::where('usuario_id', $maria->id)->firstOrFail();
        $this->assertSame('Maria', $m->nome);
        $this->assertSame('12345', $m->dias_semana);
        $this->assertSame(2, $m->areas()->count());
        $this->assertSame($this->mecanica->id, $m->area_executante_id);
        $this->get('/ordens-de-servico/cadastros/mantenedores')->assertOk()->assertSee('maria@empresa.com')->assertSee('Suporte de TI');

        // Operador sem o perfil Solicitante não vê o menu de SS; com ele, vê e abre, mas não relatórios nem cadastros.
        $this->actingAs($this->usuario(['operador'], 'op@empresa.com'));
        $this->get('/')->assertDontSee('Solicitações (SS)');
        $this->get('/ordens-de-servico/solicitacoes/nova')->assertForbidden();
        $this->actingAs($this->usuario(['operador', 'solicitante_manutencao'], 'op2@empresa.com'));
        $this->get('/')->assertSee('Solicitações (SS)');
        $this->get('/ordens-de-servico/solicitacoes/nova')->assertOk()->assertSee('Título resumido');
        $this->get('/ordens-de-servico/relatorios/horas')->assertForbidden();
        $this->get('/ordens-de-servico/cadastros/areas')->assertForbidden();
    }

    public function test_lista_de_os_so_das_unidades_do_login_com_filtro_de_unidade_e_data(): void
    {
        $base = ['tipo' => 'corretiva', 'prioridade' => 'media', 'status' => 'aberta'];
        $manut = OrdemServico::create($base + ['unidade' => 'manutencao', 'area_executante_id' => $this->mecanica->id, 'descricao' => 'OS da manutencao', 'titulo' => 'Prensa travada']);
        $ti = OrdemServico::create($base + ['unidade' => 'tecnologia', 'area_executante_id' => $this->ti->id, 'descricao' => 'OS de TI', 'titulo' => 'Servidor lento']);
        $ti->forceFill(['criado_em' => now()->subDays(10)])->saveQuietly();

        // Só Tecnologia (até administradora): não vê a OS da Manutenção, nem pela URL, e não tem o filtro de unidade.
        $rafa = $this->usuario(['solicitante_tecnologia', 'mantenedor_tecnologia', 'admin_tecnologia'], 'rafaela@empresa.com');
        $this->actingAs($rafa)->get('/ordens-de-servico/ordens')->assertOk()->assertSee('Servidor lento')->assertDontSee('Prensa travada')
            ->assertDontSee('<select name="unidade">', false);
        $this->get("/ordens-de-servico/ordens/{$manut->id}")->assertNotFound();
        $this->get('/ordens-de-servico/solicitacoes/nova')->assertOk()->assertDontSee($this->mecanica->nome);

        // Duas unidades: filtra por unidade e pela data de emissão.
        $adm = $this->usuario(['admin_producao', 'admin_tecnologia'], 'adm@empresa.com');
        $this->actingAs($adm)->get('/ordens-de-servico/ordens')->assertSee('Servidor lento')->assertSee('Prensa travada')->assertSee('<select name="unidade">', false);
        $this->get('/ordens-de-servico/ordens?unidade=manutencao')->assertSee('Prensa travada')->assertDontSee('Servidor lento');
        $this->get('/ordens-de-servico/ordens?unidade=ferramentaria')->assertSee('Prensa travada')->assertSee('Servidor lento'); // fora do login = ignorado
        $hoje = now(config('oee.fuso_exibicao'))->format('Y-m-d');
        $this->get('/ordens-de-servico/ordens?de='.$hoje)->assertSee('Prensa travada')->assertDontSee('Servidor lento');
        $this->get('/ordens-de-servico/ordens?ate='.now(config('oee.fuso_exibicao'))->subDays(5)->format('Y-m-d'))->assertSee('Servidor lento')->assertDontSee('Prensa travada');
    }

    public function test_prioridades_so_alta_e_baixa_e_antigas_ainda_aparecem(): void
    {
        $sol = $this->usuario(['solicitante_manutencao'], 'sol@empresa.com');
        $this->actingAs($sol)->get('/ordens-de-servico/solicitacoes/nova')->assertOk()
            ->assertSee('<option value="alta"', false)->assertSee('<option value="baixa"', false)
            ->assertDontSee('<option value="media"', false)->assertDontSee('<option value="urgente"', false);
        $this->post('/ordens-de-servico/solicitacoes', ['unidade' => 'manutencao', 'titulo' => 'x', 'tipo' => 'corretiva', 'area_executante_id' => $this->mecanica->id,
            'prioridade' => 'urgente', 'descricao' => 'x', 'centro_custo' => '1001'])->assertSessionHasErrors('prioridade');

        // Gravada antes com a prioridade antiga: continua aparecendo.
        $antiga = OrdemServico::create(['unidade' => 'manutencao', 'tipo' => 'corretiva', 'area_executante_id' => $this->mecanica->id,
            'prioridade' => 'urgente', 'descricao' => 'Antiga', 'solicitante_id' => $sol->id, 'status' => 'aberta']);
        $this->assertSame('Urgente (antiga)', $antiga->rotuloPrioridade());
        $this->assertSame('alta', SolicitacaoServico::prioridadeAtual('urgente'));
        $this->assertSame('baixa', SolicitacaoServico::prioridadeAtual('media'));
    }

    public function test_horas_por_setor_separadas_por_unidade(): void
    {
        $base = ['tipo' => 'corretiva', 'prioridade' => 'media', 'status' => 'aberta'];
        $manut = OrdemServico::create($base + ['unidade' => 'manutencao', 'area_executante_id' => $this->mecanica->id, 'descricao' => 'x', 'titulo' => 'Prensa', 'centro_custo' => '110101']);
        $ti = OrdemServico::create($base + ['unidade' => 'tecnologia', 'area_executante_id' => $this->ti->id, 'descricao' => 'y', 'titulo' => 'Servidor', 'centro_custo' => '220202']);
        $inicio = now()->startOfMonth()->addDays(2)->setTime(12, 0);
        $mant = ['manutencao' => Mantenedor::create(['nome' => 'Joao', 'unidade' => 'manutencao']),
            'tecnologia' => Mantenedor::create(['nome' => 'Rafa', 'unidade' => 'tecnologia'])];
        foreach ([[$manut, 90], [$ti, 45]] as [$os, $min]) {
            ApontamentoHoras::create(['ordem_servico_id' => $os->id, 'mantenedor_id' => $mant[$os->unidade]->id, 'inicio' => $inicio, 'fim' => $inicio->copy()->addMinutes($min),
                'minutos_normais' => $min, 'minutos_extras' => 0]);
        }

        $adm = $this->usuario(['admin_producao', 'admin_tecnologia'], 'adm@empresa.com');
        $this->actingAs($adm)->get('/ordens-de-servico/relatorios/setores?mes='.now()->format('Y-m'))->assertOk()
            ->assertSee('Resumo por unidade')->assertSee('id="unidade-manutencao"', false)->assertSee('id="unidade-tecnologia"', false)
            ->assertSeeInOrder(['Subtotal Manutenção', '1:30', 'Subtotal Tecnologia', '0:45']);
        // Só Tecnologia: não vê a seção da Manutenção.
        $this->actingAs($this->usuario(['admin_tecnologia'], 'rafa@empresa.com'))->get('/ordens-de-servico/relatorios/setores?mes='.now()->format('Y-m'))
            ->assertOk()->assertSee('id="unidade-tecnologia"', false)->assertDontSee('id="unidade-manutencao"', false);
    }

    public function test_relatorio_de_horas_filtra_unidade_e_area_executante_e_mostra_a_area(): void
    {
        $base = ['tipo' => 'corretiva', 'prioridade' => 'alta', 'status' => 'aberta', 'descricao' => 'x'];
        $manut = OrdemServico::create($base + ['unidade' => 'manutencao', 'area_executante_id' => $this->mecanica->id, 'centro_custo' => '110101']);
        $ti = OrdemServico::create($base + ['unidade' => 'tecnologia', 'area_executante_id' => $this->ti->id, 'centro_custo' => '220202']);
        $inicio = now()->setTime(12, 0);
        $m = Mantenedor::create(['nome' => 'Joao']);
        foreach ([$manut, $ti] as $os) {
            ApontamentoHoras::create(['ordem_servico_id' => $os->id, 'mantenedor_id' => $m->id, 'inicio' => $inicio, 'fim' => $inicio->copy()->addHour(),
                'minutos_normais' => 60, 'minutos_extras' => 0]);
        }
        $url = '/ordens-de-servico/relatorios/horas';
        $this->actingAs($this->usuario(['admin_producao', 'admin_tecnologia'], 'adm-h@empresa.com'));
        $this->get($url)->assertOk()->assertSee('Área executante')->assertSee('name="unidade"', false)
            ->assertSee($manut->numeroFormatado())->assertSee($ti->numeroFormatado())->assertSee('Mecânica')->assertSee('Suporte de TI');
        $this->get($url.'?unidade=tecnologia')->assertOk()->assertSee($ti->numeroFormatado())->assertDontSee($manut->numeroFormatado());
        $this->get($url.'?area='.$this->mecanica->id)->assertOk()->assertSee($manut->numeroFormatado())->assertDontSee($ti->numeroFormatado());
        $csv = $this->get($url.'?formato=csv&area='.$this->ti->id)->assertOk()->streamedContent();
        $this->assertStringContainsString('Suporte de TI', $csv);
        $this->assertStringNotContainsString('Mecânica', $csv);
        // Unidade fora do login é ignorada (e só a Tecnologia aparece).
        $this->actingAs($this->usuario(['admin_tecnologia'], 'rafa-h@empresa.com'));
        $this->get($url.'?unidade=manutencao')->assertOk()->assertSee($ti->numeroFormatado())->assertDontSee($manut->numeroFormatado());
    }

    public function test_painel_tempos_de_atendimento_e_reparo_por_periodo_e_aba_ferramentaria(): void
    {
        $adm = $this->usuario(['admin_producao', 'admin_ferramentaria'], 'adm-pf@empresa.com');
        $m = Mantenedor::create(['nome' => 'Mecânico']);
        $hoje = now(config('oee.fuso_exibicao'))->startOfDay()->setTime(8, 0)->utc();
        $ss = SolicitacaoServico::create(['unidade' => 'manutencao', 'solicitante_id' => $adm->id, 'titulo' => 't', 'tipo' => 'corretiva',
            'area_executante_id' => $this->mecanica->id, 'prioridade' => 'alta', 'descricao' => 'x', 'status' => 'aprovada']);
        $ss->forceFill(['criado_em' => $hoje])->saveQuietly();
        $os = OrdemServico::create(['unidade' => 'manutencao', 'solicitacao_id' => $ss->id, 'tipo' => 'corretiva', 'area_executante_id' => $this->mecanica->id,
            'prioridade' => 'alta', 'descricao' => 'x', 'solicitante_id' => $adm->id, 'status' => 'concluida', 'concluida_em' => $hoje->copy()->addHours(3)]);
        // Abertura 08:00 → 1º apontamento 08:30 (atendimento 30 min) → conclusão 11:00 (reparo 2 h 30 min).
        ApontamentoHoras::create(['ordem_servico_id' => $os->id, 'mantenedor_id' => $m->id,
            'inicio' => $hoje->copy()->addMinutes(30), 'fim' => $hoje->copy()->addHours(2), 'minutos_normais' => 90, 'minutos_extras' => 0]);
        ApontamentoHoras::create(['ordem_servico_id' => $os->id, 'mantenedor_id' => $m->id,
            'inicio' => $hoje->copy()->addHours(2), 'fim' => $hoje->copy()->addHours(3), 'minutos_normais' => 60, 'minutos_extras' => 0]);

        $dados = app(PainelManutencao::class)->dados($adm, 'manutencao');
        $this->assertEquals(30, $dados['tempoMedioMin']);
        $this->assertEquals(150, $dados['tempoReparoMin']);
        $this->assertSame(now(config('oee.fuso_exibicao'))->startOfMonth()->format('Y-m-d'), $dados['periodoDe']->format('Y-m-d')); // padrão: mês atual

        $this->actingAs($adm)->get('/ordens-de-servico/painel?unidade=manutencao')->assertOk()
            ->assertSee('Tempo médio de reparo')->assertSee('2 h 30 min')->assertSee('Ferramentaria')->assertSee('name="de"', false);
        // Período sem a OS: sem tempos.
        $this->get('/ordens-de-servico/painel?unidade=manutencao&de=2020-01-01&ate=2020-01-31')->assertOk()->assertDontSee('2 h 30 min');
        $this->get('/ordens-de-servico/painel?unidade=ferramentaria')->assertOk()->assertSee('Painel — Ferramentaria');
    }

    public function test_painel_por_solicitante_e_area_conta_todas_as_os_do_periodo(): void
    {
        $adm = $this->usuario(['admin_producao'], 'adm-sol@empresa.com');
        $base = ['unidade' => 'manutencao', 'tipo' => 'corretiva', 'prioridade' => 'alta', 'descricao' => 'x', 'solicitante_id' => $adm->id, 'area_executante_id' => $this->mecanica->id];
        foreach (['aberta', 'encerrada', 'cancelada'] as $st) {
            OrdemServico::create($base + ['status' => $st]);
        }
        $antiga = OrdemServico::create($base + ['status' => 'concluida']);
        $antiga->forceFill(['criado_em' => now()->subYears(2)])->saveQuietly();

        $dados = app(PainelManutencao::class)->dados($adm, 'manutencao');
        $linha = $dados['porSolicitante']->first();
        $this->assertSame(3, $linha['total']); // a de 2 anos atrás fica fora do período
        $this->assertSame([1, 1, 1], [$linha['porStatus']['aberta'], $linha['porStatus']['concluida'], $linha['porStatus']['cancelada']]);
        $this->assertSame(3, $dados['porArea']['Mecânica']);
        $this->actingAs($adm)->get('/ordens-de-servico/painel?unidade=manutencao')->assertOk()->assertSee('OS por solicitante')->assertSee('concluída');
    }

    public function test_metas_da_area_tempos_por_area_e_mes_e_area_obrigatoria_na_conclusao(): void
    {
        $adm = $this->usuario(['admin_producao'], 'adm-metas@empresa.com');
        $this->actingAs($adm)->put("/ordens-de-servico/cadastros/areas/{$this->mecanica->id}", ['unidade' => 'manutencao', 'codigo' => 'MEC', 'nome' => 'Mecânica',
            'ativo' => 1, 'meta_atendimento' => '0:20', 'meta_reparo' => '17:01'])->assertSessionHasNoErrors();
        $this->assertSame([20, 1021], [$this->mecanica->fresh()->meta_atendimento_min, $this->mecanica->fresh()->meta_reparo_min]);
        $this->put("/ordens-de-servico/cadastros/areas/{$this->mecanica->id}", ['unidade' => 'manutencao', 'codigo' => 'MEC', 'nome' => 'Mecânica',
            'ativo' => 1, 'meta_atendimento' => '1h30'])->assertSessionHasErrors('meta_atendimento');
        $this->get('/ordens-de-servico/cadastros/areas')->assertSee('value="17:01"', false);

        // OS concluída: atendimento 30 min (acima da meta de 20) e reparo 2 h 30 (dentro).
        $m = Mantenedor::create(['nome' => 'Mecânico']);
        $mesPassado = now(config('oee.fuso_exibicao'))->subMonthNoOverflow()->startOfMonth()->setTime(8, 0)->utc();
        $os = OrdemServico::create(['unidade' => 'manutencao', 'tipo' => 'corretiva', 'area_executante_id' => $this->mecanica->id,
            'prioridade' => 'alta', 'descricao' => 'x', 'solicitante_id' => $adm->id, 'status' => 'encerrada', 'concluida_em' => $mesPassado->copy()->addHours(3)]);
        $os->forceFill(['criado_em' => $mesPassado])->saveQuietly();
        ApontamentoHoras::create(['ordem_servico_id' => $os->id, 'mantenedor_id' => $m->id,
            'inicio' => $mesPassado->copy()->addMinutes(30), 'fim' => $mesPassado->copy()->addHours(3), 'minutos_normais' => 150, 'minutos_extras' => 0]);
        $de = now(config('oee.fuso_exibicao'))->subMonthNoOverflow()->startOfMonth()->format('Y-m-d');
        $pagina = $this->get("/ordens-de-servico/painel?unidade=manutencao&de={$de}")->assertOk()
            ->assertSee('Tempos de atendimento e reparo')->assertSee('Por área executante acionada')->assertSee('acima da meta')
            ->assertSee('Tempos mês a mês × meta')->assertSee('class="grafico-tempos"', false)->getContent();
        $this->assertStringContainsString('Mecânica', substr($pagina, strpos($pagina, 'Por área executante acionada')));
        // Só o mês atual: sem o gráfico mês a mês.
        $this->get('/ordens-de-servico/painel?unidade=manutencao')->assertOk()->assertDontSee('Tempos mês a mês × meta');

        // Concluir pela tela pede a área executante (vem a da OS; aceita só da mesma unidade).
        $aberta = OrdemServico::create(['unidade' => 'manutencao', 'tipo' => 'corretiva', 'area_executante_id' => $this->mecanica->id,
            'prioridade' => 'baixa', 'descricao' => 'y', 'solicitante_id' => $adm->id, 'status' => 'em_execucao']);
        $this->get("/ordens-de-servico/ordens/{$aberta->id}")->assertSee('Área executante que atendeu');
        $this->post("/ordens-de-servico/ordens/{$aberta->id}/acao", ['acao' => 'concluir', 'area_executante_id' => $this->ti->id])->assertSessionHasErrors('area_executante_id');
        $this->post("/ordens-de-servico/ordens/{$aberta->id}/acao", ['acao' => 'concluir', 'area_executante_id' => $this->mecanica->id])->assertSessionHasNoErrors();
        $this->assertSame('concluida', $aberta->fresh()->status);
    }
}
