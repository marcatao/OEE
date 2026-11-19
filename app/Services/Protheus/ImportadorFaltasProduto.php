<?php

namespace App\Services\Protheus;

use Carbon\CarbonImmutable;

/**
 * Falta de produtos: cópia da ZB5 do Protheus (tabela customizada) em faltas_produto. Chave: R_E_C_N_O_.
 * No SQL Server a sincronização é feita pela procedure dbo.sp_sincronizar_faltas_produto (MERGE, mesma lógica);
 * este importador é o caminho do PHP (testes em SQLite, ou PROTHEUS_VIA_ZB5=php).
 * Data + hora do Protheus (horário de Brasília) viram solicitado_em / atendido_em em UTC.
 */
class ImportadorFaltasProduto extends ImportadorEspelho
{
    public const CAMPOS = ['ZB5_FILIAL', 'ZB5_DOC', 'ZB5_PRODUT', 'ZB5_QTDSOL', 'ZB5_QTDATE', 'ZB5_PEDIDO', 'ZB5_VLPED',
        'ZB5_SOLICI', 'ZB5_DTSOLI', 'ZB5_HORINI', 'ZB5_DTATEN', 'ZB5_HORFIM', 'ZB5_ATENDI', 'ZB5_STATUS',
        'D_E_L_E_T_', 'R_E_C_N_O_'];

    public function campos(): array
    {
        return self::CAMPOS;
    }

    protected function tabela(): string
    {
        return 'faltas_produto';
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
        if (! isset($l['R_E_C_N_O_'])) {
            return null;
        }
        $dtsoli = self::texto($l, 'ZB5_DTSOLI', 8);
        $horini = self::texto($l, 'ZB5_HORINI', 8);
        $dtaten = self::texto($l, 'ZB5_DTATEN', 8);
        $horfim = self::texto($l, 'ZB5_HORFIM', 8);

        return [
            'zb5_filial' => self::texto($l, 'ZB5_FILIAL', 6),
            'zb5_doc' => self::texto($l, 'ZB5_DOC', 10),
            'zb5_produt' => self::texto($l, 'ZB5_PRODUT', 16),
            'zb5_qtdsol' => (float) ($l['ZB5_QTDSOL'] ?? 0),
            'zb5_qtdate' => (float) ($l['ZB5_QTDATE'] ?? 0),
            'zb5_pedido' => self::texto($l, 'ZB5_PEDIDO', 6),
            'zb5_vlped' => (float) ($l['ZB5_VLPED'] ?? 0),
            'zb5_solici' => self::texto($l, 'ZB5_SOLICI', 19),
            'zb5_dtsoli' => $dtsoli,
            'zb5_horini' => $horini,
            'zb5_dtaten' => $dtaten,
            'zb5_horfim' => $horfim,
            'zb5_atendi' => self::texto($l, 'ZB5_ATENDI', 19),
            'zb5_status' => strtoupper(self::texto($l, 'ZB5_STATUS', 1)),
            'solicitado_em' => self::utc($dtsoli, $horini),
            'atendido_em' => self::utc($dtaten, $horfim),
            'd_e_l_e_t_' => self::excluida($l),
            'r_e_c_n_o_' => (int) $l['R_E_C_N_O_'],
        ];
    }

    /** AAAAMMDD + HH:MM[:SS] no horário de Brasília → "Y-m-d H:i:s" em UTC. Data inválida = null; hora inválida = 00:00:00. */
    public static function utc(string $data, string $hora): ?string
    {
        if (! preg_match('/^[12]\d{3}[01]\d[0-3]\d$/', $data)) {
            return null;
        }
        $hora = match (true) {
            (bool) preg_match('/^[0-2]\d:[0-5]\d:[0-5]\d$/', $hora) => $hora,
            (bool) preg_match('/^[0-2]\d:[0-5]\d$/', $hora) => $hora.':00',
            default => '00:00:00',
        };
        $momento = CarbonImmutable::createFromFormat('!Ymd H:i:s', "{$data} {$hora}", 'America/Sao_Paulo');

        return $momento ? $momento->utc()->format('Y-m-d H:i:s') : null;
    }
}
