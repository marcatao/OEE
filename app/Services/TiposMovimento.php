<?php

namespace App\Services;

/**
 * Legendas dos movimentos das OPs (config/protheus.php): D3_CF da SD3 (PR = produção, RE = baixa/consumo,
 * DE = devolução) e "NF" para os itens de nota fiscal (SD2).
 */
class TiposMovimento
{
    /** Tamanho do número da OP no Protheus: C2_NUM (6) + C2_ITEM (2) + C2_SEQUEN (3). */
    public const TAMANHO_NUMERO_OP = 11;

    /** "50985901001   " (D3_ORDEM, D2_OP: número + item + sequência + item de grade) → "50985901001". */
    public static function numeroDaOp(string $op): string
    {
        return mb_substr(trim($op), 0, self::TAMANHO_NUMERO_OP);
    }

    /** producao | consumo | devolucao | outro, pelo prefixo do D3_CF. */
    public function tipoDoCf(string $cf): string
    {
        $cf = strtoupper(trim($cf));
        if ($cf === 'NF') {
            return 'nota_fiscal';
        }

        return config('protheus.prefixos_d3_cf', [])[substr($cf, 0, 2)] ?? 'outro';
    }

    /** Descrição do D3_CF (ex.: RE1 → "Baixa automática (empenho)"); sem legenda própria, a do tipo. */
    public function descricao(string $cf): string
    {
        $cf = strtoupper(trim($cf));

        return config('protheus.legendas_d3_cf', [])[$cf] ?? $this->rotulo($this->tipoDoCf($cf));
    }

    public function rotulo(string $tipo): string
    {
        return $this->tipos()[$tipo] ?? $tipo;
    }

    /** @return array<string, string> tipo => rótulo */
    public function tipos(): array
    {
        return config('protheus.tipos_movimento', []);
    }

    /** @return array<string, string> D3_CF => descrição */
    public function legendas(): array
    {
        return config('protheus.legendas_d3_cf', []);
    }
}
