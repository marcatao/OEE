<?php

namespace App\Services\Protheus;

use App\Services\TiposMovimento;
use Illuminate\Database\Query\Builder;

/**
 * Empenhos das OPs do Protheus: cópia da SD4 em empenhos_op (materiais reservados para cada OP pela estrutura).
 * Chave: R_E_C_N_O_. Relacionamento: D4_OP = C2_NUM + C2_ITEM + C2_SEQUEN (+ C2_ITEMGRD) da SC2; D4_COD = componente
 * (SB1) e D4_TRT = G1_TRT da estrutura (SG1). D4_QUANT é o SALDO ainda empenhado (cai conforme as baixas na SD3);
 * D4_QTDEORI, o empenho original. Lida inteira a cada sincronização (o saldo muda), só as linhas com OP e, se
 * configurado, a partir de PROTHEUS_SC2_DESDE.
 */
class ImportadorEmpenhos extends ImportadorEspelho
{
    public const CAMPOS = ['D4_FILIAL', 'D4_COD', 'D4_OP', 'D4_LOCAL', 'D4_DATA', 'D4_QTDEORI', 'D4_QUANT', 'D4_TRT',
        'D4_LOTECTL', 'D4_OPORIG', 'D_E_L_E_T_', 'R_E_C_N_O_'];

    public function campos(): array
    {
        return self::CAMPOS;
    }

    public function filtrar(Builder $consulta): Builder
    {
        $consulta->where('D4_OP', '<>', '');
        $desde = preg_replace('/\D/', '', (string) config('protheus.sc2_desde'));

        return strlen($desde) === 8 ? $consulta->where('D4_DATA', '>=', $desde) : $consulta;
    }

    protected function tabela(): string
    {
        return 'empenhos_op';
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
        $op = trim((string) ($l['D4_OP'] ?? ''));
        if ($op === '' || ! isset($l['R_E_C_N_O_'])) {
            return null;
        }

        return [
            'd4_filial' => self::texto($l, 'D4_FILIAL', 8),
            'd4_cod' => self::texto($l, 'D4_COD', 30),
            'd4_op' => mb_substr($op, 0, 20),
            'op_numero' => TiposMovimento::numeroDaOp($op),
            'd4_local' => self::texto($l, 'D4_LOCAL', 4),
            'd4_data' => substr(preg_replace('/\D/', '', (string) ($l['D4_DATA'] ?? '')), 0, 8),
            'd4_qtdeori' => (float) ($l['D4_QTDEORI'] ?? 0),
            'd4_quant' => (float) ($l['D4_QUANT'] ?? 0),
            'd4_trt' => self::texto($l, 'D4_TRT', 3),
            'd4_lotectl' => self::texto($l, 'D4_LOTECTL', 20),
            'd4_oporig' => self::texto($l, 'D4_OPORIG', 20),
            'd_e_l_e_t_' => self::excluida($l),
            'r_e_c_n_o_' => (int) $l['R_E_C_N_O_'],
        ];
    }
}
