<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Aplicativo Android: avaliação da OS concluída feita pelo SOLICITANTE no celular do mantenedor, com assinatura com o
 * dedo. Cada avaliação assinada guarda a imagem (PNG em base64), quem assinou (o solicitante da OS), quem registrou (o
 * usuário logado no app) e a nota. A avaliação em si continua em ordens_servico (avaliacao, aceite_*) e os_historico.
 * Só ACRESCENTA uma tabela: nenhum registro existente é alterado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('os_assinaturas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ordem_servico_id')->constrained('ordens_servico');
            $table->foreignId('assinado_por_id')->nullable()->constrained('usuarios');    // solicitante da OS
            $table->foreignId('registrado_por_id')->nullable()->constrained('usuarios');  // quem estava logado no app
            $table->string('nome_assinante', 120)->nullable();
            $table->string('avaliacao', 10);                                               // excelente | boa | ruim
            $table->longText('imagem_png');                                                // assinatura, PNG em base64
            $table->dateTime('criado_em')->nullable();
            $table->index('ordem_servico_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('os_assinaturas');
    }
};
