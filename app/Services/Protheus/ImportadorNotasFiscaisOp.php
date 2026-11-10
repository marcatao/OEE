<?php

namespace App\Services\Protheus;

use App\Services\TiposMovimento;
use Illuminate\Database\Query\Builder;

/**
 * Itens de nota fiscal de saída (SD2) que citam uma OP (config protheus.sd2_campo_op, padrão D2_OP): cópia em
 * notas_fiscais_op. Chave: R_E_C_N_O_. Aparecem nos apontamentos de produção e no kardex da OP com a legenda
 * "Nota fiscal", valorizados pelo D2_CUSTO1.
 */
class ImportadorNotasFiscaisOp extends ImportadorEspelho
{
    public const CAMPOS = ['D2_FILIAL', 'D2_DOC', 'D2_SERIE', 'D2_ITEM', 'D2_COD', 'D2_UM', 'D2_QUANT', 'D2_LOCAL', 'D2_EMISSAO',
        'D2_CUSTO1', 'D2_CF', 'D2_CLIENTE', 'D2_LOJA', 'D_E_L_E_T_', 'R_E_C_N_O_'];

    public static function campoOp(): string
    {
        return strtoupper(preg_replace('/\W/', '', (string) config('protheus.sd2_campo_op', 'D2_OP')) ?: 'D2_OP');
    }

    public function campos(): array
    {
        return array_values(array_unique([...self::CAMPOS, self::campoOp()]));
    }

    /** Só itens com OP e, se configurado, emitidos a partir de PROTHEUS_SC2_DESDE (AAAAMMDD). */
    public function filtrar(Builder $consulta): Builder
    {
        $consulta->where(self::campoOp(), '<>', '');
        $desde = preg_replace('/\D/', '', (string) config('protheus.sc2_desde'));

        return strlen($desde) === 8 ? $consulta->where('D2_EMISSAO', '>=', $desde) : $consulta;
    }

    protected function tabela(): string
    {
        return 'notas_fiscais_op';
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

        return [
            'd2_filial' => self::texto($l, 'D2_FILIAL', 8),
            'd2_doc' => self::texto($l, 'D2_DOC', 20),
            'd2_serie' => self::texto($l, 'D2_SERIE', 3),
            'd2_item' => self::texto($l, 'D2_ITEM', 4),
            'd2_cod' => self::texto($l, 'D2_COD', 30),
            'd2_um' => self::texto($l, 'D2_UM', 2),
            'd2_quant' => (float) ($l['D2_QUANT'] ?? 0),
            'd2_local' => self::texto($l, 'D2_LOCAL', 4),
            'd2_emissao' => substr(preg_replace('/\D/', '', (string) ($l['D2_EMISSAO'] ?? '')), 0, 8),
            'd2_custo1' => (float) ($l['D2_CUSTO1'] ?? 0),
            'd2_cf' => self::texto($l, 'D2_CF', 5),
            'd2_cliente' => self::texto($l, 'D2_CLIENTE', 10),
            'd2_loja' => self::texto($l, 'D2_LOJA', 4),
            'op' => mb_substr($op, 0, 20),
            'op_numero' => TiposMovimento::numeroDaOp($op),
            'd_e_l_e_t_' => self::excluida($l),
            'r_e_c_n_o_' => (int) $l['R_E_C_N_O_'],
        ];
    }
}
