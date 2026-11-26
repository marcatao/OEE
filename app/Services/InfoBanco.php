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
        'usuarios', 'grupos', 'grupo_usuario', 'grupo_permissoes', 'registros_login', 'acessos_paginas', 'usuario_permissoes', 'plantas', 'linhas', 'maquinas', 'turnos', 'produtos',
        'motivos_parada', 'apontamentos', 'paradas', 'dispositivos_iot', 'legendas', 'apontamentos_estado', 'centros_custo', 'sincronizacoes', 'filtros_usuarios', 'produtos_protheus', 'ordens_producao',
        'movimentos_producao', 'notas_fiscais_op', 'estruturas_produto', 'roteiros_operacao', 'ferramentas',
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
            $descricao['Criptografia'] = ($cfg['encrypt'] ?? 'padrão do driver').' (confiar no certificado: '.($cfg['trust_server_certificate'] ?? 'padrão do driver').')';
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

    /**
     * De onde vem cada tabela: do próprio sistema OEE, sincronizada do Protheus (cópia) ou mista (parte dos campos
     * vem do Protheus e o resto é do sistema OEE) — config/protheus.php.
     *
     * @return array<string, array{tipo: string, origem: ?string, chave: ?string}>
     */
    public function origens(): array
    {
        $doProtheus = [];
        foreach (config('protheus.sincronizacoes', []) as $chave => $item) {
            $doProtheus[$item['tabela_destino']] = [
                'tipo' => ($item['modo'] ?? 'espelho') === 'mista' ? 'mista' : 'protheus',
                'origem' => config('database.connections.protheus.database').'.'.$item['tabela_origem'],
                'chave' => $chave,
            ];
        }

        $origens = [];
        foreach (self::TABELAS as $tabela) {
            $origens[$tabela] = $doProtheus[$tabela] ?? ['tipo' => 'sistema', 'origem' => null, 'chave' => null];
        }

        return $origens;
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
