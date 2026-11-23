<?php

namespace App\Services\Manutencao;

use App\Models\ApontamentoEstado;
use App\Models\Manutencao\OrdemServico;
use App\Models\Manutencao\SolicitacaoServico;
use App\Models\Usuario;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Exclusão de um apontamento real que abriu SS/OS automática (pedido do usuário em 23/11/2026). A SS guarda o
 * apontamento de origem (chave estrangeira), então antes ele não podia ser excluído — e o Integrador MQTT, ao corrigir
 * um trecho (ex.: 97 "Sem resposta" quando o aparelho voltou), deixava o apontamento antigo no meio da produção.
 *
 * Agora o chamado vai junto: SS ainda pendente é CANCELADA; OS aberta sem nenhum atendimento (sem horas) é CANCELADA;
 * SS/OS que já tiveram atendimento continuam como estão (só perdem o vínculo com o apontamento). Tudo fica no
 * histórico da SS/OS com o motivo. Na tela o usuário é avisado antes (`aviso`); o integrador pede pela API.
 */
class ExclusaoApontamento
{
    public function __construct(private readonly FluxoOrdemServico $fluxo) {}

    /**
     * SS e OS abertas por este apontamento (as OS também diretamente, sem SS).
     *
     * @return array{ss: Collection<int, SolicitacaoServico>, os: Collection<int, OrdemServico>}
     */
    public static function chamados(ApontamentoEstado $ap): array
    {
        $ss = SolicitacaoServico::withoutGlobalScopes()->where('apontamento_estado_id', $ap->id)->get();
        $os = OrdemServico::withoutGlobalScopes()->where(fn ($q) => $q->where('apontamento_estado_id', $ap->id)
            ->when($ss->isNotEmpty(), fn ($o) => $o->orWhereIntegerInRaw('solicitacao_id', $ss->map(fn ($s) => (int) $s->id)->all())))
            ->get();

        return ['ss' => $ss, 'os' => $os];
    }

    /** Texto do aviso antes de excluir (null = o apontamento não abriu chamado). */
    public static function aviso(ApontamentoEstado $ap): ?string
    {
        ['ss' => $ss, 'os' => $os] = self::chamados($ap);
        if ($ss->isEmpty() && $os->isEmpty()) {
            return null;
        }
        $partes = [];
        foreach ($ss as $s) {
            $partes[] = $s->numeroFormatado().' ('.mb_strtolower(SolicitacaoServico::STATUS[$s->status] ?? $s->status).')';
        }
        foreach ($os as $o) {
            $partes[] = $o->numeroFormatado().' ('.mb_strtolower(OrdemServico::STATUS[$o->status] ?? $o->status).')';
        }

        return 'Este apontamento abriu '.implode(' e ', $partes).'. Ao excluir, a SS pendente e a OS ainda sem atendimento '
            .'serão CANCELADAS; uma OS que já teve atendimento continua, só sem o vínculo com este apontamento.';
    }

    /**
     * Cancela (ou desvincula) os chamados e exclui o apontamento, numa transação.
     *
     * @return list<string> o que aconteceu com cada chamado (para a mensagem da tela ou do integrador)
     */
    public function excluir(ApontamentoEstado $ap, Usuario $usuario, string $motivo): array
    {
        return DB::transaction(function () use ($ap, $usuario, $motivo) {
            ['ss' => $ss, 'os' => $os] = self::chamados($ap);
            $feito = [];
            foreach ($os as $o) {
                $semAtendimento = $o->status === 'aberta' && (int) $o->total_minutos === 0 && ! $o->apontamentos()->exists();
                if ($semAtendimento) {
                    $o->forceFill(['status' => 'cancelada', 'cancelamento_motivo' => mb_substr($motivo, 0, 500)])->save();
                    $this->fluxo->registrar($o, null, 'cancelar', 'aberta', 'cancelada', $motivo, $usuario);
                    $feito[] = $o->numeroFormatado().' cancelada';
                } else {
                    $this->fluxo->registrar($o, null, 'desvinculada', $o->status, $o->status, $motivo, $usuario);
                    $feito[] = $o->numeroFormatado().' continua ('.mb_strtolower(OrdemServico::STATUS[$o->status] ?? $o->status).')';
                }
                if ((int) $o->apontamento_estado_id === (int) $ap->id) {
                    $o->forceFill(['apontamento_estado_id' => null])->save();
                }
            }
            foreach ($ss as $s) {
                if ($s->status === 'pendente') {
                    $s->forceFill(['status' => 'cancelada', 'apontamento_estado_id' => null])->save();
                    $this->fluxo->registrar(null, $s, 'cancelada', 'pendente', 'cancelada', $motivo, $usuario);
                    $feito[] = $s->numeroFormatado().' cancelada';
                } else {
                    $s->forceFill(['apontamento_estado_id' => null])->save();
                    $this->fluxo->registrar(null, $s, 'desvinculada', $s->status, $s->status, $motivo, $usuario);
                }
            }
            $ap->delete();

            return $feito;
        });
    }
}
