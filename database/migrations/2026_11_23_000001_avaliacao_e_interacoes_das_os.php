<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Ordens de serviço:
 * - ordens_servico.avaliacao: avaliação do solicitante quando a OS é concluída (excelente | boa | ruim). Ruim reabre a
 *   OS (motivo em aceite_observacao e no histórico). Coluna nova e vazia: as OS antigas ficam sem avaliação.
 * - os_interacoes: conversa da OS (comentários de todos os envolvidos, estilo chat) e os_interacao_anexos: arquivos
 *   (documentos, prints) de cada comentário, guardados em storage/app/private/os-anexos (fora da pasta pública).
 * Só ACRESCENTA: nenhum registro existente é alterado. Sem cascata (padrão do módulo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordens_servico', function (Blueprint $table) {
            $table->string('avaliacao', 10)->nullable(); // excelente | boa | ruim
        });

        Schema::create('os_interacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ordem_servico_id')->constrained('ordens_servico');
            $table->foreignId('usuario_id')->constrained('usuarios');
            $table->string('texto', 4000)->nullable();
            $table->dateTime('criado_em')->nullable();
            $table->index(['ordem_servico_id', 'id']); // conversa da OS em ordem
        });

        Schema::create('os_interacao_anexos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('interacao_id')->constrained('os_interacoes');
            $table->string('nome', 200);          // nome original do arquivo
            $table->string('caminho', 300);       // caminho no disco "local" (privado)
            $table->string('mime', 120)->nullable();
            $table->integer('tamanho')->default(0); // bytes
            $table->dateTime('criado_em')->nullable();
            $table->index('interacao_id');
        });

        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement("ALTER TABLE ordens_servico ADD CONSTRAINT ck_ordens_servico_avaliacao CHECK (avaliacao IS NULL OR avaliacao IN ('excelente', 'boa', 'ruim'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('os_interacao_anexos');
        Schema::dropIfExists('os_interacoes');
        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement('ALTER TABLE ordens_servico DROP CONSTRAINT ck_ordens_servico_avaliacao');
        }
        Schema::table('ordens_servico', fn (Blueprint $table) => $table->dropColumn('avaliacao'));
    }
};
