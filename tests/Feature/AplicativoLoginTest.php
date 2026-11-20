<?php

namespace Tests\Feature;

use App\Models\Aplicativo\TokenAplicativo;
use App\Models\Usuario;
use App\Services\Aplicativo\LoginAplicativo;
use App\Services\EntraId;
use App\Services\IdentidadeEntra;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Tests\TestCase;

class AplicativoLoginTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = '11111111-1111-1111-1111-111111111111';

    private const VERIFICADOR = 'segredo-do-app-com-pelo-menos-quarenta-e-tres-caracteres-123';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.azure.tenant' => self::TENANT, 'services.azure.redirect' => url('/auth/callback')]);
    }

    /** Faz o caminho do celular: /app/entrar → Entra ID (simulado) → /auth/callback → /app/entrar/concluir. */
    private function loginPeloApp(string $oid, string $email = 'op@alumbra.com.br')
    {
        $this->partialMock(EntraId::class, function (MockInterface $m) use ($oid, $email) {
            $m->shouldReceive('redirecionarParaLogin')->with(true)->andReturn(new RedirectResponse('https://login.microsoftonline.com/x'));
            $m->shouldReceive('usuarioAutenticado')->andReturn(new IdentidadeEntra(oid: $oid, tenantId: self::TENANT, nome: 'Operador Teste', email: $email));
        });

        $this->get('/app/entrar?desafio='.LoginAplicativo::desafioDe(self::VERIFICADOR))
            ->assertRedirect('https://login.microsoftonline.com/x');
        $this->get('/auth/callback?code=c&state=s')->assertRedirect(route('app.entrar.concluir'));

        return $this->get(route('app.entrar.concluir'));
    }

    private function operador(): Usuario
    {
        $usuario = Usuario::create(['entra_oid' => 'oid-op', 'nome' => 'Operador Teste', 'email' => 'op@alumbra.com.br', 'perfil' => 'operador']);
        $usuario->forceFill(['criado_em' => now()->subDays(3)])->save();

        return $usuario;
    }

    private function codigoDaResposta($resposta): string
    {
        preg_match('/br\.com\.alumbra\.oee:\/\/login\?codigo=([A-Za-z0-9]+)/', $resposta->getContent(), $m);
        $this->assertNotEmpty($m[1] ?? null, 'a página deveria levar o código ao app');

        return $m[1];
    }

    public function test_login_completo_gera_token_e_dados_do_usuario(): void
    {
        $this->operador();
        $resposta = $this->loginPeloApp('oid-op')->assertOk()->assertSee('Voltar ao aplicativo');
        $this->assertGuest(); // a sessão do navegador do celular não fica aberta
        $codigo = $this->codigoDaResposta($resposta);

        $json = $this->postJson('/api/app/token', ['codigo' => $codigo, 'verificador' => self::VERIFICADOR, 'dispositivo' => 'Samsung A15', 'versao' => '0.2.0'])
            ->assertOk()
            ->assertJsonPath('usuario.nome', 'Operador Teste')
            ->assertJsonPath('usuario.funcoes.operador', true)
            ->assertJsonPath('usuario.funcoes.mantenedor', false)
            ->json();

        $this->assertSame(1, TokenAplicativo::count());
        $this->assertNotSame($json['token'], TokenAplicativo::first()->token_hash); // só o hash fica no banco

        $this->getJson('/api/app/eu', ['Authorization' => 'Bearer '.$json['token']])->assertOk()->assertJsonPath('usuario.email', 'op@alumbra.com.br');
        $this->getJson('/api/app/eu', ['X-Token-App' => $json['token']])->assertOk();

        // O código é de uso único.
        $this->postJson('/api/app/token', ['codigo' => $codigo, 'verificador' => self::VERIFICADOR])->assertStatus(422);
    }

    public function test_codigo_com_segredo_errado_e_recusado(): void
    {
        $this->operador();
        $codigo = $this->codigoDaResposta($this->loginPeloApp('oid-op'));

        $this->postJson('/api/app/token', ['codigo' => $codigo, 'verificador' => str_repeat('x', 50)])->assertStatus(422);
        $this->assertSame(0, TokenAplicativo::count());
    }

    public function test_primeiro_acesso_pelo_app_e_recusado(): void
    {
        $this->loginPeloApp('oid-novo', 'novo@alumbra.com.br')
            ->assertStatus(403)
            ->assertSee('primeiro acesso deve ser pelo portal');
        $this->assertSame(0, TokenAplicativo::count());
    }

    public function test_usuario_sem_acesso_e_recusado(): void
    {
        $usuario = Usuario::create(['entra_oid' => 'oid-sem', 'nome' => 'Sem Acesso', 'email' => 's@alumbra.com.br']);
        $usuario->forceFill(['criado_em' => now()->subDays(3)])->save();

        $this->loginPeloApp('oid-sem', 's@alumbra.com.br')->assertStatus(403)->assertSee('ainda não tem acesso');
    }

    public function test_sair_revoga_o_token(): void
    {
        $usuario = $this->operador();
        $token = $this->tokenDireto($usuario);

        $this->postJson('/api/app/sair', [], ['Authorization' => "Bearer {$token}"])->assertOk();
        $this->getJson('/api/app/eu', ['Authorization' => "Bearer {$token}"])->assertStatus(401);
    }

    public function test_token_cai_sem_uso_ou_sem_acesso(): void
    {
        $usuario = $this->operador();
        $token = $this->tokenDireto($usuario);

        TokenAplicativo::query()->update(['ultimo_uso_em' => now()->subDays(8)]);
        $this->getJson('/api/app/eu', ['Authorization' => "Bearer {$token}"])->assertStatus(401);

        TokenAplicativo::query()->update(['ultimo_uso_em' => now()]);
        $usuario->forceFill(['ativo' => false])->save();
        $this->getJson('/api/app/eu', ['Authorization' => "Bearer {$token}"])->assertStatus(401);
    }

    public function test_api_sem_token_responde_401_em_json(): void
    {
        $this->getJson('/api/app/eu')->assertStatus(401)->assertJsonPath('erro', 'sessao_expirada');
        $this->get('/api/app/eu')->assertStatus(401);
    }

    public function test_entrar_exige_desafio_valido(): void
    {
        $this->get('/app/entrar?desafio=curto')->assertSessionHasErrors('desafio');
    }

    private function tokenDireto(Usuario $usuario): string
    {
        $login = app(LoginAplicativo::class);
        $codigo = $login->criarCodigo($usuario, LoginAplicativo::desafioDe(self::VERIFICADOR));

        return $login->trocarCodigo($codigo, self::VERIFICADOR, null, null)['token'];
    }

    public function test_token_vence_em_30_dias_e_o_administrador_desconecta_o_celular(): void
    {
        $op = $this->operador();
        $token = Str::random(64);
        $registro = TokenAplicativo::create(['usuario_id' => $op->id, 'token_hash' => hash('sha256', $token), 'dispositivo' => 'Moto G', 'ultimo_uso_em' => now()]);
        $this->getJson('/api/app/eu', ['Authorization' => "Bearer {$token}"])->assertOk();

        $adm = Usuario::create(['entra_oid' => 'adm-ti', 'nome' => 'TI', 'email' => 'ti@alumbra.com.br', 'perfil' => 'admin']);
        $this->actingAs($adm)->get("/cadastros/logins/usuarios/{$op->id}/editar")->assertOk()->assertSee('Moto G');
        $this->post("/cadastros/logins/usuarios/{$op->id}/celulares/{$registro->id}/revogar")->assertSessionHas('ok');
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/app/eu', ['Authorization' => "Bearer {$token}"])->assertStatus(401);

        // Usado todo dia, mas conectado há mais de 30 dias: vence.
        $token2 = Str::random(64);
        $velho = TokenAplicativo::create(['usuario_id' => $op->id, 'token_hash' => hash('sha256', $token2), 'ultimo_uso_em' => now()]);
        $velho->forceFill(['criado_em' => now()->subDays(31)])->save();
        $this->getJson('/api/app/eu', ['Authorization' => "Bearer {$token2}"])->assertStatus(401);
    }
}
