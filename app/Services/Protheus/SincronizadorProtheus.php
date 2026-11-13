<?php

namespace App\Services\Protheus;

use App\Models\Sincronizacao;
use App\Models\Usuario;
use App\Support\Cronometro;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Sincroniza tabelas do Protheus (só leitura) com as do portal. Usado pelo botão da tela Banco de dados, pelo
 * comando "php artisan protheus:sincronizar" e pelo agendamento automático (config/protheus.php, "agenda").
 * Cada execução fica registrada na tabela "sincronizacoes".
 */
class SincronizadorProtheus
{
    /** @return array<string, array{descricao: string, tabela_origem: string, tabela_destino: string, importador: class-string, modo: string}> */
    public function itens(): array
    {
        return config('protheus.sincronizacoes', []);
    }

    /** "Compras.CTT": banco e tabela de origem no Protheus. */
    public function origem(string $chave): string
    {
        return config('database.connections.protheus.database').'.'.$this->item($chave)['tabela_origem'];
    }

    /** @return array<string, Sincronizacao> uma execução por tabela */
    public function sincronizarTudo(string $disparo, ?Usuario $usuario = null, bool $completa = false): array
    {
        $resultado = [];
        foreach (array_keys($this->itens()) as $chave) {
            $resultado[$chave] = $this->sincronizar($chave, $disparo, $usuario, $completa);
        }

        return $resultado;
    }

    /**
     * Sincronização inteira de uma tabela, de uma vez (comando e agendamento: no PHP de linha de comando não há tempo
     * limite). Com $completa = false, as tabelas incrementais (SD3, SD2) leem só o que é novo e a janela de releitura.
     */
    public function sincronizar(string $chave, string $disparo, ?Usuario $usuario = null, bool $completa = false): Sincronizacao
    {
        $registro = $this->iniciar($chave, $disparo, $usuario, $completa);

        return $registro->situacao === 'executando' ? $this->continuar($registro) : $registro;
    }

    /**
     * Abre uma execução (situação "executando") sem processar nada ainda. Recusa (situação "erro") se outra execução
     * da mesma tabela estiver em andamento. Depois, chame continuar() quantas vezes for preciso.
     */
    public function iniciar(string $chave, string $disparo, ?Usuario $usuario = null, bool $completa = false): Sincronizacao
    {
        $item = $this->item($chave);
        $dados = ['chave' => $chave, 'origem' => Str::limit($this->origem($chave), 157), 'disparo' => $disparo,
            'usuario_id' => $usuario?->id, 'iniciado_em' => now(), 'sinal_em' => now()];

        // "Uma por vez" por tabela: a execução em andamento dá sinal de vida a cada lote (sinal_em). Se o processo
        // morrer (falta de memória, tempo esgotado), em poucos minutos ela é dada como interrompida e não trava a
        // próxima. A trava de cache só protege este começo (dois cliques ao mesmo tempo) e dura segundos.
        $inicio = Cache::lock("protheus-inicio-{$chave}", 30);
        if (! $inicio->get()) {
            return Sincronizacao::create($dados + ['situacao' => 'erro', 'terminado_em' => now(),
                'mensagem' => 'Outra sincronização desta tabela está começando agora. Tente de novo em alguns segundos.']);
        }
        try {
            $this->encerrarInterrompidas();
            $emAndamento = Sincronizacao::where('chave', $chave)->where('situacao', 'executando')->latest('iniciado_em')->first();
            if ($emAndamento) {
                return Sincronizacao::create($dados + ['situacao' => 'erro', 'terminado_em' => now(),
                    'mensagem' => sprintf('Já existe uma sincronização desta tabela em andamento (começou %s, %d linhas lidas até agora). Aguarde terminar.',
                        $emAndamento->iniciado_em->setTimezone(config('oee.fuso_exibicao'))->format('d/m H:i'), $emAndamento->lidos)]);
            }
            $registro = Sincronizacao::create($dados + ['situacao' => 'executando']);
        } finally {
            $inicio->release();
        }

        try {
            $tabela = $this->tabelaOrigem($item);
            $importador = app($item['importador']);
            // Leitura incremental (SD3, SD2): começa no primeiro R_E_C_N_O_ que precisa ser relido.
            $primeiro = $importador instanceof ImportadorEspelho ? $importador->primeiroRecno($tabela, $completa) : null;
            if ($primeiro !== null) {
                $registro->update(['ultimo_recno' => $primeiro - 1]);
            }
        } catch (Throwable $e) {
            Log::warning("Falha ao sincronizar {$chave} com o Protheus", ['erro' => $e->getMessage()]);

            return $this->terminar($registro, 'erro', mensagem: $this->explicarErro($e, $chave));
        }

        return $registro;
    }

    /**
     * Processa a execução a partir do último R_E_C_N_O_ gravado. Com $segundos, para depois do lote em que o tempo
     * acabar e deixa a execução "executando" para a próxima etapa (o botão da tela chama de novo); sem, vai até o fim.
     * Tabelas que não são cópia em lotes (centros de custo, máquinas) sempre vão até o fim de uma vez (são pequenas).
     */
    public function continuar(Sincronizacao $registro, ?float $segundos = null): Sincronizacao
    {
        $passo = Cache::lock("protheus-passo-{$registro->id}", 300);
        if (! $passo->get()) {
            // Outra etapa desta execução está rodando. Se ela não dá sinal de vida há mais de 75 s, o processo morreu
            // (ex.: o IIS cortou a requisição): assume a execução e continua do último lote gravado.
            $registro->refresh();
            $parada = $registro->sinal_em === null
                || $registro->sinal_em->lt(now()->subSeconds(max(75, (int) config('protheus.segundos_por_etapa', 25) + 50)));
            if ($registro->situacao !== 'executando' || ! $parada) {
                return $registro;
            }
            $passo->forceRelease();
            if (! $passo->get()) {
                return $registro;
            }
        }
        // Se o PHP morrer no meio (tempo limite, memória), a trava é liberada mesmo assim: a próxima etapa continua
        // na hora do último lote gravado, sem esperar.
        register_shutdown_function(fn () => rescue(fn () => $passo->release(), report: false));
        Cronometro::zerar();
        try {
            $registro->refresh();
            if ($registro->situacao !== 'executando') {
                return $registro;
            }
            $item = $this->item($registro->chave);
            $tabela = $this->tabelaOrigem($item);

            /** @var ImportadorProtheus $importador */
            $importador = app($item['importador']);
            if (! $importador instanceof ImportadorEspelho) {
                $segundos = null;
            }
            $limite = $segundos !== null ? microtime(true) + $segundos : null;
            $base = (int) $registro->lidos;
            $lidos = 0;
            $ultimo = $registro->ultimo_recno;
            $terminou = false;
            $porPagina = max(50, (int) config('protheus.lote', 1000));
            // Lê o Protheus em páginas (ordem de R_E_C_N_O_): nunca carrega a tabela inteira na memória.
            $linhas = (function () use ($importador, $tabela, $porPagina, $registro, $base, $limite, &$lidos, &$ultimo, &$terminou) {
                $relogio = microtime(true);
                do {
                    if ($limite !== null && $lidos > 0 && microtime(true) >= $limite) {
                        return; // tempo desta etapa acabou: a próxima continua do último R_E_C_N_O_
                    }
                    $consulta = DB::connection('protheus')->table($tabela)->select($importador->campos());
                    if (method_exists($importador, 'filtrar')) {
                        $consulta = $importador->filtrar($consulta); // ex.: só OPs a partir de uma data
                    }
                    if ($ultimo !== null) {
                        $consulta->where('R_E_C_N_O_', '>', $ultimo);
                    }
                    $pagina = Cronometro::medir('Protheus', fn () => $consulta->orderBy('R_E_C_N_O_')->limit($porPagina)->get());
                    foreach ($pagina as $linha) {
                        $lidos++;
                        $ultimo = (int) array_change_key_case((array) $linha, CASE_UPPER)['R_E_C_N_O_'];
                        yield $linha;
                    }
                    $quantos = $pagina->count();
                    unset($pagina);
                    // Sinal de vida a cada página. Página cheia = lote já gravado pelo importador (o lote tem o mesmo
                    // tamanho da página): grava também o ponto de parada, para uma etapa cortada no meio não perder nada.
                    $registro->update(['lidos' => $base + $lidos, 'sinal_em' => now()]
                        + ($quantos === $porPagina ? ['ultimo_recno' => $ultimo] : []));
                    $duracao = microtime(true) - $relogio;
                    if ($duracao > 10) {
                        Log::warning(sprintf('Sincronização %s: lote de %d linhas levou %.1f s (até R_E_C_N_O_ %s).',
                            $registro->chave, $quantos, $duracao, $ultimo));
                    }
                    $relogio = microtime(true);
                } while ($quantos === $porPagina);
                $terminou = true;
            })();

            $resumo = $importador->importar($linhas);

            // Soma desta etapa com as anteriores.
            $totais = ['lidos' => $base + $lidos];
            foreach (['novos', 'atualizados', 'ignorados', 'excluidos'] as $campo) {
                $totais[$campo] = (int) $registro->{$campo} + (int) ($resumo[$campo] ?? 0);
            }
            if (! $terminou) {
                $registro->update($totais + ['ultimo_recno' => $ultimo, 'sinal_em' => now()]);

                return $registro;
            }

            // Nada lido: diz por quê (ex.: campo da OP vazio na SD3), para não parecer que deu certo.
            $aviso = $totais['lidos'] === 0 && method_exists($importador, 'diagnosticoSemLinhas')
                ? $importador->diagnosticoSemLinhas($tabela) : null;

            return $this->terminar($registro, 'sucesso', $totais + ['ultimo_recno' => $ultimo], $aviso);
        } catch (Throwable $e) {
            Log::warning("Falha ao sincronizar {$registro->chave} com o Protheus", ['erro' => $e->getMessage()]);

            return $this->terminar($registro, 'erro', ['lidos' => $registro->lidos, 'novos' => $registro->novos,
                'atualizados' => $registro->atualizados, 'ignorados' => $registro->ignorados, 'excluidos' => $registro->excluidos],
                $this->explicarErro($e, $registro->chave));
        } finally {
            $passo->release();
        }
    }

    /**
     * Mensagem do erro para a tela. Tabela que não existe no banco do Protheus (SQL Server: "Invalid object name";
     * SQLite: "no such table") ganha a explicação de como resolver: o nome certo vai em PROTHEUS_TABELA_xxx no .env.
     */
    private function explicarErro(Throwable $e, string $chave): string
    {
        $mensagem = $e->getMessage();
        if (! preg_match('/Invalid object name|no such table/i', $mensagem)) {
            return $mensagem;
        }
        $tabela = (string) (config("protheus.sincronizacoes.{$chave}.tabela_origem") ?? '');
        $sigla = strtoupper(substr((string) preg_replace('/^.*\./', '', $tabela), 0, 3));
        $banco = (string) config('database.connections.protheus.database');

        return "A tabela {$tabela} não existe no banco do Protheus ({$banco}) ou o usuário da conexão não tem acesso a ela. "
            .'Peça ao TI para disponibilizá-la nesse banco (como as outras tabelas já sincronizadas) ou, se ela tem outro nome '
            ."(ex.: {$sigla}010 ou dbo.{$sigla}010), informe em PROTHEUS_TABELA_{$sigla} no .env. Erro original: ".$mensagem;
    }

    private function tabelaOrigem(array $item): string
    {
        $tabela = $item['tabela_origem'];
        if (! preg_match('/^[A-Za-z0-9_]+(\.[A-Za-z0-9_]+)?$/', $tabela)) {
            throw new InvalidArgumentException("Nome de tabela de origem inválido: {$tabela}");
        }

        return $tabela;
    }

    /**
     * Execuções "executando" sem sinal de vida há mais de protheus.sem_sinal segundos: o processo parou sem registrar o fim
     * (falta de memória, tempo esgotado, servidor reiniciado). Marca como erro para o histórico não ficar preso.
     */
    public function encerrarInterrompidas(): int
    {
        $limite = now()->subSeconds(max(30, (int) config('protheus.sem_sinal', 180)));

        return Sincronizacao::where('situacao', 'executando')
            ->where(fn ($q) => $q->where('sinal_em', '<', $limite)
                ->orWhere(fn ($w) => $w->whereNull('sinal_em')->where('iniciado_em', '<', $limite)))
            ->update([
                'situacao' => 'erro',
                'terminado_em' => now(),
                'mensagem' => 'Interrompida: o processo parou antes de terminar (falta de memória, tempo esgotado ou servidor reiniciado). Rode de novo.',
            ]);
    }

    /** Última execução de cada tabela sincronizada. @return array<string, Sincronizacao|null> */
    public function ultimas(): array
    {
        $this->encerrarInterrompidas();
        $ultimas = [];
        foreach (array_keys($this->itens()) as $chave) {
            $ultimas[$chave] = Sincronizacao::where('chave', $chave)->latest('iniciado_em')->latest('id')->first();
        }

        return $ultimas;
    }

    private function item(string $chave): array
    {
        return $this->itens()[$chave] ?? throw new InvalidArgumentException("Tabela de sincronização desconhecida: {$chave}");
    }

    private function terminar(Sincronizacao $registro, string $situacao, array $resumo = [], ?string $mensagem = null): Sincronizacao
    {
        $registro->update([
            'situacao' => $situacao,
            'terminado_em' => now(),
            'lidos' => $resumo['lidos'] ?? 0,
            'novos' => $resumo['novos'] ?? 0,
            'atualizados' => $resumo['atualizados'] ?? 0,
            'ignorados' => $resumo['ignorados'] ?? 0,
            'excluidos' => $resumo['excluidos'] ?? 0,
            'mensagem' => $mensagem ? Str::limit($mensagem, 997) : null,
        ] + (array_key_exists('ultimo_recno', $resumo) ? ['ultimo_recno' => $resumo['ultimo_recno']] : []));

        return $registro;
    }
}
