<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventário de TI (pedido do usuário em 25/11/2026): atributos livres dos itens (nome + valor, ex.: IP, RAM, Office)
 * e as duas TESTEMUNHAS do contrato de comodato, que assinam no portal como o comodatário.
 * Só ACRESCENTA tabelas novas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ti_item_atributos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('item_id')->constrained('ti_itens');
            $t->string('nome', 60);
            $t->string('valor', 255)->nullable();
            $t->integer('ordem')->default(0);
            $t->dateTime('criado_em')->nullable();
            $t->dateTime('atualizado_em')->nullable();
            $t->index(['item_id', 'ordem']);
            $t->index('nome');
        });

        Schema::create('ti_termo_testemunhas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('termo_id')->constrained('ti_termos');
            $t->tinyInteger('ordem');                                              // 1 ou 2
            $t->foreignId('usuario_id')->constrained('usuarios');
            $t->string('nome', 120)->nullable();                                   // como assinou
            $t->string('rg', 30)->nullable();
            $t->longText('imagem_png')->nullable();                                // assinatura, PNG em base64
            $t->dateTime('assinado_em')->nullable();
            $t->foreignId('registrado_por_id')->nullable()->constrained('usuarios');
            $t->boolean('presencial')->default(false);
            $t->string('assinado_ip', 45)->nullable();
            $t->foreignId('definido_por_id')->nullable()->constrained('usuarios');
            $t->dateTime('criado_em')->nullable();
            $t->unique(['termo_id', 'ordem']);
            $t->index(['usuario_id', 'assinado_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ti_termo_testemunhas');
        Schema::dropIfExists('ti_item_atributos');
    }
};
