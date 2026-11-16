<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Procedures do banco do portal (só SQL Server). O texto fica em database/procedures/<nome>.sql e começa com
 * "ALTER PROCEDURE dbo.<nome>": cria vazia se não existe e depois aplica o arquivo (funciona em qualquer versão).
 */
class ProceduresBanco
{
    /** Instala ou atualiza uma procedure. Devolve false se o banco não é SQL Server. */
    public static function instalar(string $nome): bool
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            return false;
        }
        if (! preg_match('/^[A-Za-z0-9_]+$/', $nome)) {
            throw new \InvalidArgumentException("Nome de procedure inválido: {$nome}");
        }
        DB::unprepared("IF OBJECT_ID('dbo.{$nome}', 'P') IS NULL EXEC('CREATE PROCEDURE dbo.{$nome} AS RETURN 0')");
        DB::unprepared(file_get_contents(database_path("procedures/{$nome}.sql")));

        return true;
    }

    /** @return list<string> nomes das procedures em database/procedures */
    public static function todas(): array
    {
        return array_map(fn ($f) => basename($f, '.sql'), glob(database_path('procedures/*.sql')) ?: []);
    }
}
