<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventário de TI (pedido do usuário em 25/11/2026): DESCRIÇÕES PADRÃO dos itens, cadastradas para padronizar (ex.:
 * "Notebook Dell Latitude 5440"); o item escolhe uma da lista (`descricao_id`) e guarda o texto em `descricao`.
 * Só ACRESCENTA: tabela nova e a coluna vazia descricao_id (os itens existentes continuam com a descrição deles).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ti_descricoes', function (Blueprint $t) {
            $t->id();
            $t->string('unidade', 20)->default('tecnologia');
            $t->foreignId('tipo_id')->nullable()->constrained('ti_tipos'); // vazio = serve para qualquer tipo
            $t->string('descricao', 150);
            $t->boolean('ativo')->default(true);
            $t->dateTime('criado_em')->nullable();
            $t->foreignId('criado_por_id')->nullable()->constrained('usuarios');
            $t->dateTime('atualizado_em')->nullable();
            $t->foreignId('atualizado_por_id')->nullable()->constrained('usuarios');
            $t->index(['ativo', 'tipo_id']);
        });

        if (! Schema::hasColumn('ti_itens', 'descricao_id')) {
            Schema::table('ti_itens', function (Blueprint $t) {
                $t->foreignId('descricao_id')->nullable()->constrained('ti_descricoes');
                $t->index('descricao_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('ti_itens', function (Blueprint $t) {
            $t->dropForeign(['descricao_id']);
            $t->dropIndex(['descricao_id']);
            $t->dropColumn('descricao_id');
        });
        Schema::dropIfExists('ti_descricoes');
    }
};
