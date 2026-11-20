<?php

namespace Tests\Feature;

use App\Models\ApontamentoEstado;
use App\Models\Legenda;
use App\Models\Linha;
use App\Models\Manutencao\AreaExecutante;
use App\Models\Manutencao\SolicitacaoServico;
use App\Models\Maquina;
use App\Models\OrdemProducao;
use App\Models\Planta;
use App\Models\Usuario;
use App\Services\TrocaOpMaquina;
use App\Support\AcessoOs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\IotDeTeste;
use Tests\TestCase;

/** Falhas da auditoria antes da produção (apontamento real × SS automática): não podem voltar. */
class CorrecoesAuditoriaProducaoTest extends TestCase
{
    use IotDeTeste;
    use RefreshDatabase;

    private Maquina $maquina;

    private Legenda $quebra;

    protected function setUp(): void
    {
        parent::setUp();
        AcessoOs::limparCache();
        $linha = Linha::create(['planta_id' => Planta::create(['codigo' => 'P', 'nome' => 'Planta'])->id, 'codigo' => 'L', 'nome' => 'Linha']);
        $this->maquina = Maquina::create(['codigo' => 'INJ-12', 'nome' => 'Injetora 12', 'linha_id' => $linha->id, 'ativo' => false, 'unidade' => 'manutencao'] + $this->iotGravado());
        $mec = AreaExecutante::create(['codigo' => 'MEC', 'nome' => 'Mecânica', 'unidade' => 'manutencao', 'ativo' => true]);
        $this->quebra = Legenda::create(['codigo' => 'XQB', 'descricao' => 'Quebra', 'tipo' => 'parada', 'cor' => '#d97706', 'unidade' => 'manutencao']);
        $this->quebra->forceFill(['abre_ss_unidade' => 'manutencao', 'abre_ss_area_executante_id' => $mec->id])->save();
        $u = Usuario::create(['entra_oid' => 'oid-adm', 'nome' => 'Adm', 'email' => 'adm@empresa.com']);
        $u->definirPerfis(['admin_producao']);
        $this->actingAs($u->fresh());
    }

    private function op(string $num): OrdemProducao
    {
        return OrdemProducao::create(['c2_filial' => '01', 'c2_num' => $num, 'c2_item' => '01', 'c2_sequen' => '001', 'numero' => "{$num}01001", 'status' => 'aberta']);
    }

    public function test_apontamento_que_abriu_ss_nao_e_excluido_e_avisa(): void
    {
        $ap = ApontamentoEstado::create(['maquina_id' => $this->maquina->id, 'legenda_id' => $this->quebra->id, 'inicio' => now()->subHour()]);
        $this->assertSame(1, SolicitacaoServico::withoutGlobalScopes()->count());

        $this->delete("/producao/apontamentos/{$ap->id}")->assertRedirect()->assertSessionHas('erro', fn ($m) => str_contains($m, 'não pode ser excluído'));
        $this->assertNotNull($ap->fresh());
    }

    public function test_troca_de_op_com_novo_apontamento_nao_abre_outra_ss(): void
    {
        $op1 = $this->op('000001');
        $this->op('000002');
        $ap = ApontamentoEstado::create(['maquina_id' => $this->maquina->id, 'legenda_id' => $this->quebra->id, 'ordem_producao_id' => $op1->id, 'inicio' => now()->subHour()]);
        $ss = SolicitacaoServico::withoutGlobalScopes()->sole();

        app(TrocaOpMaquina::class)->trocar($this->maquina->fresh(), '00000201001', TrocaOpMaquina::NOVO);

        $this->assertSame(1, SolicitacaoServico::withoutGlobalScopes()->count());
        $novo = ApontamentoEstado::whereKeyNot($ap->id)->sole();
        $this->assertSame((int) $ss->id, (int) $novo->solicitacao_vinculada_id); // a Timeline segue mostrando o chamado
        $this->assertNotNull($novo->criado_por_id);
    }
}
