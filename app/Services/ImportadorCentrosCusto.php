<?php

namespace App\Services;

use App\Models\CentroCusto;
use App\Services\Protheus\ImportadorProtheus;
use Illuminate\Support\Facades\DB;

/**
 * Copia os centros de custo da CTT do Protheus para a tabela centros_custo do portal.
 * Chave: CTT_FILIAL + CTT_CUSTO. Registros excluídos no Protheus (D_E_L_E_T_ = '*') também vêm, marcados,
 * para o portal saber que saíram de uso. Os espaços à direita dos campos caractere são mantidos como no Protheus.
 */
class ImportadorCentrosCusto implements ImportadorProtheus
{
    public const CAMPOS = ['CTT_FILIAL', 'CTT_CUSTO', 'CTT_DESC01', 'CTT_CLASSE', 'CTT_BLOQ', 'CTT_CCSUP', 'CTT_DTEXIS', 'CTT_DTEXSF', 'D_E_L_E_T_', 'R_E_C_N_O_'];

    public function campos(): array
    {
        return self::CAMPOS;
    }

    /**
     * @param  iterable<array|object>  $linhas  linhas da CTT (nomes de coluna como no Protheus)
     * @return array{novos: int, atualizados: int, ignorados: int}
     */
    public function importar(iterable $linhas): array
    {
        $resumo = ['novos' => 0, 'atualizados' => 0, 'ignorados' => 0];

        DB::transaction(function () use ($linhas, &$resumo) {
            foreach ($linhas as $linha) {
                $l = array_change_key_case((array) $linha, CASE_UPPER);
                $codigo = rtrim((string) ($l['CTT_CUSTO'] ?? ''));
                if ($codigo === '') {
                    $resumo['ignorados']++;

                    continue;
                }

                $filial = rtrim((string) ($l['CTT_FILIAL'] ?? ''));
                $centro = CentroCusto::where('ctt_filial', $filial)->where('ctt_custo', $codigo)->first() ?? new CentroCusto;
                $novo = ! $centro->exists;
                $centro->forceFill([
                    'ctt_filial' => $filial,
                    'ctt_custo' => $codigo,
                    'ctt_desc01' => mb_substr(rtrim((string) ($l['CTT_DESC01'] ?? '')), 0, 40),
                    'ctt_classe' => in_array($l['CTT_CLASSE'] ?? '', ['1', '2'], true) ? $l['CTT_CLASSE'] : '2',
                    'ctt_bloq' => ($l['CTT_BLOQ'] ?? '') === '1' ? '1' : '2',
                    'ctt_ccsup' => rtrim((string) ($l['CTT_CCSUP'] ?? '')),
                    'ctt_dtexis' => substr(trim((string) ($l['CTT_DTEXIS'] ?? '')), 0, 8),
                    'ctt_dtexsf' => substr(trim((string) ($l['CTT_DTEXSF'] ?? '')), 0, 8),
                    'd_e_l_e_t_' => ($l['D_E_L_E_T_'] ?? '') === '*' ? '*' : ' ',
                    'r_e_c_n_o_' => isset($l['R_E_C_N_O_']) ? (int) $l['R_E_C_N_O_'] : null,
                ]);

                // Campos CHAR do SQL Server (ex.: ctt_dtexsf vazio) voltam com espaços à direita: compara sem eles,
                // senão todo centro de custo parecia alterado a cada sincronização.
                $mudou = $novo || collect($centro->getDirty())->contains(
                    fn ($valor, $campo) => rtrim((string) $valor) !== rtrim((string) $centro->getOriginal($campo)));
                if ($mudou) {
                    $centro->save();
                    $resumo[$novo ? 'novos' : 'atualizados']++;
                }
            }
        });

        return $resumo;
    }
}
