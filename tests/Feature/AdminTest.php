<?php

namespace Tests\Feature;

use App\Models\Grupo;
use App\Models\Planta;
use App\Models\RegistroLogin;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(string $perfil, string $email): Usuario
    {
        return Usuario::create(['entra_oid' => "oid-{$email}", 'nome' => ucfirst(strtok($email, '@')), 'email' => $email, 'perfil' => $perfil]);
    }

    public function test_admin_ve_todas_as_paginas_de_cadastro_e_o_menu(): void
    {
        $admin = $this->usuario('admin', 'admin@empresa.com');
        $this->usuario('sem_acesso', 'novo@empresa.com');

        $this->actingAs($admin)->get('/cadastros/logins/usuarios')->assertOk()
            ->assertSee('novo@empresa.com')->assertSee('1 usuário aguarda liberação')
            ->assertSee('Cadastros')->assertSee('Logins')->assertSee('Grupos')->assertSee('Histórico de acessos');
        $this->get('/cadastros/logins/grupos')->assertOk();
        $this->get('/cadastros/logins/grupos/novo')->assertOk();
        $this->get('/cadastros/logins/acessos')->assertOk();
        $this->get('/admin/banco')->assertOk()->assertSee('Conexão OK')->assertSee('grupo_usuario');
    }

    public function test_quem_nao_e_admin_nao_acessa_cadastros(): void
    {
        $this->actingAs($this->usuario('operador', 'op@empresa.com'));
        foreach (['/cadastros/logins/usuarios', '/cadastros/logins/grupos', '/cadastros/logins/acessos', '/admin/banco'] as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_usuario_sem_acesso_so_ve_aguardando_liberacao(): void
    {
        $this->actingAs($this->usuario('sem_acesso', 'novo@empresa.com'));

        $this->get('/')->assertOk()->assertSee('aguardando liberação')->assertDontSee('Calculadora');
        $this->get('/calculadora')->assertRedirect('/aguardando-liberacao');
        $this->get('/cadastros/logins/usuarios')->assertRedirect('/aguardando-liberacao');
        $this->get('/aguardando-liberacao')->assertOk();
    }

    public function test_admin_libera_usuario_vira_admin_e_coloca_em_grupos(): void
    {
        $admin = $this->usuario('admin', 'admin@empresa.com');
        $maria = $this->usuario('sem_acesso', 'maria@empresa.com');
        $grupo = Grupo::create(['nome' => 'Supervisores']);

        $this->actingAs($admin)->get("/cadastros/logins/usuarios/{$maria->id}/editar")->assertOk()->assertSee('Supervisores');
        $this->put("/cadastros/logins/usuarios/{$maria->id}", ['perfil' => 'admin', 'ativo' => '1', 'grupos' => [$grupo->id]])
            ->assertRedirect('/cadastros/logins/usuarios')->assertSessionHas('ok');

        $maria->refresh();
        $this->assertSame('admin', $maria->perfil);
        $this->assertSame(['Supervisores'], $maria->grupos->pluck('nome')->all());

        // e depois rebaixa para operador e tira dos grupos
        $this->put("/cadastros/logins/usuarios/{$maria->id}", ['perfil' => 'operador', 'ativo' => '1']);
        $this->assertSame('operador', $maria->refresh()->perfil);
        $this->assertCount(0, $maria->grupos);
    }

    public function test_admin_nao_tira_o_proprio_acesso(): void
    {
        $admin = $this->usuario('admin', 'admin@empresa.com');

        $this->actingAs($admin)->put("/cadastros/logins/usuarios/{$admin->id}", ['perfil' => 'operador', 'ativo' => '1'])
            ->assertSessionHas('erro');
        $this->assertSame('admin', $admin->refresh()->perfil);
    }

    public function test_perfil_invalido_e_rejeitado(): void
    {
        $admin = $this->usuario('admin', 'admin@empresa.com');
        $maria = $this->usuario('operador', 'maria@empresa.com');

        $this->actingAs($admin)->put("/cadastros/logins/usuarios/{$maria->id}", ['perfil' => 'dono', 'ativo' => '1'])
            ->assertSessionHasErrors('perfil');
    }

    public function test_admin_cria_e_edita_grupo_com_permissoes_e_membros(): void
    {
        $admin = $this->usuario('admin', 'admin@empresa.com');
        $op = $this->usuario('operador', 'op@empresa.com');

        $this->actingAs($admin)->post('/cadastros/logins/grupos', [
            'nome' => 'Operadores Linha 1', 'descricao' => 'Turno A', 'ativo' => '1',
            'permissoes' => ['calculadora', 'inexistente'], 'usuarios' => [$op->id],
        ])->assertSessionHasErrors('permissoes.1');
        $this->assertSame(0, Grupo::count());

        $this->post('/cadastros/logins/grupos', [
            'nome' => 'Operadores Linha 1', 'ativo' => '1', 'permissoes' => ['calculadora'], 'usuarios' => [$op->id],
        ])->assertRedirect('/cadastros/logins/grupos');

        $grupo = Grupo::sole();
        $this->assertSame(['calculadora'], $grupo->permissoes());
        $this->assertSame([$op->id], $grupo->usuarios->pluck('id')->all());
        $this->assertSame($admin->id, $grupo->criado_por_id);

        $this->put("/cadastros/logins/grupos/{$grupo->id}", ['nome' => 'Operadores L1', 'ativo' => '1'])->assertRedirect();
        $grupo->refresh();
        $this->assertSame('Operadores L1', $grupo->nome);
        $this->assertSame([], $grupo->permissoes());
        $this->assertCount(0, $grupo->usuarios);
    }

    public function test_nome_de_grupo_repetido_e_rejeitado(): void
    {
        Grupo::create(['nome' => 'Qualidade']);
        $this->actingAs($this->usuario('admin', 'admin@empresa.com'))
            ->post('/cadastros/logins/grupos', ['nome' => 'Qualidade', 'ativo' => '1'])
            ->assertSessionHasErrors(['nome' => 'Já existe um grupo com esse nome.']);
    }

    public function test_permissao_de_tela_vem_dos_grupos_ativos(): void
    {
        $op = $this->usuario('operador', 'op@empresa.com');
        $this->actingAs($op)->get('/calculadora')->assertForbidden(); // operador sem grupo

        $grupo = Grupo::create(['nome' => 'Produção']);
        $grupo->definirPermissoes(['calculadora']);
        $grupo->usuarios()->attach($op);
        $this->actingAs($op->fresh())->get('/calculadora')->assertOk();

        $grupo->update(['ativo' => false]); // grupo inativo não dá permissão
        $this->actingAs($op->fresh())->get('/calculadora')->assertForbidden();

        $this->actingAs($this->usuario('admin', 'admin@empresa.com'))->get('/calculadora')->assertOk(); // admin pode tudo
    }

    public function test_historico_de_acessos_filtra_recusados(): void
    {
        $admin = $this->usuario('admin', 'admin@empresa.com');
        RegistroLogin::create(['email' => 'ok@empresa.com', 'data_hora' => now(), 'sucesso' => true]);
        RegistroLogin::create(['email' => 'ruim@outra.com', 'data_hora' => now(), 'sucesso' => false, 'motivo' => 'outro locatário']);

        $this->actingAs($admin)->get('/cadastros/logins/acessos?resultado=recusado')
            ->assertSee('ruim@outra.com')->assertDontSee('ok@empresa.com');
    }

    public function test_auditoria_grava_quem_criou(): void
    {
        $admin = $this->usuario('admin', 'admin@empresa.com');
        $this->actingAs($admin);

        $planta = Planta::create(['codigo' => 'P1', 'nome' => 'Planta 1']);
        $this->assertSame($admin->id, $planta->criado_por_id);
        $this->assertNotNull($planta->criado_em);
    }
}
