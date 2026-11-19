<?php

namespace Tests\Feature;

use App\Models\Apontamento;
use App\Models\ApontamentoEstado;
use App\Models\Assistente\Conversa;
use App\Models\Assistente\Pergunta;
use App\Models\Grupo;
use App\Models\Legenda;
use App\Models\Linha;
use App\Models\Maquina;
use App\Models\OrdemProducao;
use App\Models\Planta;
use App\Models\ProdutoProtheus;
use App\Models\Usuario;
use App\Services\Assistente\ClienteClaude;
use App\Services\Assistente\FerramentasAssistente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * "Pergunte para Alumbra": box na página inicial, conversa em etapas com a Claude (cliente falso nos testes) e
 * consultas que só devolvem o que o usuário logado pode ver.
 */
class AssistenteTest extends TestCase
{
    use RefreshDatabase;

    private ClienteFalso $claude;

    protected function setUp(): void
    {
        parent::setUp();
        config(['assistente.chave' => 'chave-de-teste', 'assistente.limite_diario' => 10]);
        $this->claude = new ClienteFalso;
        $this->app->instance(ClienteClaude::class, $this->claude);

        $planta = Planta::create(['codigo' => 'P', 'nome' => 'Planta', 'unidade' => 'ambos']);
        $linha = Linha::create(['planta_id' => $planta->id, 'codigo' => 'L', 'nome' => 'Linha 1', 'unidade' => 'ambos']);
        Maquina::create(['linha_id' => $linha->id, 'codigo' => 'INJ-01', 'nome' => 'Injetora 01', 'ativo' => true]);
        Maquina::create(['linha_id' => $linha->id, 'codigo' => 'SRV-01', 'nome' => 'Servidor 01', 'ativo' => true, 'unidade' => 'tecnologia']);
    }

    private function usuario(array $perfis, string $email, array $permissoesGrupo = []): Usuario
    {
        $u = Usuario::create(['entra_oid' => "oid-{$email}", 'nome' => 'Fulano Silva', 'email' => $email]);
        $u->definirPerfis($perfis);
        if ($permissoesGrupo) {
            $grupo = Grupo::create(['nome' => 'Grupo '.$email, 'ativo' => true]);
            $grupo->definirPermissoes($permissoesGrupo);
            $grupo->usuarios()->attach($u->id);
        }

        return $u->fresh();
    }

    /** Pergunta + etapas até terminar (como a tela faz). */
    private function perguntar(string $texto, ?int $conversa = null): array
    {
        $r = $this->postJson('/assistente/perguntas', ['pergunta' => $texto, 'conversa_id' => $conversa])->assertOk();
        for ($i = 0; $i < 10; $i++) {
            $etapa = $this->postJson("/assistente/perguntas/{$r->json('pergunta_id')}/etapa")->assertOk()->json();
            if ($etapa['situacao'] !== 'continuar') {
                return $etapa + ['conversa_id' => $r->json('conversa_id'), 'pergunta_id' => $r->json('pergunta_id')];
            }
        }
        $this->fail('A pergunta não terminou.');
    }

    public function test_box_aparece_so_para_quem_tem_a_permissao(): void
    {
        $this->actingAs($this->usuario(['admin_producao'], 'adm@empresa.com'));
        $this->get('/')->assertOk()->assertSee('Pergunte para Alumbra')->assertSee('data-assistente', false)
            ->assertSee('js/assistente.js', false)->assertSee('Qual foi o OEE de cada máquina ontem?');

        // Operador (sem a permissão "assistente"): sem box e sem acesso às rotas.
        $this->actingAs($this->usuario(['operador'], 'op@empresa.com'));
        $this->get('/')->assertOk()->assertDontSee('Pergunte para Alumbra');
        $this->postJson('/assistente/perguntas', ['pergunta' => 'Oi'])->assertForbidden();

        // Liberado por grupo: aparece, com sugestões só das telas que ele tem.
        $this->actingAs($this->usuario(['operador'], 'op2@empresa.com', ['assistente']));
        $this->get('/')->assertOk()->assertSee('Pergunte para Alumbra')->assertDontSee('Qual foi o OEE de cada máquina ontem?')
            ->assertSee('Quais OPs abertas estão com o custo acima da meta?');
    }

    public function test_responde_em_etapas_com_os_dados_que_o_usuario_ve(): void
    {
        $this->actingAs($this->usuario(['admin_producao'], 'adm@empresa.com'));
        $this->claude->respostas = [
            ClienteFalso::consulta('listar_maquinas', []),
            ClienteFalso::texto("Há **1** máquina ativa:\n\n| Máquina | Situação |\n|---|---|\n| INJ-01 | - |"),
        ];

        $r = $this->perguntar('Quais máquinas temos?');
        $this->assertSame('concluida', $r['situacao']);
        $this->assertStringContainsString('INJ-01', $r['resposta']);

        // 1ª chamada: só as consultas permitidas, instruções com cache e contexto do usuário.
        $primeira = $this->claude->requisicoes[0];
        $nomes = array_column($primeira['tools'], 'name');
        $this->assertContains('indicadores_oee', $nomes);
        $this->assertContains('ordens_servico', $nomes);
        $this->assertSame(['type' => 'ephemeral'], $primeira['system'][0]['cache_control']);
        $this->assertStringContainsString('SÓ trata do portal', $primeira['system'][0]['text']);
        $this->assertStringContainsString('Administrador de Produção', $primeira['system'][1]['text']);
        $this->assertSame([['role' => 'user', 'content' => 'Quais máquinas temos?']], $primeira['messages']);

        // 2ª chamada: o resultado da consulta só tem a máquina da unidade dele (Manutenção), não a de Tecnologia.
        $resultado = end($this->claude->requisicoes[1]['messages'])['content'][0];
        $this->assertSame('tool_result', $resultado['type']);
        $this->assertStringContainsString('INJ-01', $resultado['content']);
        $this->assertStringNotContainsString('SRV-01', $resultado['content']);

        $pergunta = Pergunta::sole();
        $this->assertSame('concluida', $pergunta->situacao);
        $this->assertSame(2, $pergunta->etapas);
        $this->assertSame('listar_maquinas', $pergunta->ferramentas);
        $this->assertSame(200, $pergunta->tokens_entrada);
        $this->assertSame(1, Conversa::count());

        // A conversa continua na pergunta seguinte e volta ao recarregar a página.
        $this->claude->respostas = [ClienteFalso::texto('Continuando.')];
        $this->perguntar('E as paradas?', $r['conversa_id']);
        $this->assertCount(5, $this->claude->requisicoes[2]['messages']);
        $tela = $this->getJson('/assistente/conversa')->assertOk();
        $this->assertSame($r['conversa_id'], $tela->json('conversa_id'));
        $this->assertSame(['Quais máquinas temos?', 'E as paradas?'], array_column($tela->json('itens'), 'pergunta'));
        $this->assertSame('Continuando.', $tela->json('itens.1.resposta'));
        $this->assertSame(8, $tela->json('restantes'));
    }

    public function test_consulta_sem_permissao_nao_e_oferecida_nem_executada(): void
    {
        // Operador + grupo com o assistente: vê OPs e apontamentos, mas não o Dashboard (OEE) nem as OS.
        $this->actingAs($this->usuario(['operador'], 'op@empresa.com', ['assistente']));
        $this->claude->respostas = [
            ClienteFalso::consulta('indicadores_oee', ['de' => '2026-11-01']),
            ClienteFalso::texto('Seu perfil não dá acesso ao OEE.'),
        ];

        $r = $this->perguntar('Qual o OEE de ontem?');
        $this->assertSame('concluida', $r['situacao']);
        $nomes = array_column($this->claude->requisicoes[0]['tools'], 'name');
        $this->assertNotContains('indicadores_oee', $nomes);
        $this->assertNotContains('ordens_servico', $nomes);
        $this->assertContains('ordens_producao', $nomes);

        $resultado = end($this->claude->requisicoes[1]['messages'])['content'][0];
        $this->assertTrue($resultado['is_error']);
        $this->assertStringContainsString('não tem permissão', $resultado['content']);
    }

    public function test_ultima_etapa_responde_sem_novas_consultas(): void
    {
        config(['assistente.max_etapas' => 2]);
        $this->actingAs($this->usuario(['admin_producao'], 'adm@empresa.com'));
        $this->claude->respostas = [ClienteFalso::consulta('listar_maquinas', []), ClienteFalso::texto('Pronto.')];

        $this->assertSame('concluida', $this->perguntar('Máquinas?')['situacao']);
        $this->assertArrayNotHasKey('tool_choice', $this->claude->requisicoes[0]);
        $this->assertSame(['type' => 'none'], $this->claude->requisicoes[1]['tool_choice']);
    }

    public function test_erro_da_api_desfaz_a_pergunta_na_conversa(): void
    {
        $this->actingAs($this->usuario(['admin_producao'], 'adm@empresa.com'));
        $this->claude->respostas = [new RuntimeException('falhou')];

        $r = $this->perguntar('Quais máquinas?');
        $this->assertSame('erro', $r['situacao']);
        $this->assertSame('Não foi possível responder agora. Tente de novo.', $r['mensagem']);
        $this->assertSame([], Conversa::sole()->historico());
        $this->assertSame('erro', Pergunta::sole()->situacao);
    }

    public function test_recusa_vira_resposta_padrao(): void
    {
        $this->actingAs($this->usuario(['admin_producao'], 'adm@empresa.com'));
        $this->claude->respostas = [['content' => [], 'stop_reason' => 'refusal', 'model' => 'claude-opus-5-5', 'usage' => []]];

        $r = $this->perguntar('Algo fora do escopo');
        $this->assertStringContainsString('Não posso ajudar com esse pedido', $r['resposta']);
    }

    public function test_limites_chave_e_dono_da_pergunta(): void
    {
        $dono = $this->usuario(['admin_producao'], 'adm@empresa.com');
        $this->actingAs($dono);
        $this->claude->respostas = [ClienteFalso::texto('Oi!')];
        $r = $this->perguntar('Oi');

        // Outra pessoa não continua a pergunta de ninguém.
        $this->actingAs($this->usuario(['admin_producao'], 'outro@empresa.com'));
        $this->postJson("/assistente/perguntas/{$r['pergunta_id']}/etapa")->assertNotFound();
        // Conversa de outro usuário não é reaproveitada: vira uma nova.
        $nova = $this->postJson('/assistente/perguntas', ['pergunta' => 'Oi', 'conversa_id' => $r['conversa_id']])->assertOk();
        $this->assertNotSame($r['conversa_id'], $nova->json('conversa_id'));

        // Limite diário.
        config(['assistente.limite_diario' => 1]);
        $this->actingAs($dono);
        $this->postJson('/assistente/perguntas', ['pergunta' => 'De novo'])->assertStatus(429)
            ->assertJson(['mensagem' => 'Você atingiu o limite de perguntas de hoje. Amanhã tem mais.']);

        // Sem chave da API: avisa.
        config(['assistente.limite_diario' => 0, 'assistente.chave' => null]);
        $this->postJson('/assistente/perguntas', ['pergunta' => 'De novo'])->assertStatus(503);
        $this->getJson('/assistente/conversa')->assertJson(['configurado' => false, 'restantes' => null]);
    }

    public function test_consultas_de_oee_apontamentos_e_ops(): void
    {
        $adm = $this->usuario(['admin_producao'], 'adm@empresa.com');
        $this->actingAs($adm);
        $ferramentas = app(FerramentasAssistente::class);
        $inj = Maquina::where('codigo', 'INJ-01')->sole();
        $srv = Maquina::withoutGlobalScopes()->where('codigo', 'SRV-01')->sole();
        // Relógio fixo no fim da tarde: o resultado não depende da hora em que o teste roda.
        $this->travelTo(now(config('oee.fuso_exibicao'))->setTime(18, 0));
        $hoje = now(config('oee.fuso_exibicao'))->startOfDay();

        // OEE: só as máquinas que ele vê.
        foreach ([$inj, $srv] as $m) {
            Apontamento::create(['maquina_id' => $m->id, 'inicio' => $hoje->copy()->addHours(8)->utc(), 'fim' => $hoje->copy()->addHours(9)->utc(),
                'ciclo_ideal_s' => 30, 'total_produzido' => 100, 'pecas_boas' => 90]);
        }
        $oee = $ferramentas->executar($adm, 'indicadores_oee', ['de' => $hoje->format('Y-m-d')]);
        $this->assertCount(1, $oee['por_maquina']);
        $this->assertSame('INJ-01 - Injetora 01', $oee['por_maquina'][0]['maquina']);
        $this->assertSame(90.0, $oee['total']['qualidade_pct']);

        // Apontamentos reais agrupados por legenda (cortados no período).
        $legenda = Legenda::create(['codigo' => '3', 'descricao' => 'Manutenção', 'tipo' => 'parada', 'cor' => '#e53935']);
        ApontamentoEstado::create(['maquina_id' => $inj->id, 'legenda_id' => $legenda->id,
            'inicio' => $hoje->copy()->addHours(10)->utc(), 'fim' => $hoje->copy()->addHours(11)->addMinutes(30)->utc()]);
        ApontamentoEstado::create(['maquina_id' => $srv->id, 'legenda_id' => $legenda->id,
            'inicio' => $hoje->copy()->addHours(10)->utc(), 'fim' => $hoje->copy()->addHours(12)->utc()]);
        $ap = $ferramentas->executar($adm, 'apontamentos_reais', ['de' => $hoje->format('Y-m-d'), 'agrupar' => 'legenda']);
        $this->assertSame([['legenda' => 'Manutenção', 'tipo' => 'Parada não planejada', 'minutos' => 90.0, 'apontamentos' => 1, 'horas' => 1.5]], $ap['grupos']);

        // Encerrado conta inteiro, mesmo que termine depois de agora; em aberto conta só até agora.
        $this->travelTo(now(config('oee.fuso_exibicao'))->setTime(11, 0));
        $ap = $ferramentas->executar($adm, 'apontamentos_reais', ['de' => $hoje->format('Y-m-d'), 'agrupar' => 'legenda']);
        $this->assertSame(90.0, $ap['grupos'][0]['minutos']);
        ApontamentoEstado::where('maquina_id', $inj->id)->update(['fim' => null]);
        $ap = $ferramentas->executar($adm, 'apontamentos_reais', ['de' => $hoje->format('Y-m-d'), 'agrupar' => 'legenda']);
        $this->assertSame(60.0, $ap['grupos'][0]['minutos']);
        $this->travelBack();

        // OPs: filtro por descrição do produto e total por status.
        ProdutoProtheus::create(['b1_cod' => 'PA001', 'b1_desc' => 'INTERRUPTOR PARALELO', 'b1_um' => 'PC']);
        OrdemProducao::create(['c2_num' => '062678', 'c2_item' => '01', 'c2_sequen' => '001', 'numero' => '06267801001',
            'c2_produto' => 'PA001', 'status' => 'andamento', 'c2_quant' => 1000, 'c2_quje' => 250, 'c2_datprf' => '20261130']);
        OrdemProducao::create(['c2_num' => '062679', 'c2_item' => '01', 'c2_sequen' => '001', 'numero' => '06267901001',
            'c2_produto' => 'PA002', 'status' => 'aberta']);
        $ops = $ferramentas->executar($adm, 'ordens_producao', ['produto' => 'interruptor', 'incluir_custo' => true]);
        $this->assertSame(['Andamento' => 1], $ops['total_por_status']);
        $this->assertSame('06267801001', $ops['ops'][0]['numero']);
        $this->assertSame('INTERRUPTOR PARALELO', $ops['ops'][0]['descricao']);
        $this->assertSame(25.0, $ops['ops'][0]['produzido_pct']);
        $this->assertSame('30/11/2026', $ops['ops'][0]['previsao_fim']);
        $this->assertArrayHasKey('custo_unitario_simulado_oee', $ops['ops'][0]);

        $detalhe = $ferramentas->executar($adm, 'detalhe_op', ['numero' => '06267801001']);
        $this->assertSame('Andamento', $detalhe['op']['status']);
    }
}

/** Cliente falso da Claude: devolve respostas prontas e guarda as requisições. */
class ClienteFalso implements ClienteClaude
{
    /** @var list<array|\Throwable> */
    public array $respostas = [];

    /** @var list<array> */
    public array $requisicoes = [];

    public function enviar(array $requisicao): array
    {
        $this->requisicoes[] = json_decode(json_encode($requisicao), true);
        $r = array_shift($this->respostas) ?? self::texto('(sem resposta)');
        if ($r instanceof \Throwable) {
            throw $r;
        }

        return $r;
    }

    public static function texto(string $texto): array
    {
        return ['content' => [['type' => 'text', 'text' => $texto]], 'stop_reason' => 'end_turn', 'model' => 'claude-opus-5-5',
            'usage' => ['input_tokens' => 100, 'output_tokens' => 20]];
    }

    public static function consulta(string $nome, array $entrada): array
    {
        return ['content' => [['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'], ['type' => 'tool_use', 'id' => 'toolu_'.$nome, 'name' => $nome, 'input' => $entrada]],
            'stop_reason' => 'tool_use', 'model' => 'claude-opus-5-5', 'usage' => ['input_tokens' => 100, 'output_tokens' => 30]];
    }
}
