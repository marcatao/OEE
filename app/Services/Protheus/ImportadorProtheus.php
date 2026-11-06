<?php

namespace App\Services\Protheus;

/**
 * Importa linhas de uma tabela do Protheus para uma tabela do portal. Cada tabela sincronizada tem o seu
 * importador, registrado em config/protheus.php.
 */
interface ImportadorProtheus
{
    /** @return list<string> colunas lidas da tabela do Protheus */
    public function campos(): array;

    /**
     * @param  iterable<array|object>  $linhas
     * @return array{novos: int, atualizados: int, ignorados: int, excluidos?: int}
     */
    public function importar(iterable $linhas): array;
}
