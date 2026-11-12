<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Solicitação de material: as prioridades passam a ser os códigos da empresa (AD, AL, DG, IO, IP, MP, NO, PD, PF, PU,
 * RI, SE — lista em config('oee.prioridades_material')). No SQL Server a regra CHECK que só aceitava alta/media/baixa é
 * retirada (a lista fica na configuração e é conferida pelo portal). Só muda a ESTRUTURA: nenhum registro é alterado —
 * os itens antigos continuam com alta/media/baixa e aparecem como "(antiga)".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement("IF OBJECT_ID('ck_solicitacoes_material_itens_prioridade', 'C') IS NOT NULL ALTER TABLE solicitacoes_material_itens DROP CONSTRAINT ck_solicitacoes_material_itens_prioridade");
        }
    }

    public function down(): void
    {
        // Sem volta automática: itens novos já podem ter os códigos (AD, MP...), que a regra antiga recusaria.
    }
};
