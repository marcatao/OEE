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
        $this->encerrarInterrompidas();
        $registro = Sincronizacao::create([
            'chave' => $chave,
            'origem' => Str::limit($this->origem($chave), 157),
            'disparo' => $disparo,
            'usuario_id' => $usuario?->id,
            'iniciado_em' => now(),
            'situacao' => 'executando',
        ]);

        $trava = Cache::lock("protheus-sincronizar-{$chave}", config('protheus.tempo_maximo', 600));
        if (! $trava->get()) {
            return $this->terminar($registro, 'erro', mensagem: 'Já existe uma sincronização desta tabela em andamento. Tente de novo em alguns minutos.');
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
            $linhas = (function () use ($importador, $tabela, $porPagina, &$lidos) {
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
                } while ($quantos === $porPagina);
            })();

            $resumo = $importador->importar($linhas);

            return $this->terminar($registro, 'sucesso', $resumo + ['lidos' => $lidos]);
        } catch (Throwable $e) {
            Log::warning("Falha ao sincronizar {$chave} com o Protheus", ['erro' => $e->getMessage()]);

            return $this->terminar($registro, 'erro', mensagem: $e->getMessage());
        } finally {
            $trava->release();
        }
    }

    /**
     * Execuções que ficaram "executando" além do tempo máximo: o processo parou sem conseguir registrar o fim
     * (falta de memória, tempo esgotado, servidor reiniciado). Marca como erro para o histórico não ficar preso.
     */
    public function encerrarInterrompidas(): int
    {
        return Sincronizacao::where('situacao', 'executando')
            ->where('iniciado_em', '<', now()->subSeconds((int) config('protheus.tempo_maximo', 600)))
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
