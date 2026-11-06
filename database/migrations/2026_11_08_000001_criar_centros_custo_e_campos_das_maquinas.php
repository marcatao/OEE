<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Centros de custo (setor) no mesmo formato da tabela CTT do Protheus, para os dados virem de lá:
 * colunas com os mesmos nomes e tipos caractere (datas como 'AAAAMMDD'), D_E_L_E_T_ e R_E_C_N_O_ de origem.
 * A máquina guarda só o CÓDIGO do centro de custo (caractere, como CTT_CUSTO).
 *
 * Máquinas ganham: centro de custo, limites de micro e macro parada (segundos) e três canais IoT
 * (ID do dispositivo, canal e tipo do canal: produção ou refugo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('centros_custo', function (Blueprint $table) {
            $table->id();
            $table->char('ctt_filial', 8)->default('');      // CTT_FILIAL
            $table->string('ctt_custo', 20);                 // CTT_CUSTO  (código do centro de custo)
            $table->string('ctt_desc01', 40);                // CTT_DESC01 (descrição)
            $table->char('ctt_classe', 1)->default('2');     // CTT_CLASSE 1=Sintético 2=Analítico
            $table->char('ctt_bloq', 1)->default('2');       // CTT_BLOQ   1=Bloqueado 2=Não bloqueado
            $table->string('ctt_ccsup', 20)->default('');    // CTT_CCSUP  (centro de custo superior)
            $table->char('ctt_dtexis', 8)->default('');      // CTT_DTEXIS (início da existência, AAAAMMDD)
            $table->char('ctt_dtexsf', 8)->default('');      // CTT_DTEXSF (fim da existência, AAAAMMDD)
            $table->char('d_e_l_e_t_', 1)->default(' ');     // D_E_L_E_T_ ('*' = excluído no Protheus)
            $table->integer('r_e_c_n_o_')->nullable();       // R_E_C_N_O_ de origem (vazio = cadastrado no portal)
            $table->dateTime('criado_em')->nullable();
            $table->foreignId('criado_por_id')->nullable()->constrained('usuarios');
            $table->dateTime('atualizado_em')->nullable();
            $table->foreignId('atualizado_por_id')->nullable()->constrained('usuarios');
            $table->unique(['ctt_filial', 'ctt_custo']);
            $table->index('ctt_custo');
        });

        Schema::table('maquinas', function (Blueprint $table) {
            $table->string('centro_custo', 20)->nullable();   // só o código (CTT_CUSTO)
            $table->integer('micro_parada_s')->nullable();    // parada até este tempo = micro parada (perda de performance)
            $table->integer('macro_parada_s')->nullable();    // parada a partir deste tempo = macro parada (perda de disponibilidade)
            foreach ([1, 2, 3] as $n) {
                $table->string("iot{$n}_id", 100)->nullable();     // ID do dispositivo IoT (ex.: Teste_Alumbra)
                $table->string("iot{$n}_canal", 100)->nullable();  // canal (ex.: ch1_value)
                $table->string("iot{$n}_tipo", 20)->nullable();    // producao | refugo
            }
            $table->index('centro_custo');
        });

        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement("ALTER TABLE centros_custo ADD CONSTRAINT ck_centros_custo_classe CHECK (ctt_classe IN ('1', '2'))");
            DB::statement("ALTER TABLE centros_custo ADD CONSTRAINT ck_centros_custo_bloq CHECK (ctt_bloq IN ('1', '2'))");
            DB::statement('ALTER TABLE maquinas ADD CONSTRAINT ck_maquinas_paradas CHECK (micro_parada_s IS NULL OR macro_parada_s IS NULL OR macro_parada_s > micro_parada_s)');
            foreach ([1, 2, 3] as $n) {
                DB::statement("ALTER TABLE maquinas ADD CONSTRAINT ck_maquinas_iot{$n}_tipo CHECK (iot{$n}_tipo IS NULL OR iot{$n}_tipo IN ('producao', 'refugo'))");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement('ALTER TABLE maquinas DROP CONSTRAINT ck_maquinas_paradas');
            foreach ([1, 2, 3] as $n) {
                DB::statement("ALTER TABLE maquinas DROP CONSTRAINT ck_maquinas_iot{$n}_tipo");
            }
        }
        Schema::table('maquinas', function (Blueprint $table) {
            $table->dropIndex(['centro_custo']);
            $colunas = ['centro_custo', 'micro_parada_s', 'macro_parada_s'];
            foreach ([1, 2, 3] as $n) {
                array_push($colunas, "iot{$n}_id", "iot{$n}_canal", "iot{$n}_tipo");
            }
            $table->dropColumn($colunas);
        });
        Schema::dropIfExists('centros_custo');
    }
};
