<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cria no banco dos testes (SQLite, conexão compras.conexao) as tabelas muro ZP1 e ZP2 como o Protheus as cria pelo
 * dicionário do compatibilizador integracao-protheus/pedido-compra/OEEUPDPC.tlpp (campos com o prefixo do alias,
 * datas AAAAMMDD, D_E_L_E_T_ e R_E_C_N_O_).
 */
trait TabelaMuroDeCompras
{
    protected function criarTabelasMuro(): void
    {
        $esquema = Schema::connection(config('compras.conexao'));
        $c = fn (string $campo) => config('compras.alias_pedidos').'_'.$campo;
        $i = fn (string $campo) => config('compras.alias_itens').'_'.$campo;

        $esquema->create(config('compras.tabela_pedidos'), function (Blueprint $t) use ($c) {
            $t->string($c('FILIAL'), 8)->default(' ');
            $t->string($c('IDPORT'), 10)->default(' ');
            $t->string($c('TIPO'), 2)->default(' ');
            $t->string($c('ORIGEM'), 30)->default(' ');
            $t->string($c('FILPED'), 8)->default(' ');
            $t->string($c('CCORIG'), 20)->default(' ');
            $t->string($c('CCDEST'), 20)->default(' ');
            $t->string($c('FORNEC'), 6)->default(' ');
            $t->string($c('LOJA'), 2)->default(' ');
            $t->string($c('COND'), 3)->default(' ');
            $t->string($c('SOLIC'), 60)->default(' ');
            $t->string($c('OBS'), 250)->default(' ');
            $t->string($c('STATUS'), 20)->default(' ');
            $t->string($c('MSG'), 250)->default(' ');
            $t->string($c('NUMPED'), 6)->default(' ');
            foreach (['INT', 'APRO', 'CONC', 'CRIA', 'ATU'] as $quando) {
                $t->string($c('DT'.$quando), 8)->default(' ');
                $t->string($c('HR'.$quando), 8)->default(' ');
            }
            $t->string($c('APROV'), 60)->default(' ');
            $t->decimal($c('VALOR'), 16, 2)->default(0);
            $t->integer($c('TENTAT'))->default(0);
            $t->string('D_E_L_E_T_', 1)->default(' ');
            $t->integer('R_E_C_N_O_')->unique();
            $t->integer('R_E_C_D_E_L_')->default(0);
        });
        $esquema->create(config('compras.tabela_itens'), function (Blueprint $t) use ($i) {
            $t->string($i('FILIAL'), 8)->default(' ');
            $t->string($i('IDPORT'), 10)->default(' ');
            $t->string($i('ITEM'), 4)->default(' ');
            $t->string($i('IDITEM'), 10)->default(' ');
            $t->string($i('PROD'), 15)->default(' ');
            $t->string($i('DESC'), 60)->default(' ');
            $t->string($i('UM'), 2)->default(' ');
            $t->decimal($i('QUANT'), 14, 4)->default(0);
            $t->decimal($i('PRECO'), 16, 4)->default(0);
            $t->string($i('OBS'), 100)->default(' ');
            $t->string($i('C7ITEM'), 4)->default(' ');
            $t->string($i('NUMPC'), 6)->default(' ');
            $t->decimal($i('VUNIT'), 16, 4)->default(0);
            $t->decimal($i('VTOTAL'), 16, 2)->default(0);
            $t->decimal($i('QTDENT'), 14, 4)->default(0);
            $t->string('D_E_L_E_T_', 1)->default(' ');
            $t->integer('R_E_C_N_O_')->unique();
            $t->integer('R_E_C_D_E_L_')->default(0);
        });
    }
}
