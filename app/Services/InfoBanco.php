<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Mostra a conexão configurada no .env (sem a senha) e testa se ela funciona.
 */
class InfoBanco
{
    public const TABELAS = [
        'usuarios', 'grupos', 'grupo_usuario', 'grupo_permissoes', 'registros_login', 'plantas', 'linhas', 'maquinas', 'turnos', 'produtos',
        'motivos_parada', 'apontamentos', 'paradas', 'dispositivos_iot', 'legendas', 'apontamentos_estado',
    ];

    public function descricao(?string $conexao = null): array
    {
        $nome = $conexao ?? config('database.default');
        $cfg = config("database.connections.{$nome}");
        $driver = $cfg['driver'];

        if ($driver === 'sqlite') {
            return ['Tipo' => 'SQLite (só para testes)', 'Banco' => $cfg['database']];
        }

        $descricao = [
            'Tipo' => $driver === 'sqlsrv' ? 'SQL Server' : $driver,
            'Servidor' => $cfg['host'].($cfg['port'] ? ','.$cfg['port'] : ''),
            'Banco' => $cfg['database'],
            'Autenticação' => empty($cfg['username']) ? 'Windows (conta que roda o portal)' : 'usuário e senha do SQL Server',
            'Usuário' => $cfg['username'] ?: '-',
        ];
        if ($driver === 'sqlsrv') {
            $descricao['Criptografia'] = ($cfg['encrypt'] ?? 'yes').' (confiar no certificado: '.($cfg['trust_server_certificate'] ?? 'false').')';
        }

        return $descricao;
    }

    /** @return array{0: bool, 1: string} */
    public function testar(): array
    {
        try {
            $versao = match (DB::getDriverName()) {
                'sqlsrv' => strtok(DB::scalar('SELECT @@VERSION'), "\n"),
                'sqlite' => 'SQLite '.DB::scalar('SELECT sqlite_version()'),
                default => DB::getDriverName().' '.DB::getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION),
            };

            return [true, trim($versao)];
        } catch (Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    /** @return array<string, int|null> null = tabela ainda não criada */
    public function contagens(): array
    {
        $contagens = [];
        foreach (self::TABELAS as $tabela) {
            $contagens[$tabela] = Schema::hasTable($tabela) ? DB::table($tabela)->count() : null;
        }

        return $contagens;
    }
}
