<?php

namespace Tests\Feature;

use App\Models\Usuario;
use App\Services\IdentidadeEntra;
use App\Services\RegistroDeLogin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Importação das contas do Entra ID: novas chegam BLOQUEADAS; as que já existem não mudam. */
class ImportadorUsuariosEntraTest extends TestCase
{
    use RefreshDatabase;

    private function falsoGraph(): void
    {
        config(['services.azure.tenant' => '11111111-1111-1111-1111-111111111111', 'services.azure.client_id' => 'cli', 'services.azure.client_secret' => 'seg']);
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'tok-app']),
            'graph.microsoft.com/v1.0/users?*' => Http::sequence()
                ->push(['value' => [
                    ['id' => 'AAA-1', 'displayName' => 'Ana Nova', 'mail' => 'Ana@empresa.com', 'userPrincipalName' => 'ana@empresa.com', 'accountEnabled' => true, 'userType' => 'Member', 'department' => 'Produção', 'jobTitle' => 'Operadora', 'employeeId' => '123'],
                    ['id' => 'bbb-2', 'displayName' => 'Bruno Existente', 'mail' => 'bruno@empresa.com', 'accountEnabled' => true, 'userType' => 'Member'],
                ], '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/users?$skiptoken=x'])
                ->push(['value' => [
                    ['id' => 'ccc-3', 'displayName' => 'Convidado', 'mail' => 'c@fora.com', 'userPrincipalName' => 'c_fora.com#EXT#@empresa.onmicrosoft.com', 'accountEnabled' => true, 'userType' => 'Guest'],
                    ['id' => 'ddd-4', 'displayName' => 'Desligado', 'mail' => 'd@empresa.com', 'accountEnabled' => false, 'userType' => 'Member'],
                    ['id' => 'eee-5', 'displayName' => 'Admin', 'mail' => 'admin@empresa.com', 'accountEnabled' => true, 'userType' => 'Member'],
                ]]),
        ]);
    }

    public function test_cadastra_bloqueados_e_nao_mexe_em_quem_existe(): void
    {
        config(['oee.admins' => ['admin@empresa.com']]);
        $bruno = Usuario::create(['entra_oid' => 'bbb-2', 'nome' => 'Bruno Antigo', 'email' => 'bruno@empresa.com', 'ativo' => true]);
        $bruno->definirPerfis(['operador']);
        $adm = Usuario::create(['entra_oid' => 'adm', 'nome' => 'TI', 'email' => 'ti@empresa.com', 'perfil' => 'admin']);
        $this->falsoGraph();

        $this->actingAs($adm)->post('/cadastros/logins/usuarios/importar-entra')->assertSessionHas('ok');

        $ana = Usuario::where('entra_oid', 'aaa-1')->first();
        $this->assertNotNull($ana);
        $this->assertFalse($ana->ativo);
        $this->assertFalse($ana->temAcesso());
        $this->assertSame(['ana@empresa.com', 'Produção', '123'], [$ana->email, $ana->departamento, $ana->matricula]);
        $this->assertSame('Bruno Antigo', $bruno->fresh()->nome); // quem já existia não muda
        $this->assertTrue($bruno->fresh()->temAcesso());
        $this->assertSame(3, Usuario::count()); // convidado, desabilitado e admin ficam de fora
        $this->assertSame('Bearer tok-app', Http::recorded()[1][0]->header('Authorization')[0]);

        // Quem foi importado e tenta entrar fica barrado ("acesso desativado") até ser liberado.
        $this->assertNull(app(RegistroDeLogin::class)->sucesso(new IdentidadeEntra(
            oid: 'aaa-1', tenantId: null, nome: 'Ana Nova', email: 'ana@empresa.com', token: null, tokenExpiraEm: null)));
    }

    public function test_so_o_administrador_de_tecnologia_importa_e_erro_vira_aviso(): void
    {
        $op = Usuario::create(['entra_oid' => 'op', 'nome' => 'Op', 'email' => 'op@empresa.com']);
        $op->definirPerfis(['admin_producao']);
        $this->actingAs($op->fresh())->post('/cadastros/logins/usuarios/importar-entra')->assertForbidden();

        $adm = Usuario::create(['entra_oid' => 'adm', 'nome' => 'TI', 'email' => 'ti@empresa.com', 'perfil' => 'admin']);
        config(['services.azure.tenant' => 't', 'services.azure.client_id' => 'c', 'services.azure.client_secret' => 's']);
        Http::fake(['login.microsoftonline.com/*' => Http::response(['access_token' => 'x']), 'graph.microsoft.com/*' => Http::response([], 403)]);
        $this->actingAs($adm)->post('/cadastros/logins/usuarios/importar-entra')->assertSessionHas('erro', fn ($m) => str_contains($m, 'User.Read.All'));
        $this->assertSame(2, Usuario::count());
    }
}
