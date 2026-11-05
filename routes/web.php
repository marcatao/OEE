<?php

use App\Http\Controllers\Admin\BancoController;
use App\Http\Controllers\Admin\UsuarioController;
use App\Http\Controllers\AutenticacaoController;
use App\Http\Controllers\PortalController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PortalController::class, 'inicio'])->name('inicio');

Route::controller(AutenticacaoController::class)->prefix('auth')->group(function () {
    Route::get('login', 'login')->name('login');
    Route::get('callback', 'callback')->name('auth.callback');
    Route::post('logout', 'logout')->name('logout')->middleware('auth');
});

Route::middleware('auth')->group(function () {
    Route::match(['get', 'post'], 'calculadora', [PortalController::class, 'calculadora'])->name('calculadora');
    Route::get('perfil', [PortalController::class, 'perfil'])->name('perfil');

    Route::middleware('perfil:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('banco', BancoController::class)->name('banco');
        Route::get('usuarios', [UsuarioController::class, 'index'])->name('usuarios');
        Route::put('usuarios/{usuario}', [UsuarioController::class, 'update'])->name('usuarios.update');
    });
});
