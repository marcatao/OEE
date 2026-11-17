<?php

use App\Http\Controllers\Aplicativo\ApiAplicativoController;
use App\Http\Controllers\Aplicativo\MaquinasAplicativoController;
use Illuminate\Support\Facades\Route;

/*
 * API do aplicativo Android (app-movel/). Prefixo /api (ex.: https://www.alumbra.com.br/oee/api/app/eu).
 * Tudo, menos a troca do código de login, exige o token do app (middleware "aplicativo"), e cada consulta usa as
 * mesmas permissões e escopos de unidade das telas do portal.
 */
Route::prefix('app')->name('api.app.')->group(function () {
    Route::post('token', [ApiAplicativoController::class, 'token'])->middleware('throttle:20,1')->name('token');

    Route::middleware('aplicativo')->group(function () {
        Route::get('eu', [ApiAplicativoController::class, 'eu'])->name('eu');
        Route::post('sair', [ApiAplicativoController::class, 'sair'])->name('sair');

        // Função "Máquinas" (operador): consultar com apontamentos ou timeline; alterar só com apontamentos.
        Route::middleware('permissao:apontamentos,timeline')->group(function () {
            Route::get('maquinas', [MaquinasAplicativoController::class, 'index'])->name('maquinas');
            Route::get('maquinas/{maquina}', [MaquinasAplicativoController::class, 'show'])->whereNumber('maquina')->name('maquinas.show');
        });
        Route::middleware('permissao:apontamentos')->group(function () {
            Route::get('ops', [MaquinasAplicativoController::class, 'ops'])->name('ops');
            Route::post('maquinas/{maquina}/op', [MaquinasAplicativoController::class, 'op'])->whereNumber('maquina')->name('maquinas.op');
            Route::post('apontamentos/{apontamento}/motivo', [MaquinasAplicativoController::class, 'motivo'])->whereNumber('apontamento')->name('apontamentos.motivo');
            Route::post('ferramentas/{ferramenta}/cavidades', [MaquinasAplicativoController::class, 'cavidades'])->whereNumber('ferramenta')->name('ferramentas.cavidades');
        });
    });
});
