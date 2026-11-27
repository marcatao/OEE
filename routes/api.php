<?php

use App\Http\Controllers\Aplicativo\ApiAplicativoController;
use App\Http\Controllers\Aplicativo\MaquinasAplicativoController;
use App\Http\Controllers\Aplicativo\OrdensServicoAplicativoController as OsApp;
use App\Http\Controllers\Aplicativo\PerdasAplicativoController;
use App\Http\Controllers\IntegradorIotController;
use Illuminate\Support\Facades\Route;

/*
 * API do aplicativo Android (app-movel/). Prefixo /api (ex.: https://www.alumbra.com.br/oee/api/app/eu).
 * Tudo, menos a troca do código de login, exige o token do app (middleware "aplicativo"), e cada consulta usa as
 * mesmas permissões e escopos de unidade das telas do portal.
 */
Route::prefix('app')->name('api.app.')->group(function () {
    Route::post('token', [ApiAplicativoController::class, 'token'])->middleware('throttle:20,1')->name('token');

    // Limite por usuário (depois do token): o app consulta a cada poucos segundos.
    Route::middleware(['aplicativo', 'throttle:300,1'])->group(function () {
        Route::get('eu', [ApiAplicativoController::class, 'eu'])->name('eu');
        Route::post('sair', [ApiAplicativoController::class, 'sair'])->name('sair');
        // Atualização do app pelo portal (qualquer usuário logado no app): versão mais nova e o APK dela.
        Route::get('versao', [ApiAplicativoController::class, 'versao'])->name('versao');
        Route::get('versao/apk', [ApiAplicativoController::class, 'apk'])->middleware('throttle:10,1')->name('versao.apk');

        // Função "Máquinas": só quem tem o perfil OPERADOR no login (e a permissão de apontamento real).
        Route::middleware(['perfil:operador', 'permissao:apontamentos'])->group(function () {
            Route::get('maquinas', [MaquinasAplicativoController::class, 'index'])->name('maquinas');
            Route::get('maquinas/{maquina}', [MaquinasAplicativoController::class, 'show'])->whereNumber('maquina')->name('maquinas.show');
            Route::get('ops', [MaquinasAplicativoController::class, 'ops'])->name('ops');
            Route::post('maquinas/{maquina}/op', [MaquinasAplicativoController::class, 'op'])->whereNumber('maquina')->name('maquinas.op');
            Route::post('maquinas/{maquina}/dividir-parada', [MaquinasAplicativoController::class, 'dividirParada'])->whereNumber('maquina')->name('maquinas.dividir-parada');
            Route::post('apontamentos/{apontamento}/motivo', [MaquinasAplicativoController::class, 'motivo'])->whereNumber('apontamento')->name('apontamentos.motivo');
            Route::get('apontamentos/{apontamento}', [MaquinasAplicativoController::class, 'apontamento'])->whereNumber('apontamento')->name('apontamentos.show');
            Route::post('apontamentos/{apontamento}/op', [MaquinasAplicativoController::class, 'opDoApontamento'])->whereNumber('apontamento')->name('apontamentos.op');
            Route::post('ferramentas/{ferramenta}/cavidades', [MaquinasAplicativoController::class, 'cavidades'])->whereNumber('ferramenta')->name('ferramentas.cavidades');
            // Detalhes da OP (kardex, empenhos, engenharia): a mesma permissão da tela da OP no portal.
            Route::get('ops/{ordem}/detalhes', [MaquinasAplicativoController::class, 'detalhesOp'])->whereNumber('ordem')
                ->middleware('permissao:ordens_producao')->name('ops.detalhes');
            // Apontamento de perdas da OP (calculadora pelo PU): a mesma regra e permissão da aba da OP no portal.
            Route::middleware('permissao:perdas_op')->group(function () {
                Route::get('ops/{ordem}/perdas', [PerdasAplicativoController::class, 'index'])->whereNumber('ordem')->name('ops.perdas');
                Route::post('ops/{ordem}/perdas', [PerdasAplicativoController::class, 'store'])->whereNumber('ordem')->name('ops.perdas.store');
                Route::delete('perdas/{perda}', [PerdasAplicativoController::class, 'destroy'])->whereNumber('perda')->name('perdas.destroy');
            });
        });

        // Ordens de serviço: só quem tem um perfil de MANTENEDOR no login (oee.mantenedores_area), mais as mesmas
        // permissões das telas de OS do portal.
        Route::prefix('os')->name('os.')->middleware('perfil:'.implode(',', config('oee.mantenedores_area')))->group(function () {
            Route::middleware('permissao:os_solicitar,os_aprovar,solicitacoes_manutencao,os_almoxarifado')->group(function () {
                Route::get('resumo', [OsApp::class, 'resumo'])->name('resumo');
                Route::get('solicitacoes', [OsApp::class, 'solicitacoes'])->name('solicitacoes');
                Route::get('formulario', [OsApp::class, 'formulario'])->name('formulario');
                Route::post('solicitacoes', [OsApp::class, 'abrir'])->name('abrir');
                Route::get('ordens/{ordem}', [OsApp::class, 'show'])->whereNumber('ordem')->name('show');
            });
            Route::middleware('permissao:os_aprovar')->group(function () {
                Route::post('solicitacoes/{solicitacao}/assumir', [OsApp::class, 'assumir'])->whereNumber('solicitacao')->name('assumir');
                Route::get('alerta', [OsApp::class, 'alerta'])->name('alerta');
                Route::post('solicitacoes/{solicitacao}/recusar', [OsApp::class, 'recusar'])->whereNumber('solicitacao')->name('recusar');
                Route::post('solicitacoes/{solicitacao}/rejeitar', [OsApp::class, 'rejeitar'])->whereNumber('solicitacao')->name('rejeitar');
            });
            Route::middleware('permissao:solicitacoes_manutencao,os_aprovar')->group(function () {
                Route::get('ordens', [OsApp::class, 'ordens'])->name('ordens');
                Route::get('avaliacoes', [OsApp::class, 'avaliacoes'])->name('avaliacoes');
                Route::post('ordens/{ordem}/iniciar', [OsApp::class, 'iniciar'])->whereNumber('ordem')->name('iniciar');
                Route::post('ordens/{ordem}/acao', [OsApp::class, 'acao'])->whereNumber('ordem')->name('acao');
                Route::post('ordens/{ordem}/checklist', [OsApp::class, 'checklist'])->whereNumber('ordem')->name('checklist');
                Route::post('ordens/{ordem}/mantenedores', [OsApp::class, 'adicionarMantenedor'])->whereNumber('ordem')->name('mantenedores');
                Route::post('ordens/{ordem}/avaliar', [OsApp::class, 'avaliar'])->whereNumber('ordem')->name('avaliar');
                Route::post('apontamentos/{apontamento}/encerrar', [OsApp::class, 'encerrar'])->whereNumber('apontamento')->name('encerrar');
            });
        });
    });
});

// Integrador MQTT (marcatao/MQTT): depois de gravar os apontamentos reais do IoT no banco, pede ao portal a SS
// automática dos apontamentos novos ou com a legenda trocada. Token em IOT_TOKEN (middleware "integrador").
Route::post('iot/apontamentos/processar', [IntegradorIotController::class, 'processar'])
    // limite antes do token: tentativas com token errado também contam
    ->middleware(['throttle:120,1', 'integrador'])->name('api.iot.processar');
