<?php

namespace Tests\Feature;

use App\Models\Legenda;
use App\Models\Linha;
use App\Models\Maquina;
use App\Models\Planta;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dois ambientes: Manutenção (produção) e Tecnologia. Cada cadastro tem "unidade" (manutencao, tecnologia ou ambos) e
 * cada usuário enxerga as unidades dos perfis dele.
 */
class UnidadesTest extends TestCase
{
    use RefreshDatabase;

    private Linha $linha;

    protected function setUp(): void
    {
        parent::setUp();
        $planta = Planta::create(['codigo' => 'P', 'nome' => 'Planta']);
        $this->linha = Linha::create(['planta_id' => $planta->id, 'codigo' => 'L', 'nome' => 'Linha']);
        Maquina::create(['linha_id' => $this->linha->id, 'codigo' => 'INJ-01', 'nome' => 'Injetora 01', 'ativo' => true]);
        Maquina::create(['linha_id' => $this->linha->id, 'codigo' => 'SRV-01', 'nome' => 'Servidor 01', 'ativo' => true, 'unidade' => 'tecnologia']);
        Maquina::create(['linha_id' => $this->linha->id, 'codigo' => 'NOB-01', 'nome' => 'Nobreak 01', 'ativo' => true, 'unidade' => 'ambos']);
    }

    private function usuario(array $perfis, string $email): Usuario
    {
        $u = Usuario::create(['entra_oid' => "oid-{$email}", 'nome' => 'Fulano', 'email' => $email]);
        $u->definirPerfis($perfis);

        return $u->fresh();
    }

    public function test_cadastros_antigos_sao_de_manutencao(): void
    {
        $this->assertSame('manutencao', Maquina::where('codigo', 'INJ-01')->value('unidade'));
        $this->assertSame('manutencao', Planta::value('unidade'));
        $this->assertSame('manutencao', Legenda::create(['codigo' => 'P', 'descricao' => 'Produzindo', 'tipo' => 'produzindo', 'cor' => '#00aa00'])->fresh()->unidade);
    }

    public function test_cada_perfil_ve_as_maquinas_da_sua_unidade(): void
    {
        $this->actingAs($this->usuario(['admin_producao'], 'prod@empresa.com'));
        $this->get('/admin/maquinas')->assertOk()->assertSee('INJ-01')->assertSee('NOB-01')->assertDontSee('SRV-01');
        $this->get('/admin/maquinas/'.Maquina::withoutGlobalScopes()->where('codigo', 'SRV-01')->value('id').'/editar')->assertNotFound();

        $this->actingAs($this->usuario(['mantenedor_tecnologia'], 'ti@empresa.com'));
        $this->get('/producao/dashboard')->assertOk()->assertSee('Servidor 01')->assertSee('Nobreak 01')->assertDontSee('Injetora 01');

        // Administrador de Tecnologia vê tudo de tudo.
        $this->actingAs($this->usuario(['admin_tecnologia'], 'adm-ti@empresa.com'));
        $this->get('/admin/maquinas')->assertOk()->assertSee('INJ-01')->assertSee('SRV-01')->assertSee('NOB-01');
    }

    public function test_filtro_de_unidade_no_cadastro_de_maquinas(): void
    {
        // Quem enxerga as três áreas filtra por qualquer uma; "Todas" (ambos) aparece junto da área filtrada.
        $this->actingAs($this->usuario(['admin_tecnologia'], 'adm-ti@empresa.com'));
        $this->get('/admin/maquinas')->assertOk()->assertSee('<select name="unidade">', false)
            ->assertSee('<option value="ferramentaria"', false);
        $this->get('/admin/maquinas?unidade=tecnologia')->assertOk()->assertSee('SRV-01')->assertSee('NOB-01')->assertDontSee('INJ-01');
        $this->get('/admin/maquinas?unidade=manutencao')->assertOk()->assertSee('INJ-01')->assertDontSee('SRV-01');

        // Quem só tem uma área não vê o filtro, e um valor de outra área na URL é ignorado.
        $this->actingAs($this->usuario(['admin_producao'], 'prod@empresa.com'));
        $this->get('/admin/maquinas')->assertOk()->assertDontSee('<select name="unidade">', false);
        $this->get('/admin/maquinas?unidade=tecnologia')->assertOk()->assertSee('INJ-01')->assertDontSee('SRV-01');
    }

    public function test_opcoes_de_unidade_no_cadastro(): void
    {
        // Linha de planta "Todas": serve às máquinas de qualquer unidade.
        $comum = Linha::create(['unidade' => 'ambos', 'codigo' => 'LC', 'nome' => 'Linha comum',
            'planta_id' => Planta::create(['unidade' => 'ambos', 'codigo' => 'PC', 'nome' => 'Planta comum'])->id]);
        $dados = fn (string $codigo, string $unidade) => ['codigo' => $codigo, 'nome' => $codigo, 'linha_id' => $comum->id,
            'unidade' => $unidade, 'ativo' => '0'];

        // Só produção: Manutenção.
        $this->actingAs($this->usuario(['admin_producao'], 'prod@empresa.com'));
        $this->get('/admin/maquinas/nova')->assertOk()->assertSee('<option value="manutencao"', false)
            ->assertDontSee('<option value="tecnologia"', false)->assertDontSee('<option value="ambos"', false);
        $this->post('/admin/maquinas', $dados('M-1', 'tecnologia'))->assertSessionHasErrors('unidade');

        // Só tecnologia: Tecnologia.
        $this->actingAs($this->usuario(['admin_tecnologia'], 'ti@empresa.com'));
        $this->post('/admin/maquinas', $dados('M-2', 'manutencao'))->assertSessionHasErrors('unidade');
        $this->post('/admin/maquinas', $dados('M-3', 'tecnologia'))->assertSessionHasNoErrors();

        // Os dois: Ambos também.
        $this->actingAs($this->usuario(['admin_producao', 'admin_tecnologia'], 'tudo@empresa.com'));
        $this->get('/admin/maquinas/nova')->assertSee('<option value="ambos"', false);
        $this->post('/admin/maquinas', $dados('M-4', 'ambos'))->assertSessionHasNoErrors();
        $this->assertSame(['M-3' => 'tecnologia', 'M-4' => 'ambos'],
            Maquina::whereIn('codigo', ['M-3', 'M-4'])->orderBy('codigo')->pluck('unidade', 'codigo')->all());

        // Editar sem mandar a unidade mantém a atual.
        $m = Maquina::where('codigo', 'M-4')->sole();
        $this->put("/admin/maquinas/{$m->id}", ['codigo' => 'M-4', 'nome' => 'Outro nome', 'linha_id' => $this->linha->id, 'ativo' => '0'])
            ->assertSessionHasNoErrors();
        $this->assertSame(['Outro nome', 'ambos'], [$m->fresh()->nome, $m->fresh()->unidade]);
    }

    public function test_marcato_e_os_admins_antigos_tem_todos_os_perfis(): void
    {
        $marcato = Usuario::create(['entra_oid' => 'oid-m', 'nome' => 'Marcato', 'email' => 'marcato@alumbra.com.br', 'perfil' => 'admin']);

        $this->assertSame(array_keys(Usuario::perfisDisponiveis()), $marcato->perfisAcesso());
        $this->assertTrue($marcato->podeSeguranca());
        $this->assertNull($marcato->unidadesVisiveis()); // vê as duas unidades
        $this->assertSame(['manutencao', 'tecnologia', 'ferramentaria', 'ambos'], $marcato->unidadesParaCadastro());
    }

    public function test_ferramentaria_aparece_em_todos_os_cadastros_e_no_painel(): void
    {
        $telas = ['/admin/maquinas/nova', '/admin/plantas-e-linhas', '/ordens-de-servico/cadastros/mantenedores/novo',
            '/ordens-de-servico/cadastros/areas'];

        // Quem administra as três áreas escolhe qualquer uma (e "Todas") em cada cadastro e tem o painel das três.
        $this->actingAs($this->usuario(['admin_producao', 'admin_tecnologia', 'admin_ferramentaria'], 'tres@empresa.com'));
        foreach ($telas as $tela) {
            foreach (['manutencao', 'tecnologia', 'ferramentaria', 'ambos'] as $valor) {
                $this->get($tela)->assertOk()->assertSee('<option value="'.$valor.'"', false);
            }
        }
        $this->get('/ordens-de-servico/painel?unidade=ferramentaria')->assertOk()->assertSee('Ferramentaria');

        // Só Ferramentaria: só ela nos cadastros.
        $this->actingAs($this->usuario(['admin_ferramentaria'], 'ferr@empresa.com'));
        foreach ($telas as $tela) {
            $this->get($tela)->assertOk()->assertSee('<option value="ferramentaria"', false)
                ->assertDontSee('<option value="tecnologia"', false)->assertDontSee('<option value="ambos"', false);
        }
    }
}
