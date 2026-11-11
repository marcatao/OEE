<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Ordens de serviço:
 * - os_mantenedor_areas: um mantenedor atende VÁRIAS áreas executantes (a coluna antiga os_mantenedores.area_executante_id
 *   continua como a área principal: a primeira escolhida);
 * - os_ordem_mantenedores: uma OS pode ter VÁRIOS mantenedores atribuídos (ordens_servico.responsavel_id continua como o
 *   principal: o primeiro escolhido).
 * Só CRIA tabelas novas: nenhum registro existente é apagado ou alterado. Sem cascata (padrão do módulo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('os_mantenedor_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mantenedor_id')->constrained('os_mantenedores');
            $table->foreignId('area_executante_id')->constrained('os_areas_executantes');
            $table->dateTime('criado_em')->nullable();
            $table->foreignId('criado_por_id')->nullable()->constrained('usuarios');
            $table->unique(['mantenedor_id', 'area_executante_id']);
            $table->index('area_executante_id');
        });

        Schema::create('os_ordem_mantenedores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ordem_servico_id')->constrained('ordens_servico');
            $table->foreignId('mantenedor_id')->constrained('os_mantenedores');
            $table->dateTime('criado_em')->nullable();
            $table->foreignId('criado_por_id')->nullable()->constrained('usuarios');
            $table->unique(['ordem_servico_id', 'mantenedor_id']);
            $table->index(['mantenedor_id', 'ordem_servico_id']); // "minhas OS" do mantenedor
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('os_ordem_mantenedores');
        Schema::dropIfExists('os_mantenedor_areas');
    }
};
