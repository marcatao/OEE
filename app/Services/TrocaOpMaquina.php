<?php

namespace App\Services;

use App\Models\ApontamentoEstado;
use App\Models\Maquina;
use App\Models\OrdemProducao;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Trocar OP" da máquina (Timeline do portal e aplicativo). A máquina passa a ter a OP nova (o próximo apontamento
 * já vem com ela) e o apontamento EM ANDAMENTO (o mais recente, sem fim) segue o modo escolhido:
 * - ATUAL: o apontamento em andamento inteiro passa para a OP nova;
 * - NOVO: o apontamento em andamento é encerrado 1 s antes de agora (fica com a OP anterior até agora) e começa outro,
 *   na mesma legenda, com a OP nova (encadeamento de HorarioApontamento). Se ele começou agora, só troca a OP;
 * - AUTOMATICO (aplicativos antigos): sem OP = ATUAL; com outra OP = NOVO.
 * Sem apontamento em andamento, só a máquina muda.
 */
class TrocaOpMaquina
{
    public const ATUAL = 'atual';

    public const NOVO = 'novo';

    public const AUTOMATICO = 'automatico';

    public function __construct(private SituacaoMaquina $situacao, private StatusOrdemProducao $status) {}

    public function trocar(Maquina $maquina, string $numero, string $modo = self::AUTOMATICO, string $origem = 'portal'): OrdemProducao
    {
        $op = OrdemProducao::apontaveis()->where('numero', trim($numero))->orderBy('status')->first();
        if (! $op) {
            throw ValidationException::withMessages(['numero' => "Ordem de produção {$numero} não encontrada ou fechada no Protheus."]);
        }
        if (! in_array($modo, [self::ATUAL, self::NOVO, self::AUTOMATICO], true)) {
            throw ValidationException::withMessages(['modo' => 'Escolha se troca no apontamento atual ou num novo apontamento.']);
        }
        $afetadas = [$op->id, $maquina->ordem_producao_id];

        DB::transaction(function () use ($maquina, $op, $modo, $origem, &$afetadas) {
            $aberto = ApontamentoEstado::where('maquina_id', $maquina->id)->whereNull('fim')
                ->orderByDesc('inicio')->orderByDesc('id')->first();
            // (int): no SQL Server os ids chegam como texto.
            $maisRecente = (int) ApontamentoEstado::where('maquina_id', $maquina->id)->orderByDesc('inicio')->orderByDesc('id')->value('id');

            if ($aberto && (int) $aberto->id === $maisRecente && (int) $aberto->ordem_producao_id !== (int) $op->id) {
                $afetadas[] = $aberto->ordem_producao_id;
                $modoReal = $modo === self::AUTOMATICO ? ($aberto->ordem_producao_id === null ? self::ATUAL : self::NOVO) : $modo;
                $agora = CarbonImmutable::now()->startOfSecond();

                if ($modoReal === self::ATUAL || $agora->subSecond()->lessThanOrEqualTo($aberto->inicio)) {
                    $aberto->update(['ordem_producao_id' => $op->id]); // o apontamento inteiro passa para a OP nova
                    $this->situacao->aplicar($aberto);
                } else {
                    HorarioApontamento::encerrarAberto($aberto, $agora); // com continuação nos dias seguintes
                    $novo = ApontamentoEstado::create(['maquina_id' => $maquina->id, 'legenda_id' => $aberto->legenda_id,
                        'ordem_producao_id' => $op->id, 'inicio' => $agora,
                        'observacao' => 'Troca de OP ('.($origem === 'aplicativo' ? 'aplicativo' : 'Timeline').')']);
                    $this->situacao->aplicar($novo);
                }
            }
            $maquina->update(['ordem_producao_id' => $op->id]);
        });

        foreach (array_unique(array_filter($afetadas)) as $id) {
            $this->status->atualizar(OrdemProducao::find($id));
        }

        return $op;
    }
}
