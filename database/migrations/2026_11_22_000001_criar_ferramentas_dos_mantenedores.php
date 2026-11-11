<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Ordens de serviço › Ferramentas: cadastro das ferramentas da manutenção e com qual mantenedor cada uma está
 * (mantenedor_id vazio = avulsa, no almoxarifado/ferramentaria). Uma ferramenta fica com um mantenedor por vez.
 * Só CRIA a tabela: nenhum registro existente é alterado. Sem cascata (cadastros são desativados, não excluídos).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('os_ferramentas', function (Blueprint $table) {
            $table->id();
            $table->string('unidade', 20)->default('manutencao');   // manutencao | tecnologia | ambos
            $table->string('codigo', 30)->unique();                 // código / patrimônio
            $table->string('descricao', 150);
            $table->string('marca_modelo', 100)->nullable();
            $table->string('numero_serie', 60)->nullable();
            $table->foreignId('mantenedor_id')->nullable()->constrained('os_mantenedores'); // vazio = avulsa
            $table->dateTime('atribuida_em')->nullable();           // desde quando está com o mantenedor
            $table->string('observacao', 500)->nullable();
            $table->boolean('ativo')->default(true);
            $table->dateTime('criado_em')->nullable();
            $table->foreignId('criado_por_id')->nullable()->constrained('usuarios');
            $table->dateTime('atualizado_em')->nullable();
            $table->foreignId('atualizado_por_id')->nullable()->constrained('usuarios');
            $table->index('unidade');
            $table->index(['mantenedor_id', 'ativo']); // ferramentas do mantenedor / avulsas (mantenedor_id nulo)
        });

        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement("ALTER TABLE os_ferramentas ADD CONSTRAINT ck_os_ferramentas_unidade CHECK (unidade IN ('manutencao', 'tecnologia', 'ambos'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('os_ferramentas');
    }
};
