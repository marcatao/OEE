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
 * - solicitante = quem apontou (o usuário logado no portal ou no aplicativo: quem criou o apontamento ou trocou a
 *   legenda); apontamento do IoT (sem usuário): o usuário técnico "IoT (automático)" e, em origem_iot, o IoT e o canal;
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

        // Quem apontou: quem criou o apontamento ou quem trocou a legenda (portal ou aplicativo). Sem usuário = IoT.
        $quemId = $ap->wasRecentlyCreated ? $ap->criado_por_id : ($ap->atualizado_por_id ?? $ap->criado_por_id);
        $quem = $quemId ? Usuario::find($quemId) : null;
        $porIot = $quem === null || $ap->automaticoIot();
        $solicitante = $quem ?? self::usuarioIot();
        $origem = $porIot ? $this->origem($ap, $maquina) : 'apontado por '.$solicitante->nome;
        $quando = $ap->inicio ? AppServiceProvider::dataHora($ap->inicio) : '';

        return DB::transaction(function () use ($ap, $legenda, $area, $maquina, $solicitante, $porIot, $origem, $quando) {
            $ss = SolicitacaoServico::create([
                'unidade' => $legenda->abre_ss_unidade ?: OrdemServico::unidadePara($area, $maquina),
                'solicitante_id' => $solicitante->id,
                'titulo' => mb_substr("OS automática — {$legenda->codigo} {$legenda->descricao} — {$maquina->codigo}", 0, 120),
                'tipo' => 'corretiva',
                'maquina_id' => $maquina->id,
                'area_executante_id' => $area->id,
                'centro_custo' => trim((string) $maquina->centro_custo) ?: null,
                'centro_custo_solicitante' => mb_substr($porIot ? $origem : ((string) ($solicitante->local_escritorio ?: $solicitante->departamento) ?: ''), 0, 120) ?: null,
                'prioridade' => $legenda->tipo === 'parada' ? 'alta' : 'baixa',
                'maquina_parada' => in_array($legenda->tipo, ['parada', 'planejada'], true),
                'descricao' => mb_substr("Solicitação aberta automaticamente: a legenda {$legenda->codigo} — {$legenda->descricao} foi apontada "
                    ."na máquina {$maquina->codigo} — {$maquina->nome}".($quando !== '' ? " em {$quando}" : '').". Origem: {$origem}.", 0, 2000),
                'status' => 'pendente',
                'apontamento_estado_id' => $ap->id,
                'ordem_producao_id' => $ap->ordem_producao_id,
                'automatica' => true,
                'origem_iot' => $porIot ? mb_substr($origem, 0, 120) : null,
            ]);
            $this->fluxo->registrar(null, $ss, 'aberta', null, 'pendente', "SS automática pela legenda {$legenda->codigo} ({$origem}).", $solicitante);

            return $ss;
        });
    }

    /** Solicitante é o usuário técnico do IoT (então quem avalia a OS é o administrador da unidade)? */
    public static function solicitanteEhIot(?int $usuarioId): bool
    {
        return $usuarioId !== null && Usuario::whereKey($usuarioId)->where('entra_oid', self::ENTRA_OID_IOT)->exists();
    }

    /** Usuário técnico solicitante das SS abertas pelo IoT (sem usuário logado). Inativo: não entra no portal. */
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
