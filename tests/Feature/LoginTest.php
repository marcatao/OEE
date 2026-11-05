<?php

namespace Tests\Feature;

use App\Models\RegistroLogin;
use App\Models\Usuario;
use App\Services\EntraId;
use App\Services\IdentidadeEntra;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
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
        config(['services.azure.tenant' => self::TENANT, 'oee.admins' => ['admin@empresa.com']]);
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

    public function test_login_salva_usuario_e_registro_no_banco_e_volta_ao_destino(): void
    {
        $this->entrar(destino: '/calculadora')->assertRedirect('/calculadora');

        $this->assertAuthenticated();
        $u = Usuario::sole();
        $this->assertSame(['Fulano', 'fulano@empresa.com', 'operador', 'oid-fulano'], [$u->nome, $u->email, $u->perfil, $u->entra_oid]);
        $this->assertNotNull($u->ultimo_login_em);
        $this->assertTrue(RegistroLogin::sole()->sucesso);

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

    public function test_admin_do_env_entra_como_admin(): void
    {
        $this->entrar(['email' => 'Admin@Empresa.com', 'oid' => 'oid-admin']);
        $this->assertSame('admin', Usuario::sole()->perfil);
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
}
