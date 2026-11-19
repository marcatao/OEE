<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SS vinculada à parada: quando o apontamento de uma legenda que "abre SS" não abre outra porque já existe uma SS/OS
 * automática em aberto da mesma máquina e área, essa SS fica vinculada ao apontamento (a Timeline e o aplicativo
 * mostram o chamado no lugar da meta). Só acrescenta a coluna.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('apontamentos_estado', 'solicitacao_vinculada_id')) {
            Schema::table('apontamentos_estado', function (Blueprint $table) {
                $table->foreignId('solicitacao_vinculada_id')->nullable()->constrained('solicitacoes_servico'); // sem cascata (SQL Server: evita ciclo com solicitacoes_servico.apontamento_estado_id)
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('apontamentos_estado', 'solicitacao_vinculada_id')) {
            Schema::table('apontamentos_estado', function (Blueprint $table) {
                $table->dropConstrainedForeignId('solicitacao_vinculada_id');
            });
        }
    }
};
