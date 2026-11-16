<?php

use App\Http\Controllers\Aplicativo\ApiAplicativoController;
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
    });
});
