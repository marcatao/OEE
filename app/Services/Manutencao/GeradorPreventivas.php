<?php

namespace App\Services\Manutencao;

use App\Models\Manutencao\ChecklistOs;
use App\Models\Manutencao\OrdemServico;
use App\Models\Manutencao\PlanoPreventiva;
use App\Models\Manutencao\SolicitacaoServico;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * PAMP: gera sozinho as OS preventivas dos planos ativos. Cada plano tem a próxima data (proxima_em); quando ela está a
 * "antecedencia_dias" de hoje (ou já passou), cria a OS (uma por plano e data: índice único) com o checklist do plano e
 * avança a próxima data pela periodicidade (semanal, quinzenal ou os meses marcados no ano).
 */
final class GeradorPreventivas
{
    public function __construct(private FluxoOrdemServico $fluxo) {}

    /** @return list<OrdemServico> OS criadas */
    public function gerar(?CarbonImmutable $hoje = null): array
    {
        $hoje ??= CarbonImmutable::now(HorasManutencao::fuso())->startOfDay();
        $criadas = [];
        $planos = PlanoPreventiva::withoutGlobalScopes()->where('ativo', true)->with(['itens' => fn ($q) => $q->where('ativo', true)->orderBy('ordem')])->get();
        foreach ($planos as $plano) {
            if ($plano->proxima_em === null) {
                $plano->proxima_em = self::proximaData($plano, $hoje->subDay());
                $plano->save();
            }
            // Limite de voltas: um plano esquecido há anos não gera centenas de OS de uma vez (só as do último ano).
            for ($voltas = 0; $voltas < 60 && $plano->proxima_em !== null; $voltas++) {
                $data = CarbonImmutable::parse($plano->proxima_em->format('Y-m-d'), HorasManutencao::fuso());
                if ($data->subDays((int) $plano->antecedencia_dias) > $hoje) {
                    break;
                }
                if ($data >= $hoje->subYear()) {
                    $os = $this->criarOs($plano, $data);
                    if ($os) {
                        $criadas[] = $os;
                    }
                }
                $plano->proxima_em = self::proximaData($plano, $data);
                $plano->save();
            }
        }

        return $criadas;
    }

    private function criarOs(PlanoPreventiva $plano, CarbonImmutable $data): ?OrdemServico
    {
        $existe = OrdemServico::where('plano_preventiva_id', $plano->id)->whereDate('prevista_para', $data->format('Y-m-d'))->exists();
        if ($existe) {
            return null;
        }

        return DB::transaction(function () use ($plano, $data) {
            $maquina = $plano->maquina()->withoutGlobalScopes()->first();
            $os = OrdemServico::create([
                'unidade' => $plano->unidade === 'ambos' ? ($maquina && $maquina->unidade !== 'ambos' ? $maquina->unidade : 'manutencao') : $plano->unidade,
                'plano_preventiva_id' => $plano->id,
                'prevista_para' => $data->format('Y-m-d'),
                'tipo' => 'preventiva',
                'maquina_id' => $plano->maquina_id,
                'area_executante_id' => $plano->area_executante_id,
                'centro_custo' => $maquina?->centro_custo,
                'prioridade' => SolicitacaoServico::prioridadeAtual($plano->prioridade),
                'maquina_parada' => false,
                'titulo' => mb_substr("Preventiva {$plano->codigo}: {$plano->descricao}", 0, 120),
                'descricao' => "Preventiva {$plano->codigo}: {$plano->descricao}",
                'responsavel_id' => $plano->responsavel_id,
                'status' => 'aberta',
            ]);
            if ($plano->responsavel_id) {
                $os->incluirMantenedor((int) $plano->responsavel_id);
            }
            foreach ($plano->itens as $item) {
                ChecklistOs::create(['ordem_servico_id' => $os->id, 'plano_item_id' => $item->id, 'ordem' => $item->ordem, 'descricao' => $item->descricao]);
            }
            $this->fluxo->registrar($os, null, 'gerada_preventiva', null, 'aberta', "Gerada pela PAMP para {$data->format('d/m/Y')}", null);

            return $os;
        });
    }

    /** Próxima data depois de $depoisDe, pela periodicidade do plano. */
    public static function proximaData(PlanoPreventiva $plano, CarbonImmutable $depoisDe): ?CarbonImmutable
    {
        $base = $depoisDe->startOfDay();

        if ($plano->periodicidade === 'semanal' || $plano->periodicidade === 'quinzenal') {
            $passo = $plano->periodicidade === 'semanal' ? 7 : 14;
            // A partir da última data programada (se houver) para manter o mesmo dia da semana.
            $atual = $plano->proxima_em ? CarbonImmutable::parse($plano->proxima_em->format('Y-m-d'), HorasManutencao::fuso()) : $base;
            while ($atual <= $base) {
                $atual = $atual->addDays($passo);
            }

            return $atual;
        }

        $meses = str_pad((string) $plano->meses, 12, '0');
        if (! str_contains($meses, '1')) {
            return null;
        }
        $dia = max(1, min(28, (int) ($plano->dia_mes ?: 1)));
        $mes = $base->startOfMonth();
        for ($i = 0; $i < 25; $i++, $mes = $mes->addMonth()) {
            if ($meses[$mes->month - 1] === '1') {
                $data = $mes->setDay($dia);
                if ($data > $base) {
                    return $data;
                }
            }
        }

        return null;
    }
}
