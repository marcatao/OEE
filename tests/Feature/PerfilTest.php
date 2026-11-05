<?php

namespace Tests\Feature;

use App\Models\Usuario;
use App\Services\EntraId;
use App\Services\IdentidadeEntra;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\TestCase;

class PerfilTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = '11111111-1111-1111-1111-111111111111';

    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config([
            'services.azure.tenant' => self::TENANT,
            'services.azure.redirect' => url('/auth/callback'),
            'oee.admins' => ['marcato@alumbra.com.br'],
        ]);
    }

    private function usuario(string $perfil = 'operador'): Usuario
    {
        return Usuario::create(['entra_oid' => 'oid-maria', 'nome' => 'Maria Souza', 'email' => 'maria@empresa.com', 'perfil' => $perfil]);
    }

    private function fingirGraph(): void
    {
        Http::fake([
            'https://graph.microsoft.com/v1.0/me?*' => Http::response([
                'department' => 'Produção', 'jobTitle' => 'Supervisora', 'mail' => 'maria@empresa.com',
                'mobilePhone' => '+55 11 99999-0000', 'employeeId' => '12345',
            ]),
            'https://graph.microsoft.com/v1.0/me/photos/*' => Http::response(base64_decode(self::PNG_1X1), 200, ['Content-Type' => 'image/jpeg']),
        ]);
    }

    private function entrarComToken(): void
    {
        $identidade = new IdentidadeEntra('oid-maria', self::TENANT, 'Maria Souza', 'maria@empresa.com', 'token-graph', time() + 3600);
        $this->partialMock(EntraId::class, fn (MockInterface $m) => $m->shouldReceive('usuarioAutenticado')->andReturn($identidade));
        $this->get('/auth/callback?code=c&state=s')->assertRedirect();
    }

    public function test_funcionario_edita_o_perfil_e_envia_foto(): void
    {
        $u = $this->usuario();
        $foto = UploadedFile::fake()->createWithContent('eu.png', base64_decode(self::PNG_1X1));

        $this->actingAs($u)->get('/perfil')->assertOk()->assertSee('Meu perfil')->assertSee('oid-maria')->assertSee('somente leitura');
        $this->put('/perfil', ['setor' => 'Qualidade', 'cargo' => 'Analista', 'email_contato' => 'maria.souza@empresa.com',
            'celular' => '(11) 98888-7777', 'foto' => $foto])->assertRedirect('/perfil')->assertSessionHas('ok');

        $u->refresh();
        $this->assertSame(['Qualidade', 'Analista', 'maria.souza@empresa.com', '(11) 98888-7777'], [$u->setor, $u->cargo, $u->email_contato, $u->celular]);
        Storage::disk('local')->assertExists($u->foto);
        $this->get("/usuarios/{$u->id}/foto")->assertOk();

        $this->put('/perfil', ['setor' => 'Qualidade', 'remover_foto' => '1'])->assertRedirect('/perfil');
        $this->assertNull($u->refresh()->foto);
        $this->get("/usuarios/{$u->id}/foto")->assertNotFound();
    }

    public function test_ids_nao_mudam_pelo_formulario_e_celular_invalido_e_recusado(): void
    {
        $u = $this->usuario();
        $this->actingAs($u)->put('/perfil', ['celular' => 'abc', 'entra_oid' => 'outro', 'perfil' => 'admin', 'matricula' => '999'])
            ->assertSessionHasErrors('celular');
        $this->put('/perfil', ['cargo' => 'X', 'entra_oid' => 'outro', 'perfil' => 'admin', 'matricula' => '999'])->assertRedirect();

        $u->refresh();
        $this->assertSame(['oid-maria', 'operador', null], [$u->entra_oid, $u->perfil, $u->matricula]);
    }

    public function test_quem_aguarda_liberacao_tambem_edita_o_perfil(): void
    {
        $this->actingAs($this->usuario('sem_acesso'))->get('/perfil')->assertOk();
        $this->put('/perfil', ['cargo' => 'Operador de máquina'])->assertRedirect('/perfil');
    }

    public function test_login_preenche_o_perfil_com_os_dados_do_entra_id(): void
    {
        $this->fingirGraph();
        $this->entrarComToken();

        $u = Usuario::sole();
        $this->assertSame(['Produção', 'Supervisora', 'maria@empresa.com', '+55 11 99999-0000', '12345'],
            [$u->setor, $u->cargo, $u->email_contato, $u->celular, $u->matricula]);
        Storage::disk('local')->assertExists($u->foto);
        $this->assertNotNull($u->perfil_entra_em);
    }

    public function test_login_nao_apaga_o_que_o_funcionario_digitou_mas_o_botao_atualiza(): void
    {
        $u = $this->usuario();
        $u->forceFill(['cargo' => 'Cargo que eu digitei'])->save();
        $this->fingirGraph();
        $this->entrarComToken();
        $this->assertSame('Cargo que eu digitei', $u->refresh()->cargo);

        $this->post('/perfil/entra')->assertRedirect('/perfil')->assertSessionHas('ok');
        $this->assertSame('Supervisora', $u->refresh()->cargo);
    }

    public function test_falha_no_graph_nao_impede_o_login(): void
    {
        Http::fake(['https://graph.microsoft.com/*' => Http::response(['error' => ['code' => 'Forbidden']], 403)]);
        $this->entrarComToken();

        $this->assertAuthenticated();
        $this->assertNull(Usuario::sole()->setor);
    }

    public function test_botao_do_entra_sem_token_pede_novo_login(): void
    {
        $this->actingAs($this->usuario())->post('/perfil/entra')->assertRedirect('/perfil')->assertSessionHas('erro');
    }
}
