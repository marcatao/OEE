<?php

namespace App\Services\Protheus;

/**
 * Ferramentas do Protheus: SH4 em "ferramentas" (tabela MISTA). Chave: H4_FILIAL + H4_CODIGO.
 * Os campos h4_* vêm do Protheus a cada sincronização. São do portal, e a sincronização nunca mexe neles:
 * - h4_qtdcavativas (cavidades ativas): nasce igual à quantidade de cavidades (H4_QTDCAV) e depois só muda no portal;
 * - unidade (manutenção, tecnologia, ferramentaria ou ambos = todas);
 * - disponivel (a ferramenta/molde está disponível para uso; nasce disponível).
 * O campo de cavidades no Protheus é configurável (config protheus.sh4_campo_cavidades, padrão H4_QTDCAV).
 */
class ImportadorFerramentas extends ImportadorEspelho
{
    public const CAMPOS = ['H4_FILIAL', 'H4_CODIGO', 'H4_DESCRI', 'H4_QUANT', 'H4_VIDAUTI', 'H4_TIPOVID', 'D_E_L_E_T_', 'R_E_C_N_O_'];

    public static function campoCavidades(): string
    {
        return strtoupper(preg_replace('/\W/', '', (string) config('protheus.sh4_campo_cavidades', 'H4_QTDCAV')) ?: 'H4_QTDCAV');
    }

    public function campos(): array
    {
        return array_values(array_unique([...self::CAMPOS, self::campoCavidades()]));
    }

    protected function tabela(): string
    {
        return 'ferramentas';
    }

    protected function colunasChave(): array
    {
        return ['h4_filial', 'h4_codigo'];
    }

    protected function colunaBusca(): string
    {
        return 'h4_codigo';
    }

    protected function linhaDestino(array $l): ?array
    {
        $codigo = self::texto($l, 'H4_CODIGO', 10);
        if ($codigo === '') {
            return null;
        }

        // Só campos do Protheus: h4_qtdcavativas e unidade ficam de fora, para a sincronização não sobrepor.
        return [
            'h4_filial' => self::texto($l, 'H4_FILIAL', 8),
            'h4_codigo' => $codigo,
            'h4_descri' => self::texto($l, 'H4_DESCRI', 120),
            'h4_quant' => (float) ($l['H4_QUANT'] ?? 0),
            'h4_vidauti' => (float) ($l['H4_VIDAUTI'] ?? 0),
            'h4_tipovid' => self::texto($l, 'H4_TIPOVID', 1),
            'h4_qtdcav' => max(0, (int) round((float) ($l[self::campoCavidades()] ?? 0))),
            'd_e_l_e_t_' => self::excluida($l),
            'r_e_c_n_o_' => isset($l['R_E_C_N_O_']) ? (int) $l['R_E_C_N_O_'] : null,
        ];
    }

    /** Ferramenta nova: cavidades ativas = cavidades do Protheus; unidade padrão (manutenção). */
    protected function completarNova(array $linha): array
    {
        return $linha + ['h4_qtdcavativas' => $linha['h4_qtdcav'], 'unidade' => 'manutencao', 'disponivel' => true, 'criado_em' => now()];
    }
}
