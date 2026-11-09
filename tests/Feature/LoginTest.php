<?php

namespace Tests\Feature;

use App\Models\RegistroLogin;
use App\Models\Usuario;
use App\Services\EntraId;
use App\Services\IdentidadeEntra;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Two\InvalidStateException;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = '11111111-1111-1111-1111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.azure.tenant' => self::TENANT,
            'services.azure.redirect' => url('/auth/callback'), // mesmo endereço das requisições de teste
            'oee.admins' => ['marcato@alumbra.com.br'],
        ]);
    }

    /** Simula o retorno do Entra ID com a identidade informada. */
    private function entrar(array $dados = [], string $destino = ''): TestResponse
    {
        $identidade = new IdentidadeEntra(
            oid: $dados['oid'] ?? 'oid-fulano',
            tenantId: $dados['tid'] ?? self::TENANT,
            nome: $dados['nome'] ?? 'Fulano',
            email: $dados['email'] ?? 'fulano@empresa.com',
        );
        $this->partialMock(EntraId::class, fn (MockInterface $m) => $m->shouldReceive('usuarioAutenticado')->andReturn($identidade));

        if ($destino) {
            $this->get($destino); // guarda a página pedida ("intended")
        }

        return $this->get('/auth/callback?code=c&state=s');
    }

    public function test_inicio_publico_com_o_nome_do_portal(): void
    {
        $this->get('/')->assertOk()->assertSee('Entrar com Microsoft')->assertSee('OEE Alumbra');
    }

    public function test_calculadora_exige_login(): void
    {
        $this->get('/calculadora')->assertRedirect('/auth/login');
    }

    public function test_login_redireciona_para_o_entra_id(): void
    {
        $this->mock(EntraId::class, fn (MockInterface $m) => $m->shouldReceive('redirecionarParaLogin')
            ->andReturn(redirect()->away('https://login.microsoftonline.com/x/oauth2/v2.0/authorize')));

        $this->get('/auth/login')->assertRedirect('https://login.microsoftonline.com/x/oauth2/v2.0/authorize');
    }

    public function test_primeiro_login_cadastra_usuario_sem_acesso(): void
    {
        $this->entrar(destino: '/calculadora')->assertRedirect('/calculadora');
        $this->get('/calculadora')->assertRedirect('/aguardando-liberacao');

        $this->assertAuthenticated();
        $u = Usuario::sole();
        $this->assertSame(['Fulano', 'fulano@empresa.com', 'sem_acesso', 'oid-fulano'], [$u->nome, $u->email, $u->perfil, $u->entra_oid]);
        $this->assertNotNull($u->ultimo_login_em);
        $log = RegistroLogin::sole();
        $this->assertTrue($log->sucesso);
        $this->assertSame('aguardando liberação', $log->motivo);
    }

    public function test_admin_nasce_admin_e_usa_a_calculadora(): void
    {
        $this->entrar(['email' => 'marcato@alumbra.com.br', 'oid' => 'oid-marcato'], '/calculadora')->assertRedirect('/calculadora');
        $this->assertSame('admin', Usuario::sole()->perfil);

        $this->post('/calculadora', [
            'tempo_planejado' => 480, 'paradas' => 47, 'ciclo_ideal' => 1, 'total_produzido' => 19271, 'pecas_boas' => 18848,
        ])->assertSee('65,4%');
    }

    public function test_segundo_login_atualiza_nome_sem_duplicar(): void
    {
        $this->entrar();
        $this->post('/auth/logout');
        $this->entrar(['nome' => 'Fulano de Tal']);

        $this->assertSame('Fulano de Tal', Usuario::sole()->nome);
        $this->assertSame(2, RegistroLogin::count());
    }

    public function test_so_o_email_configurado_nasce_admin_e_so_no_primeiro_login(): void
    {
        $this->entrar(['email' => 'Marcato@Alumbra.com.br', 'oid' => 'oid-marcato']);
        $this->assertSame('admin', Usuario::sole()->perfil);

        // se outro admin mudar o perfil, o próximo login não "promove" de novo
        Usuario::sole()->update(['perfil' => 'operador']);
        $this->post('/auth/logout');
        $this->entrar(['email' => 'marcato@alumbra.com.br', 'oid' => 'oid-marcato']);
        $this->assertSame('operador', Usuario::sole()->perfil);
    }

    public function test_outros_emails_nascem_sem_acesso(): void
    {
        $this->entrar(['email' => 'chefe@alumbra.com.br', 'oid' => 'oid-chefe']);
        $this->assertSame('sem_acesso', Usuario::sole()->perfil);
    }

    public function test_usuario_desativado_perde_a_sessao_e_nao_entra(): void
    {
        $this->entrar();
        $this->get('/perfil')->assertOk();

        Usuario::sole()->update(['ativo' => false]);
        Auth::forgetGuards(); // como numa requisição nova: o usuário é relido do banco
        $this->get('/perfil')->assertRedirect('/');
        $this->assertGuest();

        $this->entrar()->assertForbidden();
        $this->assertSame('usuário desativado no portal', RegistroLogin::where('sucesso', false)->sole()->motivo);
    }

    public function test_token_de_outro_locatario_e_recusado(): void
    {
        $this->entrar(['tid' => '22222222-2222-2222-2222-222222222222'])->assertForbidden();
        $this->assertGuest();
        $this->assertSame(0, Usuario::count());
        $this->assertFalse(RegistroLogin::sole()->sucesso);
    }

    public function test_falha_ao_validar_retorno_e_registrada(): void
    {
        $this->mock(EntraId::class, fn (MockInterface $m) => $m->shouldReceive('usuarioAutenticado')->andThrow(new RuntimeException('state inválido')));

        $this->get('/auth/callback?code=c&state=x')->assertStatus(401);
        $this->assertGuest();
        $this->assertSame('falha ao validar o retorno do Entra ID', RegistroLogin::sole()->motivo);
    }

    public function test_erro_devolvido_pelo_entra_id_e_registrado(): void
    {
        $this->get('/auth/callback?error=access_denied&error_description=Usuario+cancelou')->assertStatus(401)->assertSee('Usuario cancelou');
        $this->assertSame('Entra ID: access_denied', RegistroLogin::sole()->motivo);
    }

    public function test_logout_vai_para_o_logout_do_entra_id(): void
    {
        $this->entrar();
        $this->post('/auth/logout')->assertRedirectContains('login.microsoftonline.com/'.self::TENANT.'/oauth2/v2.0/logout');
        $this->assertGuest();
    }

    public function test_login_aberto_por_outro_endereco_vai_primeiro_para_o_endereco_do_retorno(): void
    {
        config(['services.azure.redirect' => 'http://localhost:8000/auth/callback']);

        $this->get('http://127.0.0.1:8000/auth/login')->assertRedirect('http://localhost:8000/auth/login?redirecionado=1');
    }

    public function test_state_invalido_explica_o_que_fazer(): void
    {
        $this->mock(EntraId::class, fn (MockInterface $m) => $m->shouldReceive('usuarioAutenticado')->andThrow(new InvalidStateException));

        $this->get('/auth/callback?code=c&state=x')->assertStatus(401)->assertSee('A sessão do login se perdeu');
        $this->assertSame('sessão do login perdida (state inválido)', RegistroLogin::sole()->motivo);
    }

    public function test_segredo_errado_mostra_o_codigo_aadsts_e_a_dica(): void
    {
        $corpo = json_encode(['error' => 'invalid_client', 'error_description' => "AADSTS7000215: Invalid client secret provided.\r\nTrace ID: x"]);
        $erro = new ClientException('401', new GuzzleRequest('POST', 'https://login.microsoftonline.com/x/oauth2/v2.0/token'), new GuzzleResponse(401, [], $corpo));
        $this->mock(EntraId::class, fn (MockInterface $m) => $m->shouldReceive('usuarioAutenticado')->andThrow($erro));

        $this->get('/auth/callback?code=c&state=s')->assertStatus(401)
            ->assertSee('AADSTS7000215')->assertSee('use o &quot;Valor&quot; do segredo', false);
        $this->assertSame('Entra ID: AADSTS7000215', RegistroLogin::sole()->motivo);
    }

    public function test_erro_de_certificado_ssl_explica_o_que_fazer(): void
    {
        $erro = new ConnectException('cURL error 60: SSL certificate problem: unable to get local issuer certificate', new GuzzleRequest('POST', 'https://login.microsoftonline.com'));
        $this->mock(EntraId::class, fn (MockInterface $m) => $m->shouldReceive('usuarioAutenticado')->andThrow($erro));

        $this->get('/auth/callback?code=c&state=s')->assertStatus(401)->assertSee('cURL error 60');
        $this->assertSame('falha de certificado SSL ao falar com a Microsoft', RegistroLogin::sole()->motivo);
    }

    public function test_acesso_de_outra_maquina_com_portal_configurado_em_localhost_explica_o_que_fazer(): void
    {
        config(['services.azure.redirect' => 'http://localhost:8000/auth/callback']);

        $this->get('http://192.168.0.50:8000/auth/login')->assertStatus(500)
            ->assertSee('configurado para funcionar só no próprio servidor')->assertSee('Acessar de outras máquinas');
    }

    public function test_acesso_pelo_nome_de_rede_vai_para_o_endereco_https_configurado(): void
    {
        config(['services.azure.redirect' => 'https://oee.alumbra.local/auth/callback']);

        $this->get('http://192.168.0.50:8000/auth/login')->assertRedirect('https://oee.alumbra.local/auth/login?redirecionado=1');
    }
}
