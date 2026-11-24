<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mão de obra (pedido do usuário em 24/11/2026): produtos do TIPO MO da SB1 com o FATOR DE SERVIÇO (0 a 100%) do portal,
 * que entra na meta de produção (App\Services\MetaProducao). Cria a tabela nova maos_de_obra e já a preenche com
 * todos os MO existentes em produtos_protheus com fator 0 (linhas NOVAS numa tabela nova; nada existente é alterado).
 * metas_producao ganha a coluna fator_servico (vazia nos registros antigos = 0%).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('maos_de_obra')) {
            Schema::create('maos_de_obra', function (Blueprint $t) {
                $t->id();
                $t->string('b1_cod', 30)->unique();               // código do produto MO (SB1)
                $t->decimal('fator_servico', 6, 2)->default(0);   // % (0 a 100) somado ao tempo do MO na meta
                $t->string('unidade', 20)->default('manutencao');
                $t->dateTime('criado_em')->nullable();
                $t->foreignId('criado_por_id')->nullable()->constrained('usuarios');
                $t->dateTime('atualizado_em')->nullable();
                $t->foreignId('atualizado_por_id')->nullable()->constrained('usuarios');
            });
            $agora = now();
            DB::table('produtos_protheus')->where('b1_tipo', 'MO')->where('d_e_l_e_t_', '<>', '*')
                ->distinct()->orderBy('b1_cod')->pluck('b1_cod')
                ->map(fn ($c) => rtrim($c))->unique()->chunk(200)
                ->each(fn ($lote) => DB::table('maos_de_obra')->insert($lote->map(fn ($c) => [
                    'b1_cod' => $c, 'fator_servico' => 0, 'unidade' => 'manutencao', 'criado_em' => $agora,
                ])->values()->all()));
        }
        if (! Schema::hasColumn('metas_producao', 'fator_servico')) {
            Schema::table('metas_producao', fn (Blueprint $t) => $t->float('fator_servico')->nullable()); // % usado na meta
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('metas_producao', 'fator_servico')) {
            Schema::table('metas_producao', fn (Blueprint $t) => $t->dropColumn('fator_servico'));
        }
        Schema::dropIfExists('maos_de_obra');
    }
};
