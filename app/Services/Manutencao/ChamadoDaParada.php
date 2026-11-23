<?php

namespace App\Services\Manutencao;

use App\Models\ApontamentoEstado;
use App\Models\Manutencao\SolicitacaoServico;
use App\Models\Maquina;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * SS/OS aberta pelo apontamento de uma legenda que "abre SS" (SsAutomatica) para a máquina que está PARADA agora:
 * a da parada atual (aberta desde que a máquina parou). Aprovada, mostra a OS. Vai no lugar da meta na Timeline e no
 * aplicativo, para o operador acompanhar o desfecho. Se a parada não abriu SS porque já havia uma automática em aberto da
 * máquina e área, vale a SS vinculada ao apontamento (apontamentos_estado.solicitacao_vinculada_id). Máquina produzindo
 * (ou sem SS da parada) = null.
 */
final class ChamadoDaParada
{
    /** @return array{tipo: string, id: int, numero: string, status: string, rotulo: string}|null */
    public static function da(Maquina $maquina): ?array
    {
        return self::dasMaquinas(collect([$maquina]))[(int) $maquina->id] ?? null;
    }

    /**
     * O mesmo de da() para várias máquinas com poucas consultas (Timeline e lista do app atualizam a cada minuto).
     *
     * @param  iterable<Maquina>  $maquinas
     * @return array<int, array{tipo: string, id: int, numero: string, status: string, rotulo: string}> só as que têm chamado
     */
    public static function dasMaquinas(iterable $maquinas): array
    {
        $paradas = collect($maquinas)->filter(fn (Maquina $m) => $m->situacao === 'parada')->keyBy(fn ($m) => (int) $m->id);
        if ($paradas->isEmpty()) {
            return [];
        }
        // 1º) SS vinculada ao apontamento atual (já havia uma em aberto da máquina quando ela parou); 2º) a aberta na parada.
        $vinculadas = collect(ApontamentoEstado::ultimosPorMaquina($paradas->keys(), ['solicitacao_vinculada_id']))
            ->map(fn ($u) => $u['solicitacao_vinculada_id'] ? (int) $u['solicitacao_vinculada_id'] : null)->filter();
        $ssVinculadas = $vinculadas->isEmpty() ? collect()
            : SolicitacaoServico::with('ordemServico')->whereIn('id', $vinculadas->values()->all())->get()->keyBy(fn ($s) => (int) $s->id);

        $semVinculo = $paradas->reject(fn ($m, $id) => isset($ssVinculadas[$vinculadas[$id] ?? 0]));
        $automaticas = collect();
        if ($semVinculo->isNotEmpty()) {
            // Com "desde": numa consulta, a partir da parada mais antiga (cada máquina confere a sua abaixo).
            $comDesde = $semVinculo->filter(fn ($m) => $m->situacao_desde);
            if ($comDesde->isNotEmpty()) {
                $desde = $comDesde->map(fn ($m) => $m->situacao_desde)->min()->copy()->subMinute();
                $automaticas = SolicitacaoServico::with('ordemServico')->whereIn('maquina_id', $comDesde->keys()->all())
                    ->where('automatica', true)->where('criado_em', '>=', $desde)
                    ->orderByDesc('id')->get()->groupBy(fn ($s) => (int) $s->maquina_id);
            }
            // Sem "desde" (cadastro antigo): só a última automática da máquina.
            foreach ($semVinculo->reject(fn ($m) => $m->situacao_desde) as $id => $m) {
                $ultima = SolicitacaoServico::with('ordemServico')->where('maquina_id', $id)->where('automatica', true)->orderByDesc('id')->first();
                $automaticas[$id] = $ultima ? collect([$ultima]) : collect();
            }
        }

        // A SS tem de ser DESTA parada: aberta por um apontamento que começou nela (não basta ter sido criada depois —
        // ex.: alguém troca o motivo de uma parada antiga para uma legenda que abre SS e ela apareceria na parada atual).
        $iniciosApontamento = DB::table('apontamentos_estado')
            ->whereIntegerInRaw('id', $automaticas->flatten()->pluck('apontamento_estado_id')->filter()->map(fn ($v) => (int) $v)->unique()->values()->all())
            ->pluck('inicio', 'id')->mapWithKeys(fn ($inicio, $apId) => [(int) $apId => CarbonImmutable::parse($inicio)]);
        $daParada = function ($s, Maquina $maquina) use ($iniciosApontamento) {
            if (! $maquina->situacao_desde) {
                return true;
            }
            $limite = CarbonImmutable::instance($maquina->situacao_desde)->subMinute();
            $inicio = $s->apontamento_estado_id ? ($iniciosApontamento[(int) $s->apontamento_estado_id] ?? null) : null;

            return $inicio ? $inicio->gte($limite) : $s->criado_em->gte($limite);
        };

        $chamados = [];
        foreach ($paradas as $id => $maquina) {
            $ss = $ssVinculadas[$vinculadas[$id] ?? 0] ?? ($automaticas[$id] ?? collect())
                ->first(fn ($s) => $daParada($s, $maquina));
            if ($ss) {
                $chamados[$id] = self::resumo($ss);
            }
        }

        return $chamados;
    }

    /** @return array{tipo: string, id: int, numero: string, status: string, rotulo: string} */
    private static function resumo(SolicitacaoServico $ss): array
    {
        $os = $ss->ordemServico;

        return $os
            ? ['tipo' => 'os', 'id' => $os->id, 'numero' => $os->numeroFormatado(), 'status' => $os->status, 'rotulo' => $os->rotuloStatus()]
            : ['tipo' => 'ss', 'id' => $ss->id, 'numero' => $ss->numeroFormatado(), 'status' => $ss->status, 'rotulo' => $ss->rotuloStatus()];
    }
}
