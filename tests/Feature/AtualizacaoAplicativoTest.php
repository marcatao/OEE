<?php

namespace Tests\Feature;

use App\Models\Usuario;
use App\Models\VersaoAplicativo;
use App\Services\Aplicativo\LoginAplicativo;
use App\Support\LeitorApk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Segurança › Aplicativo: publicar o APK no portal; o app pergunta a versão e baixa pelo portal. */
class AtualizacaoAplicativoTest extends TestCase
{
    use RefreshDatabase;

    private const VERIFICADOR = 'segredo-do-app-com-pelo-menos-quarenta-e-tres-caracteres-123';

    private const APK = __DIR__.'/../fixtures/aplicativo/oee-alumbra-teste.apk'; // manifesto do build 22 (0.12.5)

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function adminTecnologia(): Usuario
    {
        $u = Usuario::create(['entra_oid' => 'ti', 'nome' => 'TI', 'email' => 'ti@alumbra.com.br', 'perfil' => 'operador']);
        $u->definirPerfis(['admin_tecnologia']);

        return $u;
    }

    private function upload(string $caminho = self::APK, string $nome = 'oee.apk'): UploadedFile
    {
        return new UploadedFile($caminho, $nome, 'application/vnd.android.package-archive', null, true);
    }

    private function tokenApp(): string
    {
        $u = Usuario::create(['entra_oid' => 'op', 'nome' => 'Operador', 'email' => 'op@alumbra.com.br', 'perfil' => 'operador']);
        $login = app(LoginAplicativo::class);

        return $login->trocarCodigo($login->criarCodigo($u, LoginAplicativo::desafioDe(self::VERIFICADOR)), self::VERIFICADOR, null, null)['token'];
    }

    public function test_le_a_versao_do_manifesto_do_apk(): void
    {
        $this->assertSame(['pacote' => 'br.com.alumbra.oee', 'codigo' => 22, 'nome' => '0.12.5'], LeitorApk::ler(self::APK));
    }

    public function test_administrador_publica_o_apk_com_a_versao_lida_do_arquivo(): void
    {
        $admin = $this->adminTecnologia();
        $this->actingAs($admin)->get(route('admin.aplicativo'))->assertOk()->assertSee('Nenhuma versão publicada');

        $this->post(route('admin.aplicativo.store'), ['apk' => $this->upload(), 'observacao' => 'Metas', 'obrigatoria' => 1])
            ->assertRedirect(route('admin.aplicativo'))->assertSessionHas('ok');
        $v = VersaoAplicativo::withoutGlobalScopes()->sole();
        $this->assertSame([22, '0.12.5', true, 'ambos', hash_file('sha256', self::APK)], [$v->codigo, $v->nome, $v->obrigatoria, $v->unidade, $v->sha256]);
        Storage::disk('local')->assertExists('aplicativo/oee-alumbra-0.12.5-22.apk');
        $this->get(route('admin.aplicativo'))->assertSee('0.12.5 (build 22)');

        // O mesmo build de novo: recusado.
        $this->post(route('admin.aplicativo.store'), ['apk' => $this->upload()])->assertSessionHasErrors('apk');
        $this->assertSame(1, VersaoAplicativo::withoutGlobalScopes()->count());
    }

    public function test_recusa_arquivo_que_nao_e_apk_do_aplicativo(): void
    {
        $zip = tempnam(sys_get_temp_dir(), 'apk');
        $z = new \ZipArchive;
        $z->open($zip, \ZipArchive::OVERWRITE);
        $z->addFromString('leia-me.txt', 'nada');
        $z->close();

        $this->actingAs($this->adminTecnologia())->post(route('admin.aplicativo.store'), ['apk' => $this->upload($zip)])
            ->assertSessionHasErrors(['apk' => 'O APK não tem o AndroidManifest.xml.']);
        $this->post(route('admin.aplicativo.store'), ['apk' => $this->upload(self::APK, 'oee.zip')])->assertSessionHasErrors('apk');
        $this->assertSame(0, VersaoAplicativo::withoutGlobalScopes()->count());
    }

    public function test_so_o_administrador_de_tecnologia_publica(): void
    {
        $adminProducao = Usuario::create(['entra_oid' => 'p', 'nome' => 'P', 'email' => 'p@alumbra.com.br', 'perfil' => 'operador']);
        $adminProducao->definirPerfis(['admin_producao']);
        $this->actingAs($adminProducao)->get(route('admin.aplicativo'))->assertForbidden();
        $this->post(route('admin.aplicativo.store'), ['apk' => $this->upload()])->assertForbidden();
    }

    public function test_app_ve_a_versao_nova_baixa_e_respeita_a_obrigatoria(): void
    {
        $this->actingAs($this->adminTecnologia())->post(route('admin.aplicativo.store'), ['apk' => $this->upload()]);
        auth()->logout();
        $token = $this->tokenApp();
        $api = fn (string $url) => $this->getJson('/api/app/'.$url, ['Authorization' => 'Bearer '.$token]);

        $api('versao?instalado=21')->assertOk()->assertJsonPath('atual.codigo', 22)->assertJsonPath('atual.nome', '0.12.5')
            ->assertJsonPath('tem_nova', true)->assertJsonPath('obrigatoria', false);
        $api('versao?instalado=22')->assertJsonPath('tem_nova', false);

        $resposta = $api('versao/apk')->assertOk()->assertHeader('X-Sha256', hash_file('sha256', self::APK));
        $this->assertSame(file_get_contents(self::APK), $resposta->streamedContent());

        // Obrigatória: abaixo dela o app tem de atualizar.
        $v = VersaoAplicativo::withoutGlobalScopes()->sole();
        $v->update(['obrigatoria' => true]);
        $api('versao?instalado=21')->assertJsonPath('obrigatoria', true)->assertJsonPath('minima', 22);

        // Desativada: nada a oferecer.
        $v->update(['ativo' => false]);
        $api('versao?instalado=21')->assertJsonPath('atual', null)->assertJsonPath('tem_nova', false)->assertJsonPath('obrigatoria', false);
        $api('versao/apk')->assertNotFound();

        // Sem token, nada.
        $this->getJson('/api/app/versao')->assertUnauthorized();
    }

    public function test_excluir_so_versao_desativada_apaga_o_arquivo_e_mantem_o_historico(): void
    {
        $admin = $this->adminTecnologia();
        $this->actingAs($admin)->post(route('admin.aplicativo.store'), ['apk' => $this->upload()])->assertSessionHas('ok');
        $v = VersaoAplicativo::withoutGlobalScopes()->sole();
        $arquivo = 'aplicativo/oee-alumbra-0.12.5-22.apk';

        // Ativa: recusado, nada muda.
        $this->delete(route('admin.aplicativo.destroy', $v->id))->assertSessionHas('erro');
        Storage::disk('local')->assertExists($arquivo);
        $this->assertNull($v->fresh()->excluido_em);

        $this->put(route('admin.aplicativo.update', $v->id), ['ativo' => 0, 'obrigatoria' => 0]);
        $this->delete(route('admin.aplicativo.destroy', $v->id))->assertSessionHas('ok');
        Storage::disk('local')->assertMissing($arquivo);
        $v = $v->fresh();
        $this->assertNotNull($v->excluido_em);
        $this->assertSame((int) $admin->id, (int) $v->excluido_por_id);

        // Fica na lista como histórico, sem baixar nem reativar.
        $this->get(route('admin.aplicativo'))->assertSee('Excluída')->assertSee('por TI');
        $this->get(route('admin.aplicativo.baixar', $v->id))->assertNotFound();
        $this->put(route('admin.aplicativo.update', $v->id), ['ativo' => 1, 'obrigatoria' => 0])->assertSessionHas('erro');
        $this->assertFalse($v->fresh()->ativo);
        $this->delete(route('admin.aplicativo.destroy', $v->id))->assertSessionHas('erro');
    }

    public function test_so_o_administrador_de_tecnologia_exclui(): void
    {
        $v = VersaoAplicativo::withoutGlobalScopes()->create(['codigo' => 5, 'nome' => '0.1.0', 'arquivo' => 'aplicativo/x.apk',
            'tamanho' => 1, 'sha256' => str_repeat('a', 64), 'ativo' => false, 'unidade' => 'ambos']);
        $u = Usuario::create(['entra_oid' => 'p', 'nome' => 'Prod', 'email' => 'p@alumbra.com.br']);
        $u->definirPerfis(['admin_producao']);
        $this->actingAs($u->fresh())->delete(route('admin.aplicativo.destroy', $v->id))->assertForbidden();
        $this->assertNull($v->fresh()->excluido_em);
    }
}
