<?php

namespace App\Services\Protheus;

/**
 * Produtos do Protheus: cópia da SB1 (banco Compras) em produtos_protheus. Chave: B1_FILIAL + B1_COD.
 */
class ImportadorProdutos extends ImportadorEspelho
{
    public const CAMPOS = ['B1_FILIAL', 'B1_COD', 'B1_DESC', 'B1_TIPO', 'B1_UM', 'B1_GRUPO', 'B1_MSBLQL', 'D_E_L_E_T_', 'R_E_C_N_O_'];

    public function campos(): array
    {
        return self::CAMPOS;
    }

    protected function tabela(): string
    {
        return 'produtos_protheus';
    }

    protected function colunasChave(): array
    {
        return ['b1_filial', 'b1_cod'];
    }

    protected function linhaDestino(array $l): ?array
    {
        $codigo = self::texto($l, 'B1_COD', 30);
        if ($codigo === '') {
            return null;
        }

        return [
            'b1_filial' => self::texto($l, 'B1_FILIAL', 8),
            'b1_cod' => $codigo,
            'b1_desc' => self::texto($l, 'B1_DESC', 120),
            'b1_tipo' => self::texto($l, 'B1_TIPO', 2),
            'b1_um' => self::texto($l, 'B1_UM', 2),
            'b1_grupo' => self::texto($l, 'B1_GRUPO', 10),
            'b1_msblql' => ($l['B1_MSBLQL'] ?? '') === '1' ? '1' : '2',
            'd_e_l_e_t_' => self::excluida($l),
            'r_e_c_n_o_' => isset($l['R_E_C_N_O_']) ? (int) $l['R_E_C_N_O_'] : null,
        ];
    }
}
