<?php

namespace App\Services\Manutencao;

use App\Models\ApontamentoEstado;
use App\Models\DispositivoIot;
use App\Models\Legenda;
use App\Models\Manutencao\OrdemServico;
use App\Models\Manutencao\SolicitacaoServico;
use App\Models\Maquina;
use App\Models\Usuario;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\DB;

/**
 * SS automática: quando um apontamento real recebe uma legenda que "abre SS" (legendas.abre_ss_unidade +
 * abre_ss_area_executante_id), abre uma solicitação para essa área e máquina — no portal, no aplicativo ou pelo IoT.
 * - título "OS automática — <legenda> — <máquina>"; centro de custo = o do cadastro da máquina;
 * - solicitante = o usuário técnico "IoT (automático)" (sem acesso ao portal) e, no texto, o IoT e o canal que
 *   apontaram (origem_iot; sem origem no apontamento, o 1º canal IoT da máquina);
 * - não repete: uma por apontamento, e nenhuma nova enquanto houver SS automática pendente ou OS automática aberta da
 *   mesma máquina e área.
 */
final class SsAutomatica
{
    public const ENTRA_OID_IOT = 'sistema:iot';

    public function __construct(private readonly FluxoOrdemServico $fluxo) {}

    public function aoApontar(ApontamentoEstado $ap): ?SolicitacaoServico
    {
        $legenda = Legenda::withoutGlobalScopes()->find($ap->legenda_id);
        $area = $legenda?->abreSsArea;
        $maquina = Maquina::withoutGlobalScopes()->find($ap->maquina_id);
        if (! $legenda || ! $legenda->abreSs() || ! $area || ! $maquina) {
            return null;
        }
        if (SolicitacaoServico::where('apontamento_estado_id', $ap->id)->exists() || $this->jaEmAberto($maquina->id, $area->id)) {
            return null;
        }

        $iot = self::usuarioIot();
        $origem = $this->origem($ap, $maquina);
        $quando = $ap->inicio ? AppServiceProvider::dataHora($ap->inicio) : '';

        return DB::transaction(function () use ($ap, $legenda, $area, $maquina, $iot, $origem, $quando) {
            $ss = SolicitacaoServico::create([
                'unidade' => $legenda->abre_ss_unidade ?: OrdemServico::unidadePara($area, $maquina),
                'solicitante_id' => $iot->id,
                'titulo' => mb_substr("OS automática — {$legenda->codigo} {$legenda->descricao} — {$maquina->codigo}", 0, 120),
                'tipo' => 'corretiva',
                'maquina_id' => $maquina->id,
                'area_executante_id' => $area->id,
                'centro_custo' => trim((string) $maquina->centro_custo) ?: null,
                'centro_custo_solicitante' => mb_substr($origem, 0, 120),
                'prioridade' => $legenda->tipo === 'parada' ? 'alta' : 'baixa',
                'maquina_parada' => in_array($legenda->tipo, ['parada', 'planejada'], true),
                'descricao' => mb_substr("Solicitação aberta automaticamente: a legenda {$legenda->codigo} — {$legenda->descricao} foi apontada "
                    ."na máquina {$maquina->codigo} — {$maquina->nome}".($quando !== '' ? " em {$quando}" : '').". Origem: {$origem}.", 0, 2000),
                'status' => 'pendente',
                'apontamento_estado_id' => $ap->id,
                'ordem_producao_id' => $ap->ordem_producao_id,
                'automatica' => true,
                'origem_iot' => mb_substr($origem, 0, 120),
            ]);
            $this->fluxo->registrar(null, $ss, 'aberta', null, 'pendente', "SS automática pela legenda {$legenda->codigo} ({$origem}).", $iot);

            return $ss;
        });
    }

    /** Usuário técnico que aparece como solicitante das SS automáticas (inativo: não entra no portal). */
    public static function usuarioIot(): Usuario
    {
        return Usuario::firstOrCreate(['entra_oid' => self::ENTRA_OID_IOT], ['nome' => 'IoT (automático)', 'ativo' => false]);
    }

    private function jaEmAberto(int $maquinaId, int $areaId): bool
    {
        return SolicitacaoServico::where('automatica', true)->where('status', 'pendente')
            ->where('maquina_id', $maquinaId)->where('area_executante_id', $areaId)->exists()
            || OrdemServico::where('automatica', true)->whereIn('status', OrdemServico::STATUS_ABERTOS)
                ->where('maquina_id', $maquinaId)->where('area_executante_id', $areaId)->exists();
    }

    /** "IoT GW01 / chd1_value": do apontamento (Integrador MQTT) ou, sem isso, o 1º canal IoT da máquina. */
    private function origem(ApontamentoEstado $ap, Maquina $maquina): string
    {
        $doApontamento = $ap->origemIot();
        if ($doApontamento !== '') {
            return 'IoT '.$doApontamento;
        }
        foreach (Maquina::CANAIS_IOT as $n) {
            if ($id = $maquina->{"iot{$n}_dispositivo_id"}) {
                $codigo = DispositivoIot::withoutGlobalScopes()->whereKey($id)->value('codigo') ?? "dispositivo #{$id}";

                return 'IoT '.implode(' / ', array_filter([$codigo, $maquina->{"iot{$n}_canal"}]));
            }
        }

        return 'IoT';
    }
}
