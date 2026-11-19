<?php

namespace App\Services;

use App\Models\ApontamentoEstado;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Regras de horário do apontamento real (dia no fuso local, oee.fuso_exibicao):
 * - um apontamento fica dentro de UM dia (início e fim no mesmo dia; no máximo até 23:59:59);
 * - se a máquina já tem apontamento naquele dia, o novo começa OBRIGATORIAMENTE 1 segundo depois do fim do último
 *   (encadeado); se o último ainda está em aberto, o novo começa depois do início dele e o encerra 1 segundo antes;
 * - o primeiro apontamento do dia tem horário livre.
 */
class HorarioApontamento
{
    public static function fuso(): string
    {
        return config('oee.fuso_exibicao');
    }

    /** "2026-11-12" + "08:10:05" (fuso local) → UTC. */
    public static function montar(string $dia, string $hora): CarbonImmutable
    {
        return CarbonImmutable::parse($dia.' '.(strlen($hora) === 5 ? $hora.':00' : $hora), self::fuso())->utc();
    }

    /**
     * Último apontamento da máquina no dia (antes do apontamento informado, ao editar).
     */
    public function anteriorNoDia(int $maquinaId, string $dia, ?ApontamentoEstado $editando = null): ?ApontamentoEstado
    {
        $inicioDia = CarbonImmutable::parse($dia, self::fuso())->startOfDay();

        return ApontamentoEstado::with('legenda')->where('maquina_id', $maquinaId)
            ->where('inicio', '>=', $inicioDia->utc())->where('inicio', '<', $inicioDia->addDay()->utc())
            ->when($editando?->exists, fn ($q) => $q->whereKeyNot($editando->id)->where('inicio', '<', $editando->getOriginal('inicio')))
            ->orderByDesc('inicio')->orderByDesc('id')->first();
    }

    /**
     * Horário inicial para um apontamento da máquina no dia.
     *
     * @return array{travado: bool, hora: ?string, mensagem: string, completo: bool}
     */
    public function proximoInicio(int $maquinaId, string $dia, ?ApontamentoEstado $editando = null): array
    {
        $anterior = $this->anteriorNoDia($maquinaId, $dia, $editando);
        if (! $anterior) {
            return ['travado' => false, 'hora' => null, 'completo' => false, 'mensagem' => 'Primeiro apontamento do dia: horário livre.'];
        }
        $codigo = $anterior->legenda?->codigo;
        if ($anterior->fim === null) {
            $desde = CarbonImmutable::parse($anterior->inicio)->setTimezone(self::fuso())->format('H:i:s');

            return ['travado' => false, 'hora' => null, 'completo' => false,
                'mensagem' => "O apontamento {$codigo} das {$desde} está em aberto: escolha um horário depois dele; ele será encerrado 1 segundo antes."];
        }
        $proximo = CarbonImmutable::parse($anterior->fim)->setTimezone(self::fuso())->addSecond();
        if ($proximo->format('Y-m-d') !== $dia) {
            return ['travado' => true, 'hora' => null, 'completo' => true, 'mensagem' => 'Este dia já está completo (o último apontamento vai até 23:59:59).'];
        }

        return ['travado' => true, 'hora' => $proximo->format('H:i:s'), 'completo' => false,
            'mensagem' => 'Começa 1 segundo depois do fim do apontamento anterior desse dia ('.$codigo.', até '.CarbonImmutable::parse($anterior->fim)->setTimezone(self::fuso())->format('H:i:s').').'];
    }

    /** Fim automático de um apontamento em aberto quando outro começa: 1 s antes, sem passar do dia dele. */
    public static function fimAutomatico(ApontamentoEstado $aberto, CarbonInterface $inicioNovo): CarbonImmutable
    {
        $fimDoDia = CarbonImmutable::parse($aberto->inicio)->setTimezone(self::fuso())->endOfDay()->startOfSecond()->utc();
        $umSegundoAntes = CarbonImmutable::parse($inicioNovo)->subSecond();

        return CarbonImmutable::parse($umSegundoAntes->lessThan($fimDoDia) ? $umSegundoAntes : $fimDoDia);
    }

    /**
     * Encerra o apontamento em aberto quando outro começa: 1 s antes do novo, sem passar do dia dele (23:59:59). Se o
     * novo é de outro dia, a madrugada não se perde: para cada dia seguinte é criada uma CONTINUAÇÃO com a mesma
     * legenda, OP e chamado (00:00:00 até 23:59:59 ou até 1 s antes do novo). As continuações não disparam a SS
     * automática nem mudam a situação da máquina (são o mesmo estado).
     *
     * @return list<ApontamentoEstado> continuações criadas
     */
    public static function encerrarAberto(ApontamentoEstado $aberto, CarbonInterface $inicioNovo): array
    {
        $aberto->update(['fim' => self::fimAutomatico($aberto, $inicioNovo)]);
        $limite = CarbonImmutable::parse($inicioNovo)->subSecond();
        $continuacoes = [];
        $dia = CarbonImmutable::parse($aberto->inicio)->setTimezone(self::fuso())->startOfDay()->addDay();
        while ($dia->utc()->lessThanOrEqualTo($limite)) {
            $fimDoDia = $dia->endOfDay()->startOfSecond()->utc();
            $c = new ApontamentoEstado([
                'maquina_id' => $aberto->maquina_id, 'legenda_id' => $aberto->legenda_id, 'ordem_producao_id' => $aberto->ordem_producao_id,
                'inicio' => $dia->utc(), 'fim' => $limite->lessThan($fimDoDia) ? $limite : $fimDoDia, 'referencia' => $aberto->referencia,
                'observacao' => mb_substr('Continuação do apontamento de '.CarbonImmutable::parse($aberto->inicio)->setTimezone(self::fuso())->format('d/m H:i:s')
                    .' (virada do dia)'.($aberto->observacao ? ' · '.$aberto->observacao : ''), 0, 500),
            ]);
            $c->forceFill(['criado_por_id' => auth()->id(), 'solicitacao_vinculada_id' => $aberto->solicitacao_vinculada_id])->saveQuietly();
            $continuacoes[] = $c;
            $dia = $dia->addDay();
        }

        return $continuacoes;
    }
}
