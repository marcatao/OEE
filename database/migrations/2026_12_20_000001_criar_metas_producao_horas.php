<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meta e quantidade atingida de cada HORA do turno, por máquina e OP (pedido do usuário em 24/11/2026): a meta de cada
 * hora é a da OP que a máquina tinha naquele momento (troca no meio da hora = proporcional) e fica gravada aqui
 * (App\Services\MetaPorHora). Só CRIA a tabela: nenhum registro existente é alterado.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('metas_producao_horas')) {
            return;
        }
        Schema::create('metas_producao_horas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('maquina_id')->constrained('maquinas');
            $t->foreignId('ordem_producao_id')->constrained('ordens_producao');
            $t->date('dia');                               // dia local do turno
            $t->dateTime('hora_inicio');                   // UTC: início da hora do relógio
            $t->float('meta_por_minuto')->nullable();      // meta (pç/min) da OP no trecho (null = OP sem meta)
            $t->integer('segundos')->default(0);           // tempo da hora com essa OP na máquina
            $t->float('meta')->nullable();                 // peças da meta nesse tempo
            $t->integer('pecas')->default(0);              // peças produzidas (IoT) dessa OP na hora
            $t->dateTime('atualizado_em')->nullable();
            $t->unique(['maquina_id', 'hora_inicio', 'ordem_producao_id']);
            $t->index(['dia', 'maquina_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metas_producao_horas');
    }
};
