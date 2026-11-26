<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Controle de logins e acessos (Segurança, pedido do usuário em 26/11/2026). Só ACRESCENTA:
 * - `acessos_paginas`: cada página do portal aberta por um usuário logado (quem, qual tela, quando, IP). Volume: ~1
 *   linha por página aberta; a retenção é `oee.acessos_paginas.dias_retencao` (comando `acessos:limpar-paginas`,
 *   diário, apaga SÓ desta tabela). Índices por data e por usuário + data (painel e filtros).
 * - `usuarios.ultimo_acesso_em`: última página aberta (gravada no máximo a cada 5 min); nasce vazia.
 * - índice (usuario_id, data_hora) em `registros_login` (painel e filtro por usuário; o SQL Server não indexa FK).
 * - `usuario_permissoes`: páginas liberadas DIRETO para um usuário (além das dos perfis e dos grupos).
 * Nenhum registro existente é alterado.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('acessos_paginas')) {
            Schema::create('acessos_paginas', function (Blueprint $table) {
                $table->id();
                $table->foreignId('usuario_id')->constrained('usuarios');
                $table->dateTime('data_hora');
                $table->string('rota', 120)->nullable();   // nome da rota (ex.: timeline)
                $table->string('caminho', 300);            // endereço aberto, sem a consulta (?...)
                $table->string('ip', 45)->nullable();
                $table->index('data_hora');
                $table->index(['usuario_id', 'data_hora']);
            });
        }

        if (! Schema::hasTable('usuario_permissoes')) {
            Schema::create('usuario_permissoes', function (Blueprint $table) {
                $table->foreignId('usuario_id')->constrained('usuarios');
                $table->string('permissao', 60);
                $table->dateTime('criado_em')->nullable();
                $table->foreignId('criado_por_id')->nullable()->constrained('usuarios');
                $table->primary(['usuario_id', 'permissao']);
            });
        }

        if (! Schema::hasColumn('usuarios', 'ultimo_acesso_em')) {
            Schema::table('usuarios', fn (Blueprint $t) => $t->dateTime('ultimo_acesso_em')->nullable());
        }

        if (! Schema::hasIndex('registros_login', ['usuario_id', 'data_hora'])) {
            Schema::table('registros_login', fn (Blueprint $t) => $t->index(['usuario_id', 'data_hora']));
        }
    }

    public function down(): void
    {
        Schema::table('registros_login', fn (Blueprint $t) => $t->dropIndex(['usuario_id', 'data_hora']));
        Schema::table('usuarios', fn (Blueprint $t) => $t->dropColumn('ultimo_acesso_em'));
        Schema::dropIfExists('usuario_permissoes');
        Schema::dropIfExists('acessos_paginas');
    }
};
