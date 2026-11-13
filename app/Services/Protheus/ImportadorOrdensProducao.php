<?php

namespace App\Services\Protheus;

use App\Models\OrdemProducao;
use App\Services\CustoOrdemProducao;
use App\Services\SituacaoMaquina;
use App\Services\StatusOrdemProducao;
use App\Support\Cronometro;
use Illuminate\Database\Query\Builder;

/**
 * Ordens de produção do Protheus: cópia da SC2 (banco Compras) em ordens_producao.
 * Chave: C2_FILIAL + C2_NUM + C2_ITEM + C2_SEQUEN. O "status" (aberta, andamento, fechada, parada) é calculado
 * pelo portal (StatusOrdemProducao) para todas as OPs de cada lote, a cada sincronização. O "custo_meta" (custo unitário
 * do fechamento mais recente do produto na SB9) também é preenchido a cada sincronização (CustoOrdemProducao).
 */
class ImportadorOrdensProducao extends ImportadorEspelho
{
    public const CAMPOS = ['C2_FILIAL', 'C2_NUM', 'C2_ITEM', 'C2_SEQUEN', 'C2_PRODUTO', 'C2_LOCAL', 'C2_CC', 'C2_UM',
        'C2_QUANT', 'C2_QUJE', 'C2_PERDA', 'C2_EMISSAO', 'C2_DATPRI', 'C2_DATPRF', 'C2_DATRF', 'C2_TPOP', 'C2_STATUS', 'C2_OBS',
        'C2_ROTEIRO', 'C2_REVISAO',
        'D_E_L_E_T_', 'R_E_C_N_O_'];

    public function __construct(private StatusOrdemProducao $status, private CustoOrdemProducao $custo) {}

    public function campos(): array
    {
        return self::CAMPOS;
    }

    /** Só OPs emitidas a partir de PROTHEUS_SC2_DESDE (AAAAMMDD), se configurado: evita trazer anos de histórico. */
    public function filtrar(Builder $consulta): Builder
    {
        $desde = preg_replace('/\D/', '', (string) config('protheus.sc2_desde'));

        return strlen($desde) === 8 ? $consulta->where('C2_EMISSAO', '>=', $desde) : $consulta;
    }

    protected function tabela(): string
    {
        return 'ordens_producao';
    }

    protected function colunasChave(): array
    {
        return ['c2_filial', 'c2_num', 'c2_item', 'c2_sequen'];
    }

    protected function colunaBusca(): string
    {
        return 'numero';
    }

    protected function linhaDestino(array $l): ?array
    {
        $num = self::texto($l, 'C2_NUM', 10);
        if ($num === '') {
            return null;
        }
        $item = self::texto($l, 'C2_ITEM', 4);
        $sequen = self::texto($l, 'C2_SEQUEN', 4);
        $data = fn ($c) => substr(preg_replace('/\D/', '', (string) ($l[$c] ?? '')), 0, 8);

        return [
            'c2_filial' => self::texto($l, 'C2_FILIAL', 8),
            'c2_num' => $num,
            'c2_item' => $item,
            'c2_sequen' => $sequen,
            'numero' => mb_substr($num.$item.$sequen, 0, 20),
            'c2_produto' => self::texto($l, 'C2_PRODUTO', 30),
            'c2_local' => self::texto($l, 'C2_LOCAL', 4),
            'c2_cc' => self::texto($l, 'C2_CC', 20),
            'c2_um' => self::texto($l, 'C2_UM', 2),
            'c2_quant' => (float) ($l['C2_QUANT'] ?? 0),
            'c2_quje' => (float) ($l['C2_QUJE'] ?? 0),
            'c2_perda' => (float) ($l['C2_PERDA'] ?? 0),
            'c2_emissao' => $data('C2_EMISSAO'),
            'c2_datpri' => $data('C2_DATPRI'),
            'c2_datprf' => $data('C2_DATPRF'),
            'c2_datrf' => $data('C2_DATRF'),
            'c2_tpop' => self::texto($l, 'C2_TPOP', 1),
            'c2_status' => self::texto($l, 'C2_STATUS', 1),
            'c2_obs' => self::texto($l, 'C2_OBS', 120),
            'c2_roteiro' => self::texto($l, 'C2_ROTEIRO', 2),
            'c2_revisao' => self::texto($l, 'C2_REVISAO', 3),
            'd_e_l_e_t_' => self::excluida($l),
            'r_e_c_n_o_' => isset($l['R_E_C_N_O_']) ? (int) $l['R_E_C_N_O_'] : null,
        ];
    }

    protected function completarNova(array $linha): array
    {
        // OP nova ainda não tem apontamentos no portal: o status sai só dos dados do Protheus.
        return $linha + ['status' => $this->status->calcular(new OrdemProducao($linha)), 'status_em' => now()];
    }

    /**
     * A cada lote, recalcula o status de TODAS as OPs do lote (não só das que mudaram no Protheus): assim o status
     * acompanha também os apontamentos e mudanças de regra. Também grava o custo meta (SB9). Só grava as que mudaram.
     */
    protected function recalculaLote(): bool
    {
        return true;
    }

    protected function depoisDoLote(array $idsDoLote, array $alterados): array
    {
        return array_values(array_unique([
            ...Cronometro::medir('status', fn () => $this->status->atualizarLote($idsDoLote)),
            ...Cronometro::medir('custo meta', fn () => $this->custo->atualizarMetas($idsDoLote)),
            // OP que fechou no Protheus sai da máquina (os apontamentos continuam com ela).
            ...Cronometro::medir('desvincular OPs fechadas', function () use ($idsDoLote) {
                app(SituacaoMaquina::class)->desvincularOpsFechadas($idsDoLote);

                return [];
            }),
        ]));
    }
}
