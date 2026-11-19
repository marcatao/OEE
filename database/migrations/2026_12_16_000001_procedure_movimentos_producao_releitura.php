<?php

use App\Support\ProceduresBanco;
use Illuminate\Database\Migrations\Migration;

/*
 * Reinstala dbo.sp_sincronizar_movimentos_producao: passa a reler também o último mês fechado (a partir do PENÚLTIMO
 * fechamento da SB9) e para com erro se a leitura vier vazia. Só a procedure (SQL Server); nenhum registro muda aqui.
 */
return new class extends Migration
{
    public function up(): void
    {
        ProceduresBanco::instalar('sp_sincronizar_movimentos_producao');
    }

    public function down(): void
    {
        // A versão anterior da procedure está no histórico do Git; nada a desfazer aqui.
    }
};
