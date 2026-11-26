<?php

namespace Tests\Feature;

use App\Models\AcessoPagina;
use App\Models\Aplicativo\TokenAplicativo;
use App\Models\Grupo;
use App\Models\RegistroLogin;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Segurança › controle de logins e acessos: painel, histórico com filtros, páginas abertas, sessões e liberações. */
class ControleAcessosTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(array $perfis, string $email, array $extra = []): Usuario
    {
        $u = Usuario::create(['entra_oid' => "oid-{$email}", 'nome' => ucfirst(strtok($email, '@')), 'email' => $email] + $extra);
        $u->definirPerfis($perfis);

        return $u->fresh();
    }

    public function test_admin_de_tecnologia_abre_todas_as_telas_novas(): void
    {
        $admin = $this->usuario(['admin_tecnologia'], 'ti@empresa.com');
        $this->actingAs($admin);
        $this->get('/cadastros/logins/painel')->assertOk()->assertSee('Painel de logins')->assertSee('Usuários ativos hoje');
        $this->get('/cadastros/logins/acessos')->assertOk();
        $this->get('/cadastros/logins/paginas')->assertOk();
        $this->get('/cadastros/logins/sessoes')->assertOk()->assertSee('SESSION_DRIVER=database', false);
        $this->get('/')->assertSee('Painel de logins')->assertSee('Sessões ativas')->assertSee('Acesso às páginas');
    }

    public function test_admin_de_producao_nao_ve_controle_de_acessos_pelo_asterisco(): void
    {
        $this->actingAs($this->usuario(['admin_producao'], 'prod@empresa.com'));
        foreach (['painel', 'acessos', 'paginas', 'sessoes'] as $tela) {
            $this->get("/cadastros/logins/{$tela}")->assertForbidden();
        }
        $this->get('/')->assertDontSee('Painel de logins');
    }

    public function test_liberacao_por_grupo_e_direto_no_usuario(): void
    {
        $porGrupo = $this->usuario(['operador'], 'grupo@empresa.com');
        $grupo = Grupo::create(['nome' => 'Auditoria']);
        $grupo->definirPermissoes(['seguranca_painel']);
        $grupo->usuarios()->attach($porGrupo);
        $this->actingAs($porGrupo->fresh())->get('/cadastros/logins/painel')->assertOk()->assertSee('Painel de logins');
        $this->get('/cadastros/logins/sessoes')->assertForbidden();
        $this->get('/cadastros/logins/usuarios')->assertForbidden(); // usuários e grupos continuam só do Admin de Tecnologia

        $direto = $this->usuario(['operador'], 'direto@empresa.com');
        $admin = $this->usuario(['admin_tecnologia'], 'ti@empresa.com');
        $this->actingAs($admin)->put("/cadastros/logins/usuarios/{$direto->id}", [
            'perfis' => ['operador'], 'ativo' => 1, 'permissoes' => ['seguranca_sessoes', 'calculadora'],
        ])->assertRedirect();
        $this->assertEqualsCanonicalizing(['seguranca_sessoes', 'calculadora'], $direto->fresh()->permissoesDiretas());
        $this->actingAs($direto->fresh())->get('/cadastros/logins/sessoes')->assertOk();
        $this->get('/calculadora')->assertOk();
        $this->get('/cadastros/logins/painel')->assertForbidden();

        // tirar a liberação
        $this->actingAs($admin)->put("/cadastros/logins/usuarios/{$direto->id}", ['perfis' => ['operador'], 'ativo' => 1])->assertRedirect();
        $this->assertSame([], $direto->fresh()->permissoesDiretas());
        $this->actingAs($direto->fresh())->get('/cadastros/logins/sessoes')->assertForbidden();
    }

    public function test_liberacao_direta_sem_perfil_nao_abre_nada(): void
    {
        $u = $this->usuario([], 'semperfil@empresa.com');
        $u->definirPermissoesDiretas(['seguranca_painel']);
        $this->actingAs($u->fresh())->get('/cadastros/logins/painel')->assertRedirect();
    }

    public function test_paginas_abertas_sao_registradas_e_atualizam_o_ultimo_acesso(): void
    {
        $admin = $this->usuario(['admin_tecnologia'], 'ti@empresa.com');
        $this->actingAs($admin)->get('/cadastros/logins/painel')->assertOk();
        $this->get('/cadastros/logins/paginas?parcial=1'); // trecho recarregado não conta
        $this->getJson('/cadastros/logins/painel');           // JSON não conta
        $this->post('/cadastros/logins/sessoes/'.str_repeat('a', 64).'/encerrar'); // POST não conta

        $acesso = AcessoPagina::sole();
        $this->assertSame((int) $admin->id, (int) $acesso->usuario_id);
        $this->assertSame('cadastros.painel-acessos', $acesso->rota);
        $this->assertSame('/cadastros/logins/painel', $acesso->caminho);
        $this->assertNotNull($admin->fresh()->ultimo_acesso_em);

        $this->get('/cadastros/logins/paginas?usuario='.$admin->id)->assertOk()->assertSee('/cadastros/logins/painel');
    }

    public function test_painel_conta_entradas_recusas_e_usuarios_sem_uso(): void
    {
        $admin = $this->usuario(['admin_tecnologia'], 'ti@empresa.com', ['ultimo_login_em' => now()]);
        $this->usuario(['operador'], 'nunca@empresa.com');
        $this->usuario(['operador'], 'antigo@empresa.com', ['ultimo_login_em' => now()->subDays(100)]);
        $this->usuario(['operador'], 'bloqueado@empresa.com', ['ativo' => false]);
        RegistroLogin::create(['usuario_id' => $admin->id, 'email' => 'ti@empresa.com', 'data_hora' => now(), 'sucesso' => true]);
        RegistroLogin::create(['email' => 'x@outra.com', 'data_hora' => now(), 'sucesso' => false, 'motivo' => 'outro locatário']);

        $r = $this->actingAs($admin)->get('/cadastros/logins/painel?dias=7&sem_uso=60')->assertOk();
        $r->assertSee('outro locatário')->assertSee('Nunca entrou')->assertSee('Sem uso há');
        $this->assertSame(1, $r->viewData('entradasHoje'));
        $this->assertSame(1, $r->viewData('recusasPeriodo'));
        $this->assertSame(1, $r->viewData('nuncaEntraram'));
        $this->assertSame(1, $r->viewData('semUso'));
        $this->assertSame(1, $r->viewData('ativosHoje'));
        $this->assertCount(7, $r->viewData('porDia'));

        $this->get('/cadastros/logins/painel?mostrar=nunca')->assertSee('nunca@empresa.com')->assertDontSee('antigo@empresa.com');
    }

    public function test_historico_filtra_por_usuario_periodo_motivo_e_ip(): void
    {
        $admin = $this->usuario(['admin_tecnologia'], 'ti@empresa.com');
        $outro = $this->usuario(['operador'], 'op@empresa.com');
        RegistroLogin::create(['usuario_id' => $outro->id, 'email' => 'op@empresa.com', 'data_hora' => now(), 'sucesso' => true, 'ip' => '10.1.2.3']);
        RegistroLogin::create(['email' => 'velho@empresa.com', 'data_hora' => now()->subDays(40), 'sucesso' => true, 'ip' => '192.168.0.9']);
        RegistroLogin::create(['email' => 'ruim@outra.com', 'data_hora' => now(), 'sucesso' => false, 'motivo' => 'outro locatário']);

        $this->actingAs($admin);
        $this->get('/cadastros/logins/acessos?usuario='.$outro->id)->assertSee('op@empresa.com')->assertDontSee('ruim@outra.com');
        $this->get('/cadastros/logins/acessos?de='.now()->subDays(2)->format('Y-m-d'))->assertDontSee('velho@empresa.com')->assertSee('ruim@outra.com');
        $this->get('/cadastros/logins/acessos?motivo='.urlencode('outro locatário'))->assertSee('ruim@outra.com')->assertDontSee('<td>op@empresa.com</td>', false);
        $this->get('/cadastros/logins/acessos?ip=192.168')->assertSee('velho@empresa.com')->assertDontSee('ruim@outra.com');
        $this->get('/cadastros/logins/acessos?de=lixo&ate=2026-99-99')->assertOk();
    }

    public function test_sessoes_ativas_do_portal_e_do_app_com_desconectar(): void
    {
        config(['session.driver' => 'database']);
        $admin = $this->usuario(['admin_tecnologia'], 'ti@empresa.com');
        $op = $this->usuario(['operador'], 'op@empresa.com');
        DB::table('sessions')->insert([
            ['id' => 'sessao-do-operador', 'user_id' => $op->id, 'ip_address' => '10.0.0.5', 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120 Edg/120', 'payload' => '', 'last_activity' => now()->timestamp],
            ['id' => 'sessao-velha', 'user_id' => $op->id, 'ip_address' => '10.0.0.6', 'user_agent' => '', 'payload' => '', 'last_activity' => now()->subDay()->timestamp],
        ]);
        $token = TokenAplicativo::create(['usuario_id' => $op->id, 'token_hash' => hash('sha256', 't'), 'dispositivo' => 'Galaxy A15', 'ultimo_uso_em' => now()]);

        $this->actingAs($admin)->get('/cadastros/logins/sessoes')->assertOk()
            ->assertSee('Edge · Windows')->assertSee('10.0.0.5')->assertDontSee('10.0.0.6')
            ->assertDontSee('sessao-do-operador') // o id da sessão nunca vai para a tela
            ->assertSee('Galaxy A15');

        $this->post('/cadastros/logins/sessoes/'.hash('sha256', 'sessao-do-operador').'/encerrar')->assertSessionHas('ok');
        $this->assertFalse(DB::table('sessions')->where('id', 'sessao-do-operador')->exists());
        $this->assertTrue(DB::table('sessions')->where('id', 'sessao-velha')->exists());

        $this->post("/cadastros/logins/sessoes/celulares/{$token->id}/revogar")->assertSessionHas('ok');
        $this->assertNotNull($token->fresh()->revogado_em);
    }

    public function test_retencao_apaga_so_paginas_antigas(): void
    {
        $u = $this->usuario(['operador'], 'op@empresa.com');
        AcessoPagina::create(['usuario_id' => $u->id, 'data_hora' => now()->subDays(200), 'caminho' => '/velha']);
        AcessoPagina::create(['usuario_id' => $u->id, 'data_hora' => now()->subDays(10), 'caminho' => '/nova']);
        RegistroLogin::create(['email' => 'op@empresa.com', 'data_hora' => now()->subDays(400), 'sucesso' => true]);

        Artisan::call('acessos:limpar-paginas');
        $this->assertSame(['/nova'], AcessoPagina::pluck('caminho')->all());
        $this->assertSame(1, RegistroLogin::count()); // logins nunca são apagados

        config(['oee.acessos_paginas.dias_retencao' => 0]);
        AcessoPagina::create(['usuario_id' => $u->id, 'data_hora' => now()->subDays(900), 'caminho' => '/muito-velha']);
        Artisan::call('acessos:limpar-paginas');
        $this->assertSame(2, AcessoPagina::count());
    }
}
