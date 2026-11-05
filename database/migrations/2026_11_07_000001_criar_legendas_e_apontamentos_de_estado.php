<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Timeline das máquinas.
 * - legendas: estados possíveis de uma máquina (Produzindo, Parada, Desligada, Setup...), com cor e tipo.
 * - apontamentos_estado: em que estado (legenda) cada máquina ficou, de que horário até que horário.
 * Datas em UTC. Legendas são desativadas, nunca excluídas. Já carrega as legendas padrão de produção.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legendas', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 12)->unique();   // sigla exibida na barra (ex.: DT, OFF)
            $table->string('descricao', 120);
            $table->string('tipo', 20);               // produzindo | parada | planejada | desligada
            $table->string('cor', 7);                 // #rrggbb
            $table->integer('ordem')->default(0);
            $table->boolean('ativo')->default(true);
            $table->dateTime('criado_em')->nullable();
            $table->foreignId('criado_por_id')->nullable()->constrained('usuarios');
            $table->dateTime('atualizado_em')->nullable();
            $table->foreignId('atualizado_por_id')->nullable()->constrained('usuarios');
        });

        Schema::create('apontamentos_estado', function (Blueprint $table) {
            $table->id();
            $table->foreignId('maquina_id')->constrained('maquinas');
            $table->foreignId('legenda_id')->constrained('legendas');
            $table->dateTime('inicio');
            $table->dateTime('fim')->nullable();          // vazio = ainda neste estado
            $table->string('referencia', 40)->nullable(); // ordem de produção, lote ou produto (aparece na barra)
            $table->string('observacao', 500)->nullable();
            $table->dateTime('criado_em')->nullable();
            $table->foreignId('criado_por_id')->nullable()->constrained('usuarios');
            $table->dateTime('atualizado_em')->nullable();
            $table->foreignId('atualizado_por_id')->nullable()->constrained('usuarios');
            $table->index(['maquina_id', 'inicio']);
        });

        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement("ALTER TABLE legendas ADD CONSTRAINT ck_legendas_tipo CHECK (tipo IN ('produzindo', 'parada', 'planejada', 'desligada'))");
            DB::statement('ALTER TABLE apontamentos_estado ADD CONSTRAINT ck_apontamentos_estado_periodo CHECK (fim IS NULL OR fim > inicio)');
        }

        $agora = now();
        $padrao = [
            // código, descrição, tipo, cor
            ['PROD', 'Produzindo', 'produzindo', '#43a047'],
            ['DT', 'Parada não planejada', 'parada', '#e53935'],
            ['SETUP', 'Setup / troca de produto', 'parada', '#1e88e5'],
            ['WAIT', 'Aguardando material', 'parada', '#fb8c00'],
            ['MANUT', 'Manutenção corretiva', 'parada', '#8e24aa'],
            ['QUAL', 'Parada por qualidade', 'parada', '#00897b'],
            ['CALIB', 'Calibração', 'planejada', '#6d8f7a'],
            ['PREV', 'Manutenção preventiva', 'planejada', '#5e35b1'],
            ['REF', 'Refeição / intervalo', 'planejada', '#fdd835'],
            ['OFF', 'Desligada / sem programação', 'desligada', '#9e9e9e'],
        ];
        foreach ($padrao as $i => [$codigo, $descricao, $tipo, $cor]) {
            DB::table('legendas')->insert([
                'codigo' => $codigo, 'descricao' => $descricao, 'tipo' => $tipo, 'cor' => $cor,
                'ordem' => ($i + 1) * 10, 'ativo' => true, 'criado_em' => $agora, 'atualizado_em' => $agora,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('apontamentos_estado');
        Schema::dropIfExists('legendas');
    }
};
