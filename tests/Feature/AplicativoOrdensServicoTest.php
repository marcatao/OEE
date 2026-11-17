<?php

namespace Tests\Feature;

use App\Models\CentroCusto;
use App\Models\Linha;
use App\Models\Manutencao\ApontamentoHoras;
use App\Models\Manutencao\AreaExecutante;
use App\Models\Manutencao\AssinaturaOs;
use App\Models\Manutencao\Mantenedor;
use App\Models\Manutencao\OrdemServico;
use App\Models\Manutencao\SolicitacaoServico;
use App\Models\Maquina;
use App\Models\Planta;
use App\Models\Usuario;
use App\Services\Aplicativo\LoginAplicativo;
use App\Support\AcessoOs;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    private function abrirSs(): SolicitacaoServico
    {
        $this->api($this->solicitante, 'POST', 'solicitacoes', [
            'titulo' => 'Vazamento de óleo', 'tipo' => 'corretiva', 'area_executante_id' => $this->mecanica->id,
            'maquina_id' => $this->injetora->id, 'prioridade' => 'alta', 'maquina_parada' => true, 'descricao' => 'Vazamento no cilindro',
        ])->assertOk()->assertJsonPath('ok', true);

        return SolicitacaoServico::latest('id')->firstOrFail();
    }

    public function test_solicitante_abre_ss_pelo_app_com_as_regras_do_portal(): void
    {
        $this->api($this->solicitante, 'GET', 'formulario')->assertOk()->assertJsonPath('areas.0.nome', 'Mecânica');
        $ss = $this->abrirSs();
        $this->assertSame('pendente', $ss->status);
        $this->assertSame('1001', $ss->centro_custo); // o CC da máquina
        $this->api($this->solicitante, 'POST', 'solicitacoes', ['titulo' => 'x'])->assertUnprocessable();
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

    public function test_quem_nao_e_mantenedor_nao_inicia(): void
    {
        $ss = $this->abrirSs();
        $osId = $this->api($this->joao, 'POST', "solicitacoes/{$ss->id}/assumir")->json('ordem.id');
        $this->api($this->solicitante, 'POST', "ordens/{$osId}/iniciar")->assertForbidden();
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
