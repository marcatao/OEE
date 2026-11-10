<?php

namespace App\Services\Protheus;

use Illuminate\Support\Facades\DB;

/**
 * Base para tabelas "tipo Protheus" (cópia da origem), pensada para tabelas grandes (ex.: SC2, SB1).
 *
 * Trabalha em LOTES (config protheus.lote, padrão 1.000 linhas): para cada lote lido do Protheus, busca no portal só
 * os registros daquele lote, grava o que é novo ou mudou e libera a memória antes do próximo. Assim o consumo de
 * memória não cresce com o tamanho da tabela. Cada lote é gravado numa transação: se algo falhar no meio, o que já
 * foi gravado fica (a próxima sincronização continua de onde precisar).
 *
 * No Protheus, excluir e recriar deixa duas linhas com a mesma chave (a antiga com D_E_L_E_T_ = '*'): vale a não
 * excluída de R_E_C_N_O_ mais recente, mesmo que as duas caiam em lotes diferentes.
 * Nunca apaga registros do portal: o que foi excluído no Protheus fica marcado com d_e_l_e_t_ = '*'.
 */
abstract class ImportadorEspelho implements ImportadorProtheus
{
    /** Tabela do portal. */
    abstract protected function tabela(): string;

    /** Colunas do portal que formam a chave (ex.: b1_filial, b1_cod). */
    abstract protected function colunasChave(): array;

    /** Coluna indexada do portal usada para achar os registros de um lote (ex.: b1_cod, numero). */
    abstract protected function colunaBusca(): string;

    /**
     * Linha da origem → colunas do portal (sem id). null = ignorar (ex.: sem código).
     *
     * @param  array<string, mixed>  $l  colunas da origem em MAIÚSCULAS
     */
    abstract protected function linhaDestino(array $l): ?array;

    /** Depois de gravar um lote: ids das linhas alteradas (ex.: recalcular status). As novas passam por completarNova(). */
    protected function depoisDeGravar(array $alterados): void {}

    /** Ajustes na linha nova antes de inserir (ex.: status inicial). */
    protected function completarNova(array $linha): array
    {
        return $linha;
    }

    public function importar(iterable $linhas): array
    {
        $resumo = ['novos' => 0, 'atualizados' => 0, 'ignorados' => 0, 'excluidos' => 0];
        $tamanho = max(50, (int) config('protheus.lote', 1000));

        $lote = [];
        foreach ($linhas as $linha) {
            $lote[] = array_change_key_case((array) $linha, CASE_UPPER);
            if (count($lote) >= $tamanho) {
                $this->processarLote($lote, $resumo);
                $lote = [];
            }
        }
        if ($lote) {
            $this->processarLote($lote, $resumo);
        }

        return $resumo;
    }

    private function processarLote(array $lote, array &$resumo): void
    {
        $chaves = $this->colunasChave();

        // 1) Uma linha por chave dentro do lote (a não excluída mais recente).
        $porChave = [];
        foreach ($lote as $l) {
            $destino = $this->linhaDestino($l);
            if ($destino === null) {
                $resumo['ignorados']++;

                continue;
            }
            $chave = $this->chave($destino, $chaves);
            if (! isset($porChave[$chave]) || $this->substitui($destino, $porChave[$chave])) {
                $porChave[$chave] = $destino;
            }
        }
        if (! $porChave) {
            return;
        }

        // 2) Só os registros do portal deste lote: chave → [id, assinatura, linha].
        $colunas = array_keys(reset($porChave));
        $busca = $this->colunaBusca();
        $existentes = [];
        foreach (array_chunk(array_values(array_unique(array_column($porChave, $busca))), 1000) as $valores) {
            foreach (DB::table($this->tabela())->select(array_merge(['id'], $colunas))->whereIn($busca, $valores)->get() as $r) {
                $r = (array) $r;
                $existentes[$this->chave($r, $chaves)] = [$r['id'], $this->assinatura($r, $colunas), $r];
            }
        }

        // 3) Grava o lote: novos em blocos (limite de parâmetros do SQL Server), alterados um a um.
        DB::transaction(function () use ($porChave, $existentes, $colunas, &$resumo) {
            $agora = now();
            $novas = [];
            $alterados = [];
            foreach ($porChave as $chave => $destino) {
                if (! isset($existentes[$chave])) {
                    if ($destino['d_e_l_e_t_'] === '*') {
                        $resumo['ignorados']++; // excluída no Protheus e nunca veio para o portal

                        continue;
                    }
                    $novas[] = $this->completarNova($destino + ['sincronizado_em' => $agora]);

                    continue;
                }

                [$id, $assinatura, $atual] = $existentes[$chave];
                // Versão mais antiga do mesmo registro (veio em outro lote): não sobrescreve a mais recente.
                if (! $this->substitui($destino, $atual)) {
                    $resumo['ignorados']++;

                    continue;
                }
                if ($assinatura !== $this->assinatura($destino, $colunas)) {
                    DB::table($this->tabela())->where('id', $id)->update($destino + ['sincronizado_em' => $agora]);
                    $alterados[] = $id;
                    $resumo[$destino['d_e_l_e_t_'] === '*' ? 'excluidos' : 'atualizados']++;
                }
            }

            if ($novas) {
                $porBloco = max(1, intdiv(2000, count($novas[0]))); // SQL Server: até 2.100 parâmetros por comando
                foreach (array_chunk($novas, $porBloco) as $bloco) {
                    DB::table($this->tabela())->insert($bloco);
                }
                $resumo['novos'] += count($novas);
            }

            $this->depoisDeGravar($alterados);
        });
    }

    /**
     * A linha nova substitui a atual (mesma chave)? Sim se a atual está excluída e a nova não; senão, se não está
     * excluída e a atual sim, não; e, no mesmo caso, vale o R_E_C_N_O_ mais recente (ou igual: a mesma linha mudou).
     */
    private function substitui(array $nova, array $atual): bool
    {
        $novaExcluida = $nova['d_e_l_e_t_'] === '*';
        $atualExcluida = ($atual['d_e_l_e_t_'] ?? ' ') === '*';
        if ($novaExcluida !== $atualExcluida) {
            // Excluída só substitui a ativa se for a mesma linha (mesmo R_E_C_N_O_) ou mais recente.
            return $atualExcluida || (int) $nova['r_e_c_n_o_'] >= (int) ($atual['r_e_c_n_o_'] ?? 0);
        }

        return (int) $nova['r_e_c_n_o_'] >= (int) ($atual['r_e_c_n_o_'] ?? 0);
    }

    /** Texto MAIÚSCULO da origem sem os espaços à direita, cortado no tamanho da coluna do portal. */
    protected static function texto(array $l, string $campo, int $tamanho): string
    {
        return mb_substr(rtrim((string) ($l[$campo] ?? '')), 0, $tamanho);
    }

    protected static function excluida(array $l): string
    {
        return ($l['D_E_L_E_T_'] ?? '') === '*' ? '*' : ' ';
    }

    private function chave(array $linha, array $chaves): string
    {
        return implode('|', array_map(fn ($c) => rtrim((string) $linha[$c]), $chaves));
    }

    private function assinatura(array $linha, array $colunas): string
    {
        return md5(json_encode(array_map(fn ($c) => is_float($linha[$c] ?? null) || is_numeric($linha[$c] ?? null)
            ? (string) (0 + $linha[$c]) : rtrim((string) ($linha[$c] ?? '')), $colunas)));
    }
}
