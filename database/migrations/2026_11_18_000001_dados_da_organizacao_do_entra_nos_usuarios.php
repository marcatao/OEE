<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Dados da organização lidos do Microsoft Entra ID (Graph) a cada login: departamento, local de escritório (que na
 * Alumbra guarda o centro de custo) e gerente responsável. Só leitura no portal: valem sempre os do Entra ID.
 * Só ACRESCENTA colunas: nenhum registro existente é alterado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->string('departamento', 120)->nullable();      // Graph: department
            $table->string('local_escritorio', 120)->nullable();  // Graph: officeLocation (mostrado como "Centro de custo")
            $table->string('gerente_nome', 200)->nullable();      // Graph: /me/manager displayName
            $table->string('gerente_email', 320)->nullable();     // Graph: /me/manager mail (ou userPrincipalName)
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', fn (Blueprint $t) => $t->dropColumn(['departamento', 'local_escritorio', 'gerente_nome', 'gerente_email']));
    }
};
