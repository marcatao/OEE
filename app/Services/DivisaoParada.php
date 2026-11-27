<?php

namespace App\Services;

use App\Models\ApontamentoEstado;
use App\Models\Legenda;
use App\Models\Maquina;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Trocar/dividir o motivo da parada atual (app › tela da máquina, pedido do usuário em 27/11/2026): o apontamento
 * de parada em andamento é encerrado 1 s antes de agora e começa um novo 98 "Parada sem motivo" a partir de agora,
 * para o operador escolher o motivo (MotivoParada::informar). Só com a máquina PARADA: se o apontamento atual é de
 * produção (o IoT apontando produção normal), recusa. Se a parada atual já é 98, não divide (só escolhe o motivo).
 * O novo leva o chamado da parada anterior (sem abrir outra SS) e a observação marca o ponto de corte para o
 * integrador manter a divisão (como a "Troca de OP").
 */
class DivisaoParada
{
    public const OBSERVACAO = 'Divisão da parada (aplicativo)';

    public function __construct(private SituacaoMaquina $situacao) {}

    /** @return ApontamentoEstado o apontamento 98 em que o operador vai escolher o motivo */
    public function dividir(Maquina $maquina): ApontamentoEstado
    {
        return DB::transaction(function () use ($maquina) {
            $aberto = ApontamentoEstado::with('legenda')->where('maquina_id', $maquina->id)->whereNull('fim')
                ->orderByDesc('inicio')->orderByDesc('id')->lockForUpdate()->first();
            $maisRecente = (int) ApontamentoEstado::where('maquina_id', $maquina->id)->orderByDesc('inicio')->orderByDesc('id')->value('id');
            if (! $aberto || (int) $aberto->id !== $maisRecente) {
                throw ValidationException::withMessages(['maquina' => 'A máquina não tem uma parada em andamento.']);
            }
            if ($aberto->legenda?->tipo === 'produzindo' || $maquina->situacao === 'produzindo') {
                throw ValidationException::withMessages(['maquina' => 'O IoT está apontando produção normal: não dá para trocar o motivo da parada.']);
            }
            if (MotivoParada::ehSemMotivo($aberto->legenda)) {
                return $aberto; // já é "sem motivo": só falta escolher
            }
            $semMotivo = Legenda::withoutGlobalScopes()->where('codigo', MotivoParada::codigoSemMotivo())->where('ativo', true)->first();
            if (! $semMotivo) {
                throw ValidationException::withMessages(['maquina' => 'A legenda '.MotivoParada::codigoSemMotivo().' (parada sem motivo) não está cadastrada.']);
            }
            $agora = CarbonImmutable::now()->startOfSecond();
            if ($agora->subSecond()->lessThanOrEqualTo($aberto->inicio)) {
                throw ValidationException::withMessages(['maquina' => 'A parada acabou de começar. Tente de novo em alguns segundos.']);
            }
            HorarioApontamento::encerrarAberto($aberto, $agora); // com continuação nos dias seguintes
            $novo = new ApontamentoEstado(['maquina_id' => $maquina->id, 'legenda_id' => $semMotivo->id,
                'ordem_producao_id' => $aberto->ordem_producao_id, 'inicio' => $agora, 'observacao' => self::OBSERVACAO]);
            $novo->forceFill(['criado_por_id' => auth()->id(), 'solicitacao_vinculada_id' => $aberto->chamadoParaContinuar()])->saveQuietly();
            $this->situacao->aplicar($novo);

            return $novo;
        });
    }
}
