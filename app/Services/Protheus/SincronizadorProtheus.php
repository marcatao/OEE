<?php

namespace App\Services\Protheus;

use App\Models\Sincronizacao;
use App\Models\Usuario;
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
    public function sincronizarTudo(string $disparo, ?Usuario $usuario = null): array
    {
        $resultado = [];
        foreach (array_keys($this->itens()) as $chave) {
            $resultado[$chave] = $this->sincronizar($chave, $disparo, $usuario);
        }

        return $resultado;
    }

    public function sincronizar(string $chave, string $disparo, ?Usuario $usuario = null): Sincronizacao
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
            $tabela = $item['tabela_origem'];
            if (! preg_match('/^[A-Za-z0-9_]+(\.[A-Za-z0-9_]+)?$/', $tabela)) {
                throw new InvalidArgumentException("Nome de tabela de origem inválido: {$tabela}");
            }

            /** @var ImportadorProtheus $importador */
            $importador = app($item['importador']);
            $lidos = 0;
            $porPagina = max(50, (int) config('protheus.lote', 1000));
            // Lê o Protheus em páginas (ordem de R_E_C_N_O_): nunca carrega a tabela inteira na memória.
            $linhas = (function () use ($importador, $tabela, $porPagina, $registro, &$lidos) {
                $ultimo = null;
                do {
                    $consulta = DB::connection('protheus')->table($tabela)->select($importador->campos());
                    if (method_exists($importador, 'filtrar')) {
                        $consulta = $importador->filtrar($consulta); // ex.: só OPs a partir de uma data
                    }
                    if ($ultimo !== null) {
                        $consulta->where('R_E_C_N_O_', '>', $ultimo);
                    }
                    $pagina = $consulta->orderBy('R_E_C_N_O_')->limit($porPagina)->get();
                    foreach ($pagina as $linha) {
                        $lidos++;
                        $ultimo = array_change_key_case((array) $linha, CASE_UPPER)['R_E_C_N_O_'];
                        yield $linha;
                    }
                    $quantos = $pagina->count();
                    unset($pagina);
                    $registro->update(['lidos' => $lidos, 'sinal_em' => now()]); // sinal de vida a cada página
                } while ($quantos === $porPagina);
            })();

            $resumo = $importador->importar($linhas);

            return $this->terminar($registro, 'sucesso', $resumo + ['lidos' => $lidos]);
        } catch (Throwable $e) {
            Log::warning("Falha ao sincronizar {$chave} com o Protheus", ['erro' => $e->getMessage()]);

            return $this->terminar($registro, 'erro', mensagem: $e->getMessage());
        }
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
        ]);

        return $registro;
    }
}
