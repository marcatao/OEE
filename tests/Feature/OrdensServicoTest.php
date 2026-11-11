<?php

namespace Tests\Feature;

use App\Models\CentroCusto;
use App\Models\Linha;
use App\Models\Manutencao\ApontamentoHoras;
use App\Models\Manutencao\AreaExecutante;
use App\Models\Manutencao\Mantenedor;
use App\Models\Manutencao\OrdemServico;
use App\Models\Manutencao\PlanoPreventiva;
use App\Models\Manutencao\SolicitacaoServico;
use App\Models\Maquina;
use App\Models\Planta;
use App\Models\Usuario;
use App\Services\Manutencao\GeradorPreventivas;
use App\Support\AcessoOs;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fluxo das Ordens de serviço: SS → aprovação → OS → horas → conclusão → aceite; rejeição; unidades; PAMP; relatórios.
 */
class OrdensServicoTest extends TestCase
{
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
        ])->assertRedirect();

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

        // Mecânico inicia, requisita material e encerra o apontamento.
        $this->actingAs($mecanico)->get('/ordens-de-servico/minhas')->assertOk()->assertSee($os->numeroFormatado());
        $this->post("/ordens-de-servico/ordens/{$os->id}/apontamentos/iniciar")->assertRedirect();
        $this->assertSame('em_execucao', $os->fresh()->status);
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
        $this->actingAs($operador)->get("/ordens-de-servico/ordens/{$os->id}")->assertOk()->assertSee('Aceitar o serviço');
        $this->post("/ordens-de-servico/ordens/{$os->id}/aceite", ['resultado' => 'aceito'])->assertRedirect();
        $this->assertSame('encerrada', $os->fresh()->status);
        $this->assertSame($operador->id, $os->fresh()->aceite_por_id);
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
        // Administrador sem o perfil Solicitante não abre SS (vale o que está marcado no cadastro do usuário).
        $this->actingAs($this->usuario(['admin_producao'], 'adm-sem@empresa.com'))->get('/ordens-de-servico/solicitacoes/nova')->assertForbidden();

        $soManutencao = $this->usuario(['solicitante_manutencao'], 'sm@empresa.com');
        // Não é solicitante de Tecnologia: não abre para a área de TI.
        $this->actingAs($soManutencao)->post('/ordens-de-servico/solicitacoes', ['titulo' => 'PC', 'tipo' => 'corretiva', 'area_executante_id' => $this->ti->id,
            'prioridade' => 'media', 'descricao' => 'x', 'centro_custo' => '2002'])->assertSessionHasErrors('area_executante_id');
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
            'area_executante_id' => $this->mecanica->id, 'prioridade' => 'media', 'periodicidade' => 'meses', 'meses' => range(1, 12),
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
            '/ordens-de-servico/cadastros/sugestoes', '/ordens-de-servico/solicitacoes/nova', '/ordens-de-servico/preventivas/nova'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/ordens-de-servico/relatorios/horas?formato=csv')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        // Mantenedor vem do cadastro de usuários (nome, foto, e-mail) e atende várias áreas.
        $maria = $this->usuario(['mantenedor_producao'], 'maria@empresa.com');
        $this->actingAs($admin)->post('/ordens-de-servico/cadastros/mantenedores', ['unidade' => 'manutencao', 'jornada_inicio' => '06:00',
            'jornada_fim' => '14:00', 'intervalo_min' => 60, 'dias' => [1, 2, 3, 4, 5], 'ativo' => '1'])->assertSessionHasErrors(['usuario_id', 'areas']);
        $this->post('/ordens-de-servico/cadastros/mantenedores', ['unidade' => 'manutencao', 'usuario_id' => $maria->id, 'jornada_inicio' => '06:00',
            'jornada_fim' => '14:00', 'intervalo_min' => 60, 'dias' => [1, 2, 3, 4, 5], 'areas' => [$this->mecanica->id, $this->ti->id], 'ativo' => '1'])->assertRedirect();
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
}
