<?php

namespace App\Services;

use App\Models\ApontamentoEstado;
use App\Models\Legenda;
use App\Providers\AppServiceProvider;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

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
        $chamado = $aberto->chamadoParaContinuar();
        $dia = CarbonImmutable::parse($aberto->inicio)->setTimezone(self::fuso())->startOfDay()->addDay();
        while ($dia->utc()->lessThanOrEqualTo($limite)) {
            $fimDoDia = $dia->endOfDay()->startOfSecond()->utc();
            $c = new ApontamentoEstado([
                'maquina_id' => $aberto->maquina_id, 'legenda_id' => $aberto->legenda_id, 'ordem_producao_id' => $aberto->ordem_producao_id,
                'inicio' => $dia->utc(), 'fim' => $limite->lessThan($fimDoDia) ? $limite : $fimDoDia, 'referencia' => $aberto->referencia,
                'observacao' => mb_substr('Continuação do apontamento de '.CarbonImmutable::parse($aberto->inicio)->setTimezone(self::fuso())->format('d/m H:i:s')
                    .' (virada do dia)'.($aberto->observacao ? ' · '.$aberto->observacao : ''), 0, 500),
            ]);
            $c->forceFill(['criado_por_id' => auth()->id(), 'solicitacao_vinculada_id' => $chamado])->saveQuietly();
            $continuacoes[] = $c;
            $dia = $dia->addDay();
        }

        return $continuacoes;
    }

    /**
     * Buracos entre apontamentos da máquina (ex.: encurtou o fim de uma parada para dividi-la em mais de uma legenda,
     * ou excluiu um do meio) viram apontamentos 98 "Parada sem motivo" (config oee.app.legenda_sem_motivo), um por dia,
     * para serem editados (pisca "INFORMAR MOTIVO" na Timeline). Assim cada apontamento começa 1 s depois do fim do
     * anterior. Olha um dia antes e dois depois de $perto. Devolve quantos criou.
     */
    public static function preencherBuracos(int $maquinaId, CarbonInterface $perto): int
    {
        $legendaId = Legenda::withoutGlobalScopes()->where('codigo', MotivoParada::codigoSemMotivo())->value('id');
        if (! $legendaId) {
            return 0;
        }
        $centro = CarbonImmutable::instance($perto)->setTimezone(self::fuso());
        $lista = ApontamentoEstado::where('maquina_id', $maquinaId)
            ->tocando($centro->subDay()->startOfDay()->utc(), $centro->addDays(2)->startOfDay()->utc())
            ->orderBy('inicio')->orderBy('id')->get(['id', 'inicio', 'fim', 'ordem_producao_id']);
        $criados = 0;
        foreach ($lista->values() as $i => $a) {
            $b = $lista->values()[$i + 1] ?? null;
            if (! $b || $a->fim === null) {
                continue;
            }
            $ini = CarbonImmutable::instance($a->fim)->addSecond();
            $fimBuraco = CarbonImmutable::instance($b->inicio)->subSecond();
            while ($ini->lessThanOrEqualTo($fimBuraco)) {
                $fimDoDia = $ini->setTimezone(self::fuso())->endOfDay()->startOfSecond()->utc();
                $fim = $fimBuraco->lessThan($fimDoDia) ? $fimBuraco : $fimDoDia;
                $novo = new ApontamentoEstado(['maquina_id' => $maquinaId, 'legenda_id' => $legendaId,
                    'ordem_producao_id' => $a->ordem_producao_id, 'inicio' => $ini, 'fim' => $fim,
                    'observacao' => 'Intervalo sem apontamento: informe o motivo']);
                $novo->save();
                $criados++;
                $ini = $fim->addSecond();
            }
        }

        return $criados;
    }

    /**
     * Só o apontamento MAIS RECENTE da máquina pode ficar em aberto. Outro em aberto antes dele (ex.: ajuste manual
     * misturado com o IoT) é encerrado 1 s antes do seguinte, sem passar do dia dele; o que sobrar do dia vira 98 para
     * informar o motivo (preencherBuracos). Devolve quantos encerrou.
     */
    public static function fecharAbertosAnteriores(int $maquinaId): int
    {
        $abertos = ApontamentoEstado::where('maquina_id', $maquinaId)->whereNull('fim')->orderBy('inicio')->orderBy('id')->get();
        if ($abertos->count() < 2) {
            return 0;
        }
        $fechados = 0;
        foreach ($abertos as $aberto) {
            $seguinte = ApontamentoEstado::where('maquina_id', $maquinaId)->whereKeyNot($aberto->id)
                ->where(fn ($q) => $q->where('inicio', '>', $aberto->inicio)
                    ->orWhere(fn ($e) => $e->where('inicio', $aberto->inicio)->where('id', '>', $aberto->id)))
                ->orderBy('inicio')->orderBy('id')->first();
            if (! $seguinte) {
                continue; // é o mais recente: pode ficar em aberto
            }
            $aberto->update(['fim' => self::fimAutomatico($aberto, $seguinte->inicio)]);
            self::preencherBuracos($maquinaId, $aberto->inicio);
            $fechados++;
        }

        return $fechados;
    }

    /**
     * Validação (pedido do usuário em 23/11/2026): o apontamento EM ABERTO (sem fim) só pode ser o ÚLTIMO da máquina.
     * Recusa criar, reabrir (apagar o fim) ou mover para trás um apontamento em aberto quando a máquina já tem outro
     * que começa depois dele. Chamada pelo evento `saving` do ApontamentoEstado (portal, app e serviços do portal).
     *
     * @throws ValidationException
     */
    public static function exigirUltimoSeAberto(ApontamentoEstado $ap): void
    {
        if ($ap->fim !== null || ! $ap->maquina_id || ! $ap->inicio
            || ($ap->exists && ! $ap->isDirty(['fim', 'inicio', 'maquina_id']))) {
            return;
        }
        $depois = ApontamentoEstado::with('legenda')->where('maquina_id', (int) $ap->maquina_id)
            ->when($ap->exists, fn ($q) => $q->whereKeyNot($ap->id))
            ->where(fn ($q) => $q->where('inicio', '>', $ap->inicio)
                ->when($ap->exists, fn ($e) => $e->orWhere(fn ($i) => $i->where('inicio', $ap->inicio)->where('id', '>', $ap->id))))
            ->orderBy('inicio')->orderBy('id')->first();
        if ($depois) {
            throw ValidationException::withMessages(['hora_fim' => sprintf(
                'Só o último apontamento da máquina pode ficar em aberto: já existe o apontamento %s às %s, depois deste. Informe o horário final.',
                $depois->legenda?->codigo ?? '', AppServiceProvider::dataHora($depois->inicio))]);
        }
    }

    /** Conferência periódica (agendador): todas as máquinas com mais de um apontamento em aberto. */
    public static function conferirAbertos(): int
    {
        $total = 0;
        $maquinas = ApontamentoEstado::whereNull('fim')->groupBy('maquina_id')->havingRaw('COUNT(*) > 1')->pluck('maquina_id');
        foreach ($maquinas as $maquinaId) {
            $total += self::fecharAbertosAnteriores((int) $maquinaId);
        }

        return $total;
    }
}
