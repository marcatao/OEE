<?php

namespace App\Services\Protheus;

use App\Models\Maquina;
use Illuminate\Support\Facades\DB;

/**
 * Máquinas a partir da SHB do Protheus (centros de trabalho). A tabela "maquinas" é MISTA:
 * - código (HB_COD), nome (HB_NOME) e centro de custo (HB_CC) vêm do Protheus, só na primeira sincronização;
 * - todo o resto (linha, ciclo ideal, paradas, IoT, situação) é do sistema OEE.
 *
 * Regras:
 * - máquina nova chega INATIVA e sem linha: o usuário completa o cadastro e ativa;
 * - máquina já sincronizada NUNCA é alterada pela sincronização, a não ser que seja excluída no Protheus
 *   (D_E_L_E_T_ = '*'): aí é marcada como excluída e desativada;
 * - se já existir no portal uma máquina cadastrada à mão com o mesmo código, ela é vinculada ao Protheus
 *   (recebe código, nome e centro de custo da SHB) em vez de duplicar.
 */
class ImportadorMaquinas implements ImportadorProtheus
{
    public const CAMPOS = ['HB_FILIAL', 'HB_COD', 'HB_NOME', 'HB_CC', 'D_E_L_E_T_', 'R_E_C_N_O_'];

    public function campos(): array
    {
        return self::CAMPOS;
    }

    public function importar(iterable $linhas): array
    {
        $resumo = ['novos' => 0, 'atualizados' => 0, 'ignorados' => 0, 'excluidos' => 0];

        // No Protheus, excluir e recriar um registro deixa a linha antiga com '*' e cria outra. Por código vale a
        // linha não excluída mais recente; só conta como excluída se TODAS as linhas do código estiverem excluídas.
        $porCodigo = [];
        foreach ($linhas as $linha) {
            $l = array_change_key_case((array) $linha, CASE_UPPER);
            $codigo = mb_substr(rtrim((string) ($l['HB_COD'] ?? '')), 0, 30);
            if ($codigo === '') {
                $resumo['ignorados']++;

                continue;
            }
            $l['_codigo'] = $codigo;
            $l['_excluida'] = ($l['D_E_L_E_T_'] ?? '') === '*';
            $atual = $porCodigo[$codigo] ?? null;
            if ($atual === null || ($atual['_excluida'] && ! $l['_excluida'])
                || ($atual['_excluida'] === $l['_excluida'] && (int) $l['R_E_C_N_O_'] > (int) $atual['R_E_C_N_O_'])) {
                $porCodigo[$codigo] = $l;
            }
        }

        DB::transaction(function () use ($porCodigo, &$resumo) {
            foreach ($porCodigo as $codigo => $l) {
                $resumo[$this->aplicar($codigo, $l)]++;
            }
        });

        return $resumo;
    }

    /** @return 'novos'|'atualizados'|'ignorados'|'excluidos' */
    private function aplicar(string $codigo, array $l): string
    {
        $maquina = Maquina::where('codigo', $codigo)->first();
        $filial = rtrim((string) ($l['HB_FILIAL'] ?? ''));

        if ($l['_excluida']) {
            // Excluída no Protheus: só interessa se a máquina veio de lá e ainda não estava marcada.
            if (! $maquina || ! $maquina->doProtheus() || $maquina->excluidaNoProtheus()) {
                return 'ignorados';
            }
            $maquina->forceFill(['d_e_l_e_t_' => '*', 'ativo' => false, 'excluida_protheus_em' => now()])->save();

            return 'excluidos';
        }

        if ($maquina && $maquina->doProtheus()) {
            if (! $maquina->excluidaNoProtheus()) {
                return 'ignorados'; // já sincronizada: a sincronização não altera mais
            }
            // Voltou a existir no Protheus: tira a marca de excluída; continua inativa até o usuário ativar.
            $maquina->forceFill(['d_e_l_e_t_' => ' ', 'excluida_protheus_em' => null, 'r_e_c_n_o_' => (int) $l['R_E_C_N_O_']])->save();

            return 'atualizados';
        }

        $doProtheus = [
            'codigo' => $codigo,
            'nome' => mb_substr(rtrim((string) ($l['HB_NOME'] ?? '')), 0, 120) ?: $codigo,
            'centro_custo' => mb_substr(rtrim((string) ($l['HB_CC'] ?? '')), 0, 20) ?: null,
            'hb_filial' => $filial,
            'r_e_c_n_o_' => (int) $l['R_E_C_N_O_'],
            'd_e_l_e_t_' => ' ',
            'sincronizado_em' => now(),
        ];

        if ($maquina) {
            // Cadastrada à mão antes, com o mesmo código: passa a ser a máquina do Protheus (mantém o resto).
            $maquina->forceFill($doProtheus)->save();

            return 'atualizados';
        }

        (new Maquina)->forceFill($doProtheus + ['ativo' => false, 'linha_id' => null])->save();

        return 'novos';
    }
}
