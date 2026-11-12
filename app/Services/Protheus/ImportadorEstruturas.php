<?php

namespace App\Services\Protheus;

/**
 * Estruturas de produto do Protheus: cópia da SG1 (lista de materiais) em estruturas_produto.
 * Chave: G1_FILIAL + G1_COD + G1_COMP + G1_TRT.
 */
class ImportadorEstruturas extends ImportadorEspelho
{
    public const CAMPOS = ['G1_FILIAL', 'G1_COD', 'G1_COMP', 'G1_TRT', 'G1_QUANT', 'G1_PERDA', 'G1_INI', 'G1_FIM', 'G1_FIXVAR',
        'G1_REVINI', 'G1_REVFIM', 'D_E_L_E_T_', 'R_E_C_N_O_'];

    public function campos(): array
    {
        return self::CAMPOS;
    }

    protected function tabela(): string
    {
        return 'estruturas_produto';
    }

    protected function colunasChave(): array
    {
        return ['g1_filial', 'g1_cod', 'g1_comp', 'g1_trt'];
    }

    protected function colunaBusca(): string
    {
        return 'g1_cod';
    }

    protected function linhaDestino(array $l): ?array
    {
        $produto = self::texto($l, 'G1_COD', 30);
        if ($produto === '') {
            return null;
        }
        $data = fn ($c) => substr(preg_replace('/\D/', '', (string) ($l[$c] ?? '')), 0, 8);

        return [
            'g1_filial' => self::texto($l, 'G1_FILIAL', 8),
            'g1_cod' => $produto,
            'g1_comp' => self::texto($l, 'G1_COMP', 30),
            'g1_trt' => self::texto($l, 'G1_TRT', 3),
            'g1_quant' => (float) ($l['G1_QUANT'] ?? 0),
            'g1_perda' => (float) ($l['G1_PERDA'] ?? 0),
            'g1_ini' => $data('G1_INI'),
            'g1_fim' => $data('G1_FIM'),
            'g1_fixvar' => self::texto($l, 'G1_FIXVAR', 1),
            'g1_revini' => self::texto($l, 'G1_REVINI', 3),
            'g1_revfim' => self::texto($l, 'G1_REVFIM', 3),
            'd_e_l_e_t_' => self::excluida($l),
            'r_e_c_n_o_' => isset($l['R_E_C_N_O_']) ? (int) $l['R_E_C_N_O_'] : null,
        ];
    }
}
