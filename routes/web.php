<?php

use App\Http\Controllers\Admin\BancoController;
use App\Http\Controllers\Admin\CentroCustoController;
use App\Http\Controllers\Admin\DispositivoIotController;
use App\Http\Controllers\Admin\EstruturaController;
use App\Http\Controllers\Admin\LegendaController;
use App\Http\Controllers\Admin\MaquinaController;
use App\Http\Controllers\ApontamentoEstadoController;
use App\Http\Controllers\AutenticacaoController;
use App\Http\Controllers\Cadastros\AcessoController;
use App\Http\Controllers\Cadastros\GrupoController;
use App\Http\Controllers\Cadastros\UsuarioController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PerfilController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\TimelineController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PortalController::class, 'inicio'])->name('inicio');

Route::controller(AutenticacaoController::class)->prefix('auth')->group(function () {
    Route::get('login', 'login')->name('login');
    Route::get('callback', 'callback')->name('auth.callback');
    Route::post('logout', 'logout')->name('logout')->middleware('auth');
});

Route::middleware('auth')->group(function () {
    Route::get('aguardando-liberacao', [PortalController::class, 'aguardando'])->name('aguardando');

    // Meu perfil (qualquer usuário logado, mesmo aguardando liberação)
    Route::get('perfil', [PerfilController::class, 'show'])->name('perfil');
    Route::put('perfil', [PerfilController::class, 'update'])->name('perfil.update');
    Route::post('perfil/entra', [PerfilController::class, 'sincronizar'])->name('perfil.entra');
    Route::get('usuarios/{usuario}/foto', [PerfilController::class, 'foto'])->name('usuarios.foto');

    // Produção (cada tela exige a permissão dada pelos grupos; admin pode tudo)
    Route::middleware('permissao:dashboard_maquina')->prefix('producao/dashboard')->name('dashboard.')->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('maquinas');
        Route::get('{maquina}', [DashboardController::class, 'maquina'])->name('maquina');
    });
    Route::get('dashboards/timeline', TimelineController::class)->middleware('permissao:timeline')->name('timeline');
    Route::middleware('permissao:apontamentos')->prefix('producao/apontamentos')->name('apontamentos.')->group(function () {
        Route::get('/', [ApontamentoEstadoController::class, 'index'])->name('index');
        Route::get('novo', [ApontamentoEstadoController::class, 'create'])->name('create');
        Route::post('/', [ApontamentoEstadoController::class, 'store'])->name('store');
        Route::get('{apontamento}/editar', [ApontamentoEstadoController::class, 'edit'])->name('edit');
        Route::put('{apontamento}', [ApontamentoEstadoController::class, 'update'])->name('update');
        Route::delete('{apontamento}', [ApontamentoEstadoController::class, 'destroy'])->name('destroy');
    });
    Route::match(['get', 'post'], 'calculadora', [PortalController::class, 'calculadora'])
        ->middleware('permissao:calculadora')->name('calculadora');

    // Administração > Logins (só administradores; as URLs antigas "cadastros/logins" foram mantidas)
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

        Route::get('maquinas', [MaquinaController::class, 'index'])->name('maquinas.index');
        Route::get('maquinas/nova', [MaquinaController::class, 'create'])->name('maquinas.create');
        Route::post('maquinas', [MaquinaController::class, 'store'])->name('maquinas.store');
        Route::get('maquinas/{maquina}/editar', [MaquinaController::class, 'edit'])->name('maquinas.edit');
        Route::put('maquinas/{maquina}', [MaquinaController::class, 'update'])->name('maquinas.update');

        Route::get('centros-de-custo', [CentroCustoController::class, 'index'])->name('centros-custo.index');
        Route::get('centros-de-custo/novo', [CentroCustoController::class, 'create'])->name('centros-custo.create');
        Route::post('centros-de-custo', [CentroCustoController::class, 'store'])->name('centros-custo.store');
        Route::get('centros-de-custo/{centro}/editar', [CentroCustoController::class, 'edit'])->name('centros-custo.edit');
        Route::put('centros-de-custo/{centro}', [CentroCustoController::class, 'update'])->name('centros-custo.update');

        Route::get('plantas-e-linhas', [EstruturaController::class, 'index'])->name('estrutura');
        Route::post('plantas', [EstruturaController::class, 'salvarPlanta'])->name('estrutura.plantas.store');
        Route::put('plantas/{planta}', [EstruturaController::class, 'atualizarPlanta'])->name('estrutura.plantas.update');
        Route::post('linhas', [EstruturaController::class, 'salvarLinha'])->name('estrutura.linhas.store');
        Route::put('linhas/{linha}', [EstruturaController::class, 'atualizarLinha'])->name('estrutura.linhas.update');

        Route::get('legendas', [LegendaController::class, 'index'])->name('legendas.index');
        Route::get('legendas/nova', [LegendaController::class, 'create'])->name('legendas.create');
        Route::post('legendas', [LegendaController::class, 'store'])->name('legendas.store');
        Route::get('legendas/{legenda}/editar', [LegendaController::class, 'edit'])->name('legendas.edit');
        Route::put('legendas/{legenda}', [LegendaController::class, 'update'])->name('legendas.update');

        Route::get('dispositivos-iot', [DispositivoIotController::class, 'index'])->name('iot.index');
        Route::get('dispositivos-iot/novo', [DispositivoIotController::class, 'create'])->name('iot.create');
        Route::post('dispositivos-iot', [DispositivoIotController::class, 'store'])->name('iot.store');
        Route::get('dispositivos-iot/{dispositivo}/editar', [DispositivoIotController::class, 'edit'])->name('iot.edit');
        Route::put('dispositivos-iot/{dispositivo}', [DispositivoIotController::class, 'update'])->name('iot.update');
    });
});
