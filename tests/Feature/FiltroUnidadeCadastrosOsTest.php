<?php

namespace Tests\Feature;

use App\Models\Manutencao\AreaExecutante;
use App\Models\Manutencao\Ferramenta;
use App\Models\Manutencao\Mantenedor;
use App\Models\Manutencao\PlanoPreventiva;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CalendarioDeTeste;
use Tests\TestCase;

/** Cadastros de OS › Mantenedores e Áreas executantes: filtro de unidade só com as unidades do login. */
class FiltroUnidadeCadastrosOsTest extends TestCase
{
    use CalendarioDeTeste;
    use RefreshDatabase;

    private function usuario(array $perfis): Usuario
    {
        $u = Usuario::create(['entra_oid' => 'oid-'.implode('-', $perfis), 'nome' => 'Admin', 'email' => implode('', $perfis).'@alumbra.com.br']);
        $u->definirPerfis($perfis);

        return $u->fresh();
    }

    private function cenario(): void
    {
        AreaExecutante::create(['codigo' => 'MEC', 'nome' => 'Mecânica', 'unidade' => 'manutencao']);
        AreaExecutante::create(['codigo' => 'TI', 'nome' => 'Suporte de TI', 'unidade' => 'tecnologia']);
        AreaExecutante::create(['codigo' => 'FER', 'nome' => 'Ajuste de molde', 'unidade' => 'ferramentaria']);
        AreaExecutante::create(['codigo' => 'GER', 'nome' => 'Serviços gerais', 'unidade' => 'ambos']);
        Mantenedor::create(['nome' => 'Mecânico Zé', 'unidade' => 'manutencao'] + $this->jornadaGravada());
        Mantenedor::create(['nome' => 'Técnico TI', 'unidade' => 'tecnologia'] + $this->jornadaGravada());
        Mantenedor::create(['nome' => 'Ferramenteiro Lu', 'unidade' => 'ferramentaria'] + $this->jornadaGravada());
    }

    public function test_filtra_pela_unidade_escolhida_e_so_oferece_as_do_login(): void
    {
        $this->cenario();
        $this->actingAs($this->usuario(['admin_producao', 'admin_ferramentaria']));

        $this->get('/ordens-de-servico/cadastros/areas')->assertOk()
            ->assertSee('Mecânica')->assertSee('Ajuste de molde')->assertSee('Serviços gerais')->assertDontSee('Suporte de TI')
            ->assertSee('<option value="ferramentaria"', false)->assertDontSee('<option value="tecnologia"', false);

        $this->get('/ordens-de-servico/cadastros/areas?unidade=ferramentaria')->assertOk()
            ->assertSee('Ajuste de molde')->assertSee('Serviços gerais')->assertDontSee('value="Mecânica"', false);

        $this->get('/ordens-de-servico/cadastros/mantenedores?unidade=manutencao')->assertOk()
            ->assertSee('Mecânico Zé')->assertDontSee('Ferramenteiro Lu')->assertDontSee('Técnico TI');

        // Unidade fora do login pela URL: ignorada (mostra só as dele).
        $this->get('/ordens-de-servico/cadastros/mantenedores?unidade=tecnologia')->assertOk()
            ->assertSee('Mecânico Zé')->assertSee('Ferramenteiro Lu')->assertDontSee('Técnico TI');
    }

    public function test_preventivas_filtram_pela_unidade_e_mantem_o_ano(): void
    {
        $this->cenario();
        $area = AreaExecutante::where('codigo', 'MEC')->first();
        PlanoPreventiva::create(['codigo' => 'PRV-MAN', 'descricao' => 'Lubrificação injetora', 'unidade' => 'manutencao',
            'area_executante_id' => $area->id, 'periodicidade' => 'semanal']);
        PlanoPreventiva::create(['codigo' => 'PRV-FER', 'descricao' => 'Revisão de molde', 'unidade' => 'ferramentaria',
            'area_executante_id' => $area->id, 'periodicidade' => 'semanal']);
        PlanoPreventiva::create(['codigo' => 'PRV-TI', 'descricao' => 'Backup servidor', 'unidade' => 'tecnologia',
            'area_executante_id' => $area->id, 'periodicidade' => 'semanal']);
        $this->actingAs($this->usuario(['admin_producao', 'admin_ferramentaria']));

        $this->get('/ordens-de-servico/preventivas?ano=2027')->assertOk()
            ->assertSee('PRV-MAN')->assertSee('PRV-FER')->assertDontSee('PRV-TI')
            ->assertSee('<input type="hidden" name="ano" value="2027">', false)->assertDontSee('<option value="tecnologia"', false);
        $this->get('/ordens-de-servico/preventivas?ano=2027&unidade=ferramentaria')->assertOk()
            ->assertSee('PRV-FER')->assertDontSee('PRV-MAN')
            ->assertSee('ano=2026&amp;unidade=ferramentaria', false); // o ano anterior mantém o filtro
        $this->get('/ordens-de-servico/preventivas?unidade=tecnologia')->assertOk()->assertSee('PRV-MAN')->assertDontSee('PRV-TI');
    }

    public function test_equipamentos_filtram_pela_unidade(): void
    {
        $this->cenario();
        Ferramenta::create(['codigo' => 'MULT-01', 'descricao' => 'Multímetro', 'unidade' => 'manutencao']);
        Ferramenta::create(['codigo' => 'TORQ-01', 'descricao' => 'Torquímetro', 'unidade' => 'ferramentaria']);
        Ferramenta::create(['codigo' => 'NOTE-01', 'descricao' => 'Notebook', 'unidade' => 'tecnologia']);
        $this->actingAs($this->usuario(['admin_producao', 'admin_ferramentaria']));

        $this->get('/ordens-de-servico/cadastros/equipamentos')->assertOk()
            ->assertSee('MULT-01')->assertSee('TORQ-01')->assertDontSee('NOTE-01');
        $this->get('/ordens-de-servico/cadastros/equipamentos?unidade=ferramentaria')->assertOk()
            ->assertSee('TORQ-01')->assertDontSee('MULT-01')->assertSee('situacao=avulsas&amp;unidade=ferramentaria', false);
        $this->get('/ordens-de-servico/cadastros/equipamentos?unidade=tecnologia')->assertOk()->assertSee('MULT-01')->assertDontSee('NOTE-01');
    }

    public function test_atributos_do_equipamento_quantos_quiser_e_busca_pelo_valor(): void
    {
        $this->cenario();
        $this->actingAs($this->usuario(['admin_producao']));
        $dados = ['unidade' => 'manutencao', 'codigo' => 'clp-01', 'descricao' => 'CLP da injetora', 'ativo' => 1];

        $this->post('/ordens-de-servico/cadastros/equipamentos', $dados + ['atributos' => [
            ['nome' => 'IP', 'valor' => '10.0.5.20'], ['nome' => 'Porta', 'valor' => '502'], ['nome' => '', 'valor' => ''],
        ]])->assertRedirect()->assertSessionHasNoErrors();
        $f = Ferramenta::where('codigo', 'CLP-01')->firstOrFail();
        $this->assertSame(['IP' => '10.0.5.20', 'Porta' => '502'], $f->atributos()->pluck('valor', 'nome')->all());

        // Busca pelo IP e coluna na lista; o formulário traz os atributos.
        $this->get('/ordens-de-servico/cadastros/equipamentos?busca=10.0.5.20')->assertOk()->assertSee('CLP-01')->assertSee('IP:');
        $this->get("/ordens-de-servico/cadastros/equipamentos/{$f->id}/editar")->assertOk()->assertSee('value="10.0.5.20"', false);

        // Edita um, remove outro e acrescenta um terceiro.
        $this->put("/ordens-de-servico/cadastros/equipamentos/{$f->id}", $dados + ['codigo' => 'CLP-01', 'atributos' => [
            ['nome' => 'IP', 'valor' => '10.0.5.21'], ['nome' => 'Firmware', 'valor' => '3.2'],
        ]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['IP' => '10.0.5.21', 'Firmware' => '3.2'], $f->atributos()->pluck('valor', 'nome')->all());

        // Valor sem nome não passa.
        $this->put("/ordens-de-servico/cadastros/equipamentos/{$f->id}", $dados + ['codigo' => 'CLP-01', 'atributos' => [['nome' => '', 'valor' => 'x']]])
            ->assertSessionHasErrors('atributos.0.nome');
    }

    public function test_com_uma_unidade_so_nao_mostra_o_filtro(): void
    {
        $this->cenario();
        $this->actingAs($this->usuario(['admin_producao']));

        $this->get('/ordens-de-servico/cadastros/mantenedores')->assertOk()->assertDontSee('Todas as minhas');
    }

    public function test_salvar_area_volta_com_o_mesmo_filtro(): void
    {
        $this->cenario();
        $this->actingAs($this->usuario(['admin_producao', 'admin_ferramentaria']));
        $area = AreaExecutante::where('codigo', 'FER')->first();

        $this->put("/ordens-de-servico/cadastros/areas/{$area->id}?unidade=ferramentaria", [
            'unidade' => 'ferramentaria', 'codigo' => 'FER', 'nome' => 'Ajuste de moldes', 'ativo' => 1,
        ])->assertRedirect('/ordens-de-servico/cadastros/areas?unidade=ferramentaria');
    }
}
