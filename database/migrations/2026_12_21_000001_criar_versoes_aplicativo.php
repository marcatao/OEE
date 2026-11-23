<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versões do aplicativo Android publicadas no portal (Segurança › Aplicativo): o app consulta a mais nova, baixa o APK
 * pelo portal e pede a instalação. O arquivo fica em storage (fora da pasta pública). Só CRIA a tabela.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('versoes_aplicativo')) {
            return;
        }
        Schema::create('versoes_aplicativo', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('codigo')->unique();       // versionCode do APK (build)
            $t->string('nome', 40);                         // versionName (ex.: 0.13.0)
            $t->string('arquivo', 255);                     // caminho no disco "local" (storage/app/private)
            $t->unsignedBigInteger('tamanho');              // bytes
            $t->string('sha256', 64);                       // o app confere o download
            $t->boolean('obrigatoria')->default(false);     // abaixo desta versão o app exige atualizar
            $t->boolean('ativo')->default(true);            // desativada = o app não oferece (cadastros não se excluem)
            $t->string('observacao', 1000)->nullable();     // o que mudou (aparece no app)
            $t->string('unidade', 20)->default('ambos');    // vale para todas as unidades
            $t->dateTime('criado_em')->nullable();
            $t->foreignId('criado_por_id')->nullable()->constrained('usuarios');
            $t->dateTime('atualizado_em')->nullable();
            $t->foreignId('atualizado_por_id')->nullable()->constrained('usuarios');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('versoes_aplicativo');
    }
};
