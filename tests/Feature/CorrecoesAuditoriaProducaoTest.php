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
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_buraco_entre_apontamentos_vira_98_para_informar_o_motivo(): void
    {
        $sem = Legenda::create(['codigo' => '98', 'descricao' => 'Parada sem motivo', 'tipo' => 'parada', 'cor' => '#b42318', 'unidade' => 'manutencao']);
        $setup = Legenda::create(['codigo' => 'XST', 'descricao' => 'Setup', 'tipo' => 'parada', 'cor' => '#1d5fa8', 'unidade' => 'manutencao']);
        $fuso = config('oee.fuso_exibicao');
        $hora = fn (string $h) => CarbonImmutable::parse("2026-11-20 {$h}", $fuso)->utc();
        $a = ApontamentoEstado::create(['maquina_id' => $this->maquina->id, 'legenda_id' => $setup->id, 'inicio' => $hora('08:00:00'), 'fim' => $hora('09:59:59')]);
        $b = ApontamentoEstado::create(['maquina_id' => $this->maquina->id, 'legenda_id' => $setup->id, 'inicio' => $hora('10:00:00'), 'fim' => $hora('10:59:59')]);
        ApontamentoEstado::create(['maquina_id' => $this->maquina->id, 'legenda_id' => $setup->id, 'inicio' => $hora('11:00:00'), 'fim' => $hora('11:59:59')]);

        // Dividir a parada das 08:00: encurta até 09:00 → das 09:00:01 às 09:59:59 vira 98 para editar.
        $this->put("/producao/apontamentos/{$a->id}", ['maquina_id' => $this->maquina->id, 'legenda_id' => $setup->id,
            'dia' => '2026-11-20', 'hora_inicio' => '08:00:00', 'hora_fim' => '09:00:00'])->assertSessionHasNoErrors();
        $buraco = ApontamentoEstado::where('legenda_id', $sem->id)->sole();
        $local = fn ($c) => $c->copy()->setTimezone($fuso)->format('H:i:s');
        $this->assertSame(['09:00:01', '09:59:59'], [$local($buraco->inicio), $local($buraco->fim)]);

        // Excluir o do meio (10:00–10:59:59): também vira 98.
        $this->delete("/producao/apontamentos/{$b->id}")->assertRedirect();
        $this->assertSame(2, ApontamentoEstado::where('legenda_id', $sem->id)->count());
        // Sem buracos: cada um começa 1 s depois do fim do anterior.
        $lista = ApontamentoEstado::where('maquina_id', $this->maquina->id)->orderBy('inicio')->get();
        foreach ($lista->slice(1)->values() as $i => $atual) {
            $this->assertSame($lista[$i]->fim->getTimestamp() + 1, $atual->inicio->getTimestamp());
        }
    }

    public function test_so_o_mais_recente_fica_em_aberto(): void
    {
        Legenda::create(['codigo' => '98', 'descricao' => 'Parada sem motivo', 'tipo' => 'parada', 'cor' => '#b42318', 'unidade' => 'manutencao']);
        $prod = Legenda::create(['codigo' => 'XPR', 'descricao' => 'Produção', 'tipo' => 'produzindo', 'cor' => '#2e7d32', 'unidade' => 'manutencao']);
        $fuso = config('oee.fuso_exibicao');
        $hora = fn (string $h) => CarbonImmutable::parse("2026-11-20 {$h}", $fuso)->utc();
        // Como no print: produção em aberto desde 12:00:47 e parada (IoT) em aberto desde 13:37:22.
        $p = DB::table('apontamentos_estado')->insertGetId(['maquina_id' => $this->maquina->id,
            'legenda_id' => $prod->id, 'inicio' => $hora('12:00:47'), 'criado_em' => now(), 'atualizado_em' => now()]);
        $q = DB::table('apontamentos_estado')->insertGetId(['maquina_id' => $this->maquina->id,
            'legenda_id' => $this->quebra->id, 'inicio' => $hora('13:37:22'), 'observacao' => '[IoT] x', 'criado_em' => now(), 'atualizado_em' => now()]);

        $this->artisan('apontamentos:conferir-abertos')->assertSuccessful();
        $local = fn ($c) => $c?->copy()->setTimezone($fuso)->format('H:i:s');
        $this->assertSame('13:37:21', $local(ApontamentoEstado::find($p)->fim));
        $this->assertNull(ApontamentoEstado::find($q)->fim);
        $this->assertSame(1, ApontamentoEstado::where('maquina_id', $this->maquina->id)->whereNull('fim')->count());
    }
}
