<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ferramentas/moldes (SH4, tabela mista): campo do PORTAL "disponível" (a sincronização com o Protheus não mexe).
 * Só acrescenta a coluna; as ferramentas que já existem ficam disponíveis (padrão).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('ferramentas', 'disponivel')) {
            Schema::table('ferramentas', function (Blueprint $table) {
                $table->boolean('disponivel')->default(true);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ferramentas', 'disponivel')) {
            Schema::table('ferramentas', fn (Blueprint $t) => $t->dropColumn('disponivel'));
        }
    }
};
