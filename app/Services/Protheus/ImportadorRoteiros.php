<?php

namespace App\Services\Protheus;

/**
 * Roteiros de operação do Protheus: cópia da SG2 em roteiros_operacao.
 * Chave: G2_FILIAL + G2_PRODUTO + G2_CODIGO + G2_OPERAC. O centro de trabalho (G2_CTRAB) é o código da máquina do
 * portal (SHB) e a ferramenta (G2_FERRAM) é o código da SH4.
 */
class ImportadorRoteiros extends ImportadorEspelho
{
    public const CAMPOS = ['G2_FILIAL', 'G2_PRODUTO', 'G2_CODIGO', 'G2_OPERAC', 'G2_DESCRI', 'G2_RECURSO', 'G2_CTRAB', 'G2_FERRAM',
        'G2_TPOPER', 'G2_SETUP', 'G2_TEMPAD', 'G2_LOTEPAD', 'G2_MAOOBRA', 'D_E_L_E_T_', 'R_E_C_N_O_'];

    public function campos(): array
    {
        return self::CAMPOS;
    }

    protected function tabela(): string
    {
        return 'roteiros_operacao';
    }

    protected function colunasChave(): array
    {
        return ['g2_filial', 'g2_produto', 'g2_codigo', 'g2_operac'];
    }

    protected function colunaBusca(): string
    {
        return 'g2_produto';
    }

    protected function linhaDestino(array $l): ?array
    {
        $produto = self::texto($l, 'G2_PRODUTO', 30);
        if ($produto === '') {
            return null;
        }

        return [
            'g2_filial' => self::texto($l, 'G2_FILIAL', 8),
            'g2_produto' => $produto,
            'g2_codigo' => self::texto($l, 'G2_CODIGO', 4),
            'g2_operac' => self::texto($l, 'G2_OPERAC', 4),
            'g2_descri' => self::texto($l, 'G2_DESCRI', 120),
            'g2_recurso' => self::texto($l, 'G2_RECURSO', 10),
            'g2_ctrab' => self::texto($l, 'G2_CTRAB', 10),
            'g2_ferram' => self::texto($l, 'G2_FERRAM', 10),
            'g2_tpoper' => self::texto($l, 'G2_TPOPER', 1),
            'g2_setup' => (float) ($l['G2_SETUP'] ?? 0),
            'g2_tempad' => (float) ($l['G2_TEMPAD'] ?? 0),
            'g2_lotepad' => (float) ($l['G2_LOTEPAD'] ?? 0),
            'g2_maoobra' => (float) ($l['G2_MAOOBRA'] ?? 0),
            'd_e_l_e_t_' => self::excluida($l),
            'r_e_c_n_o_' => isset($l['R_E_C_N_O_']) ? (int) $l['R_E_C_N_O_'] : null,
        ];
    }
}
