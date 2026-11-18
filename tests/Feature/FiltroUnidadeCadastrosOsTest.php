<?php

namespace Tests\Feature;

use App\Models\Manutencao\AreaExecutante;
use App\Models\Manutencao\Mantenedor;
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
