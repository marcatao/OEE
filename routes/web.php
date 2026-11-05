<?php

use App\Http\Controllers\Admin\BancoController;
use App\Http\Controllers\AutenticacaoController;
use App\Http\Controllers\Cadastros\AcessoController;
use App\Http\Controllers\Cadastros\GrupoController;
use App\Http\Controllers\Cadastros\UsuarioController;
use App\Http\Controllers\PortalController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PortalController::class, 'inicio'])->name('inicio');

Route::controller(AutenticacaoController::class)->prefix('auth')->group(function () {
    Route::get('login', 'login')->name('login');
    Route::get('callback', 'callback')->name('auth.callback');
    Route::post('logout', 'logout')->name('logout')->middleware('auth');
});

Route::middleware('auth')->group(function () {
    Route::get('aguardando-liberacao', [PortalController::class, 'aguardando'])->name('aguardando');
    Route::get('perfil', [PortalController::class, 'perfil'])->name('perfil');

    // Produção (cada tela exige a permissão dada pelos grupos; admin pode tudo)
    Route::match(['get', 'post'], 'calculadora', [PortalController::class, 'calculadora'])
        ->middleware('permissao:calculadora')->name('calculadora');

    // Cadastros > Logins (só administradores)
    Route::middleware('perfil:admin')->prefix('cadastros/logins')->name('cadastros.')->group(function () {
        Route::get('usuarios', [UsuarioController::class, 'index'])->name('usuarios.index');
        Route::get('usuarios/{usuario}/editar', [UsuarioController::class, 'edit'])->name('usuarios.edit');
        Route::put('usuarios/{usuario}', [UsuarioController::class, 'update'])->name('usuarios.update');

        Route::get('grupos', [GrupoController::class, 'index'])->name('grupos.index');
        Route::get('grupos/novo', [GrupoController::class, 'create'])->name('grupos.create');
        Route::post('grupos', [GrupoController::class, 'store'])->name('grupos.store');
        Route::get('grupos/{grupo}/editar', [GrupoController::class, 'edit'])->name('grupos.edit');
        Route::put('grupos/{grupo}', [GrupoController::class, 'update'])->name('grupos.update');

        Route::get('acessos', AcessoController::class)->name('acessos');
    });

    // Administração (só administradores)
    Route::middleware('perfil:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('banco', BancoController::class)->name('banco');
    });
});
