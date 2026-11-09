<?php

namespace Tests\Feature;

use App\Models\Usuario;
use App\Providers\AppServiceProvider;
use App\Services\EntraId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Portal publicado numa subpasta: https://www.alumbra.com.br/oee/
 */
class EnderecoPublicoTest extends TestCase
{
    use RefreshDatabase;

    private const PORTAL = 'https://www.alumbra.com.br/oee';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.url' => self::PORTAL,
            'services.azure.redirect' => self::PORTAL.'/auth/callback',
            'services.azure.tenant' => '11111111-1111-1111-1111-111111111111',
        ]);
        AppServiceProvider::aplicarEnderecoPublico();
    }

    protected function tearDown(): void
    {
        URL::forceRootUrl(null);
        URL::forceScheme(null);
        parent::tearDown();
    }

    /** Requisição como o IIS entrega numa aplicação em /oee (o Laravel descobre a subpasta pelo SCRIPT_NAME). */
    private function naSubpasta(string $metodo, string $url): TestResponse
    {
        return $this->call($metodo, $url, [], [], [], [
            'SCRIPT_NAME' => '/oee/index.php',
            'SCRIPT_FILENAME' => public_path('index.php'),
            'PHP_SELF' => '/oee/index.php',
        ]);
    }

    public function test_links_do_portal_usam_a_subpasta_e_https(): void
    {
        $this->assertSame(self::PORTAL, url('/'));
        $this->assertSame(self::PORTAL.'/auth/login', route('login'));
        $this->assertSame(self::PORTAL.'/auth/callback', route('auth.callback'));
        $this->assertSame(self::PORTAL.'/css/portal.css', asset('css/portal.css'));
    }

    public function test_sem_subpasta_no_app_url_nada_e_forcado(): void
    {
        URL::forceRootUrl(null);
        URL::forceScheme(null);
        config(['app.url' => 'https://oee.alumbra.local', 'services.azure.redirect' => 'https://oee.alumbra.local/auth/callback']);
        AppServiceProvider::aplicarEnderecoPublico();
        $this->mock(EntraId::class, fn (MockInterface $m) => $m->shouldReceive('redirecionarParaLogin')
            ->andReturnUsing(fn () => redirect()->to(route('inicio'))));

        // Os links continuam seguindo o endereço da própria requisição.
        $this->get('http://servidor-interno/auth/login')->assertRedirect('http://servidor-interno');
    }

    public function test_iis_na_subpasta_encontra_as_rotas(): void
    {
        // Página interna sem login: a rota é achada dentro de /oee e o redirecionamento mantém a subpasta.
        $this->naSubpasta('GET', self::PORTAL.'/perfil')->assertRedirect(self::PORTAL.'/auth/login');
    }

    public function test_login_por_outro_nome_segue_para_a_microsoft_com_retorno_no_endereco_publico(): void
    {
        // Ex.: proxy na frente do IIS entrega o nome interno do servidor. Não redireciona (antes ficava preso).
        $destino = $this->naSubpasta('GET', 'https://servidor-interno/oee/auth/login')->assertRedirect()->headers->get('Location');

        $this->assertStringStartsWith('https://login.microsoftonline.com/', $destino);
        $this->assertStringContainsString('redirect_uri='.urlencode(self::PORTAL.'/auth/callback'), $destino);
    }

    public function test_depois_de_redirecionado_nao_redireciona_de_novo(): void
    {
        $this->mock(EntraId::class, fn (MockInterface $m) => $m->shouldReceive('redirecionarParaLogin')
            ->andReturn(redirect()->away('https://login.microsoftonline.com/x/oauth2/v2.0/authorize')));

        $this->get('http://servidor-interno/auth/login?redirecionado=1')
            ->assertRedirect('https://login.microsoftonline.com/x/oauth2/v2.0/authorize');
    }

    public function test_mesmo_nome_vai_direto_para_a_microsoft_com_retorno_na_subpasta(): void
    {
        $resposta = $this->naSubpasta('GET', self::PORTAL.'/auth/login');
        $destino = $resposta->headers->get('Location');

        $this->assertStringStartsWith('https://login.microsoftonline.com/', $destino);
        $this->assertStringContainsString('redirect_uri='.urlencode(self::PORTAL.'/auth/callback'), $destino);
    }

    public function test_logout_volta_para_a_pagina_inicial_com_barra_no_final(): void
    {
        $usuario = Usuario::create(['entra_oid' => 'o', 'nome' => 'U', 'email' => 'u@alumbra.com.br', 'perfil' => 'operador']);

        $this->actingAs($usuario);
        $this->naSubpasta('POST', self::PORTAL.'/auth/logout')
            ->assertRedirectContains('post_logout_redirect_uri='.urlencode(self::PORTAL.'/'));
    }
}
