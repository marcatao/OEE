<?php

namespace App\Services\Protheus;

use Illuminate\Support\Facades\DB;

/**
 * Base para tabelas "tipo Protheus" (cópia da origem), pensada para tabelas grandes (ex.: SC2, SB1):
 * - carrega de uma vez a chave e uma "assinatura" do que já existe no portal;
 * - grava só o que é novo ou mudou (inserção em lotes; atualização só das linhas alteradas);
 * - no Protheus, excluir e recriar deixa duas linhas com a mesma chave (uma com D_E_L_E_T_ = '*'): vale a não
 *   excluída mais recente.
 * Nunca apaga registros do portal: o que foi excluído no Protheus fica marcado com d_e_l_e_t_ = '*'.
 */
abstract class ImportadorEspelho implements ImportadorProtheus
{
    /** Tabela do portal. */
    abstract protected function tabela(): string;

    /** Colunas do portal que formam a chave (ex.: b1_filial, b1_cod). */
    abstract protected function colunasChave(): array;

    /**
     * Linha da origem → colunas do portal (sem id). null = ignorar (ex.: sem código).
     *
     * @param  array<string, mixed>  $l  colunas da origem em MAIÚSCULAS
     */
    abstract protected function linhaDestino(array $l): ?array;

    /** Depois de gravar: ids das linhas alteradas (ex.: recalcular status). As novas passam por completarNova(). */
    protected function depoisDeGravar(array $alterados): void {}

    /** Ajustes na linha nova antes de inserir (ex.: status inicial). */
    protected function completarNova(array $linha): array
    {
        return $linha;
    }

    public function importar(iterable $linhas): array
    {
        $resumo = ['novos' => 0, 'atualizados' => 0, 'ignorados' => 0, 'excluidos' => 0];
        $chaves = $this->colunasChave();

        // 1) Lê a origem e fica com uma linha por chave (a não excluída mais recente).
        $porChave = [];
        foreach ($linhas as $linha) {
            $l = array_change_key_case((array) $linha, CASE_UPPER);
            $destino = $this->linhaDestino($l);
            if ($destino === null) {
                $resumo['ignorados']++;

                continue;
            }
            $chave = $this->chave($destino, $chaves);
            $atual = $porChave[$chave] ?? null;
            $excluida = $destino['d_e_l_e_t_'] === '*';
            if ($atual === null || ($atual['d_e_l_e_t_'] === '*' && ! $excluida)
                || (($atual['d_e_l_e_t_'] === '*') === $excluida && (int) $destino['r_e_c_n_o_'] > (int) $atual['r_e_c_n_o_'])) {
                $porChave[$chave] = $destino;
            }
        }

        // 2) O que já existe no portal: chave → [id, assinatura].
        $colunas = array_keys(reset($porChave) ?: []);
        $existentes = [];
        if ($colunas) {
            foreach (DB::table($this->tabela())->select(array_merge(['id'], $colunas))->cursor() as $r) {
                $r = (array) $r;
                $existentes[$this->chave($r, $chaves)] = [$r['id'], $this->assinatura($r, $colunas)];
            }
        }

        // 3) Grava: novos em lotes, alterados um a um.
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
            [$id, $assinatura] = $existentes[$chave];
            if ($assinatura !== $this->assinatura($destino, $colunas)) {
                DB::table($this->tabela())->where('id', $id)->update($destino + ['sincronizado_em' => $agora]);
                $alterados[] = $id;
                $resumo[$destino['d_e_l_e_t_'] === '*' ? 'excluidos' : 'atualizados']++;
            }
        }

        if ($novas) {
            $porLote = max(1, intdiv(2000, count($novas[0]))); // SQL Server: até 2.100 parâmetros por comando
            foreach (array_chunk($novas, $porLote) as $lote) {
                DB::table($this->tabela())->insert($lote);
            }
            $resumo['novos'] = count($novas);
        }

        $this->depoisDeGravar($alterados);

        return $resumo;
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
