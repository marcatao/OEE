<?php

namespace App\Services\Protheus;

use App\Services\TiposMovimento;
use Illuminate\Database\Query\Builder;

/**
 * Apontamentos OP (movimentos de produção) do Protheus: cópia da SD3 (movimentos internos) em movimentos_producao, só das linhas
 * com o campo da OP preenchido (config protheus.sd3_campo_op, padrão D3_ORDEM). Chave: R_E_C_N_O_.
 * As linhas excluídas ou estornadas também são lidas, para marcar no portal o que já tinha vindo; as telas mostram só
 * as válidas (não excluídas e não estornadas). O "tipo" (produção, consumo, devolução) sai do D3_CF.
 */
class ImportadorMovimentosProducao extends ImportadorEspelho
{
    use DiagnosticoCampoOp;
    use ValorizaPorCustoMedio;

    private ?CustoMedioEstoque $custoMedioEstoque = null;

    public const CAMPOS = ['D3_FILIAL', 'D3_TM', 'D3_CF', 'D3_COD', 'D3_UM', 'D3_QUANT', 'D3_LOCAL', 'D3_DOC', 'D3_EMISSAO',
        'D3_NUMSEQ', 'D3_CUSTO1', 'D3_ESTORNO', 'D_E_L_E_T_', 'R_E_C_N_O_'];

    public function __construct(private TiposMovimento $tipos) {}

    public static function campoOp(): string
    {
        return strtoupper(preg_replace('/\W/', '', (string) config('protheus.sd3_campo_op', 'D3_ORDEM')) ?: 'D3_ORDEM');
    }

    public function campos(): array
    {
        return array_values(array_unique([...self::CAMPOS, self::campoOp()]));
    }

    /** Só linhas com OP e, se configurado, emitidas a partir de PROTHEUS_SC2_DESDE (AAAAMMDD). */
    public function filtrar(Builder $consulta): Builder
    {
        $consulta->where(self::campoOp(), '<>', '');
        $desde = preg_replace('/\D/', '', (string) config('protheus.sc2_desde'));

        return strlen($desde) === 8 ? $consulta->where('D3_EMISSAO', '>=', $desde) : $consulta;
    }

    /** Movimentos só crescem: lê os novos e relê os últimos dias (estornos e exclusões). Ver primeiroRecno(). */
    protected function campoDataIncremental(): ?string
    {
        return 'D3_EMISSAO';
    }

    protected function colunasDoItem(): array
    {
        return ['d3_filial', 'd3_cod', 'd3_local'];
    }

    protected function tabela(): string
    {
        return 'movimentos_producao';
    }

    protected function colunasChave(): array
    {
        return ['r_e_c_n_o_'];
    }

    protected function colunaBusca(): string
    {
        return 'r_e_c_n_o_';
    }

    protected function linhaDestino(array $l): ?array
    {
        $op = trim((string) ($l[self::campoOp()] ?? ''));
        if ($op === '' || ! isset($l['R_E_C_N_O_'])) {
            return null;
        }
        $cf = self::texto($l, 'D3_CF', 3);

        return [
            'd3_filial' => self::texto($l, 'D3_FILIAL', 8),
            'd3_tm' => self::texto($l, 'D3_TM', 3),
            'd3_cf' => $cf,
            'd3_cod' => self::texto($l, 'D3_COD', 30),
            'd3_um' => self::texto($l, 'D3_UM', 2),
            'd3_quant' => (float) ($l['D3_QUANT'] ?? 0),
            'd3_local' => self::texto($l, 'D3_LOCAL', 4),
            'd3_doc' => self::texto($l, 'D3_DOC', 20),
            'd3_emissao' => substr(preg_replace('/\D/', '', (string) ($l['D3_EMISSAO'] ?? '')), 0, 8),
            'd3_numseq' => self::texto($l, 'D3_NUMSEQ', 10),
            'd3_custo1' => (float) ($l['D3_CUSTO1'] ?? 0),
            'd3_estorno' => strtoupper(self::texto($l, 'D3_ESTORNO', 1)),
            'op' => mb_substr($op, 0, 20),
            'op_numero' => TiposMovimento::numeroDaOp($op),
            'tipo' => $this->tipos->tipoDoCf($cf),
            'd_e_l_e_t_' => self::excluida($l),
            'r_e_c_n_o_' => (int) $l['R_E_C_N_O_'],
        ];
    }
}
