<?php

use App\Http\Controllers\Admin\AplicativoController;
use App\Http\Controllers\Admin\BancoController;
use App\Http\Controllers\Admin\CalendarioController;
use App\Http\Controllers\Admin\CentroCustoController;
use App\Http\Controllers\Admin\DispositivoIotController;
use App\Http\Controllers\Admin\EstruturaController;
use App\Http\Controllers\Admin\LegendaController;
use App\Http\Controllers\Admin\MaquinaController;
use App\Http\Controllers\Aplicativo\LoginAplicativoController;
use App\Http\Controllers\ApontamentoEstadoController;
use App\Http\Controllers\ApontamentoProducaoController;
use App\Http\Controllers\AssistenteController;
use App\Http\Controllers\AutenticacaoController;
use App\Http\Controllers\Cadastros\AcessoController;
use App\Http\Controllers\Cadastros\GrupoController;
use App\Http\Controllers\Cadastros\UsuarioController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmpenhoOpController;
use App\Http\Controllers\EstruturaProdutoController;
use App\Http\Controllers\FaltaProdutoController;
use App\Http\Controllers\FerramentaController;
use App\Http\Controllers\Manutencao\ApontamentoHorasController;
use App\Http\Controllers\Manutencao\CadastroManutencaoController;
use App\Http\Controllers\Manutencao\InteracaoController;
use App\Http\Controllers\Manutencao\MaterialController;
use App\Http\Controllers\Manutencao\OrdemServicoController;
use App\Http\Controllers\Manutencao\PainelController;
use App\Http\Controllers\Manutencao\PedidoCompraController;
use App\Http\Controllers\Manutencao\PlanoPreventivaController;
use App\Http\Controllers\Manutencao\RelatorioManutencaoController;
use App\Http\Controllers\Manutencao\SolicitacaoController;
use App\Http\Controllers\Manutencao\SolicitacaoMaterialController;
use App\Http\Controllers\MaoDeObraController;
use App\Http\Controllers\OrdemProducaoController;
use App\Http\Controllers\PerfilController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\ProducaoPorHoraController;
use App\Http\Controllers\RoteiroOperacaoController;
use App\Http\Controllers\TimelineController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PortalController::class, 'inicio'])->name('inicio');

// Limite por IP alto o bastante para a troca de turno (a fábrica sai por poucos IPs).
Route::controller(AutenticacaoController::class)->prefix('auth')->middleware('throttle:120,1')->group(function () {
    Route::get('login', 'login')->name('login');
    Route::get('callback', 'callback')->name('auth.callback');
    Route::post('logout', 'logout')->name('logout')->middleware('auth');
});

// Login do aplicativo Android pelo portal (o celular abre no navegador; ver LoginAplicativoController).
Route::get('app/entrar', [LoginAplicativoController::class, 'entrar'])->name('app.entrar');
Route::get('app/entrar/concluir', [LoginAplicativoController::class, 'concluir'])->middleware('auth')->name('app.entrar.concluir');

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
    // Dashboards › Produção por hora: peças por hora do turno, máquina a máquina (com OP, produto e meta).
    Route::middleware('permissao:producao_hora')->group(function () {
        Route::get('dashboards/producao-por-hora', ProducaoPorHoraController::class)->name('producao-hora');
        Route::post('dashboards/producao-por-hora/filtros', [ProducaoPorHoraController::class, 'salvarFiltro'])->name('producao-hora.filtros.salvar');
        Route::delete('dashboards/producao-por-hora/filtros/{filtro}', [ProducaoPorHoraController::class, 'excluirFiltro'])->name('producao-hora.filtros.excluir');
    });
    Route::middleware('permissao:timeline')->group(function () {
        Route::get('dashboards/timeline', TimelineController::class)->name('timeline');
        // Filtros salvos pelo usuário na Timeline (tabela filtros_usuarios)
        Route::post('dashboards/timeline/filtros', [TimelineController::class, 'salvarFiltro'])->name('timeline.filtros.salvar');
        Route::delete('dashboards/timeline/filtros/{filtro}', [TimelineController::class, 'excluirFiltro'])->name('timeline.filtros.excluir');
    });
    Route::middleware('permissao:apontamentos')->prefix('producao/apontamentos')->name('apontamentos.')->group(function () {
        Route::get('/', [ApontamentoEstadoController::class, 'index'])->name('index');
        Route::get('novo', [ApontamentoEstadoController::class, 'create'])->name('create');
        Route::get('proximo-inicio', [ApontamentoEstadoController::class, 'proximoInicio'])->name('proximo-inicio');
        Route::post('/', [ApontamentoEstadoController::class, 'store'])->name('store');
        Route::get('{apontamento}/editar', [ApontamentoEstadoController::class, 'edit'])->name('edit');
        Route::put('{apontamento}', [ApontamentoEstadoController::class, 'update'])->name('update');
        Route::delete('{apontamento}', [ApontamentoEstadoController::class, 'destroy'])->name('destroy');
        // Timeline: informar a OP num apontamento cuja legenda exige OP (JSON)
        Route::post('{apontamento}/informar-op', [ApontamentoEstadoController::class, 'informarOp'])->name('informar-op');
        // Timeline › parada sem motivo (98): troca a legenda daquele apontamento por uma de parada (JSON).
        Route::post('{apontamento}/informar-motivo', [ApontamentoEstadoController::class, 'informarMotivo'])->name('informar-motivo');
        // Timeline › "Trocar OP" da máquina (JSON): no apontamento atual ou num novo apontamento a partir de agora.
        Route::post('maquinas/{maquina}/trocar-op', [ApontamentoEstadoController::class, 'trocarOp'])->whereNumber('maquina')->name('trocar-op');
    });
    Route::get('producao/apontamentos-de-producao', [ApontamentoProducaoController::class, 'index'])
        ->middleware('permissao:apontamentos_producao')->name('apontamentos-producao.index');
    Route::get('producao/empenhos-op', [EmpenhoOpController::class, 'index'])
        ->middleware('permissao:empenhos_op')->name('empenhos-op.index');
    // Falta de produtos (ZB5): lista cruzada com a produção, painéis e filtros salvos.
    Route::middleware('permissao:falta_produtos')->prefix('producao/falta-de-produtos')->name('falta-produtos.')->group(function () {
        Route::get('/', [FaltaProdutoController::class, 'index'])->name('index');
        Route::post('filtros', [FaltaProdutoController::class, 'salvarFiltro'])->name('filtros.salvar');
        Route::delete('filtros/{filtro}', [FaltaProdutoController::class, 'excluirFiltro'])->name('filtros.excluir');
    });
    // Estruturas (SG1) e roteiros (SG2): consulta. Ferramentas (SH4): consulta; administradores editam cavidades ativas.
    Route::get('producao/estruturas', [EstruturaProdutoController::class, 'index'])
        ->middleware('permissao:estruturas')->name('estruturas.index');
    Route::get('producao/roteiros', [RoteiroOperacaoController::class, 'index'])
        ->middleware('permissao:roteiros')->name('roteiros.index');
    Route::middleware('permissao:ferramentas')->prefix('producao/ferramentas')->name('ferramentas.')->group(function () {
        Route::get('/', [FerramentaController::class, 'index'])->name('index');
        Route::middleware('perfil:admin_producao,admin_tecnologia,admin_ferramentaria')->group(function () {
            Route::get('{ferramenta}/editar', [FerramentaController::class, 'edit'])->name('edit');
            Route::put('{ferramenta}', [FerramentaController::class, 'update'])->name('update');
        });
    });
    // Mão de obra (SB1 tipo MO): consulta; administradores editam o fator de serviço (entra na meta de produção).
    Route::middleware('permissao:mao_obra')->prefix('producao/mao-de-obra')->name('mao-de-obra.')->group(function () {
        Route::get('/', [MaoDeObraController::class, 'index'])->name('index');
        Route::put('{maoDeObra}', [MaoDeObraController::class, 'update'])->whereNumber('maoDeObra')
            ->middleware('perfil:admin_producao,admin_tecnologia,admin_ferramentaria')->name('update');
    });
    // Busca de OP dos campos de seleção (apontamento real, cadastro de máquinas): JSON, quem aponta ou administra.
    Route::get('producao/ordens-de-producao/busca', [OrdemProducaoController::class, 'busca'])->name('ordens-producao.busca');
    Route::middleware('permissao:ordens_producao')->prefix('producao/ordens-de-producao')->name('ordens-producao.')->group(function () {
        Route::get('/', [OrdemProducaoController::class, 'index'])->name('index');
        Route::get('{ordem}', [OrdemProducaoController::class, 'show'])->whereNumber('ordem')->name('show'); // kardex da OP
    });
    // Pergunte para Alumbra (assistente de IA da página inicial; JSON, em etapas curtas)
    Route::middleware('permissao:assistente')->prefix('assistente')->name('assistente.')->controller(AssistenteController::class)->group(function () {
        Route::get('conversa', 'conversa')->name('conversa');
        Route::post('perguntas', 'perguntar')->middleware('throttle:10,1')->name('perguntar'); // custo da API da Claude
        Route::post('perguntas/{pergunta}/etapa', 'etapa')->middleware('throttle:60,1')->name('etapa');
    });
    Route::match(['get', 'post'], 'calculadora', [PortalController::class, 'calculadora'])
        ->middleware('permissao:calculadora')->name('calculadora');

    // Ordens de serviço (Manutenção, Tecnologia e Ferramentaria). SS → aprovação → OS → horas, materiais → conclusão →
    // aceite; preventivas (PAMP) e relatórios de horas. Cada um vê a fila da sua unidade; o solicitante vê as suas.
    Route::prefix('ordens-de-servico')->name('os.')->group(function () {
        $todos = 'permissao:os_solicitar,os_aprovar,solicitacoes_manutencao,os_almoxarifado';
        $executa = 'permissao:solicitacoes_manutencao,os_aprovar';

        Route::middleware('permissao:os_material')->prefix('solicitacoes-de-material')->name('materiais-solicitacao.')->group(function () {
            Route::get('/', [SolicitacaoMaterialController::class, 'index'])->name('index');
            Route::get('nova', [SolicitacaoMaterialController::class, 'create'])->name('create');
            Route::post('/', [SolicitacaoMaterialController::class, 'store'])->name('store');
            Route::get('produtos', [SolicitacaoMaterialController::class, 'buscarProdutos'])->name('produtos'); // JSON da busca
            Route::get('{solicitacao}', [SolicitacaoMaterialController::class, 'show'])->whereNumber('solicitacao')->name('show');
            Route::post('{solicitacao}/analisar', [SolicitacaoMaterialController::class, 'analisar'])->whereNumber('solicitacao')->name('analisar');
            Route::post('{solicitacao}/cancelar', [SolicitacaoMaterialController::class, 'cancelar'])->whereNumber('solicitacao')->name('cancelar');
        });
        // Pedido de compra pelo Protheus (tabela muro): só o administrador da unidade (conferido no controlador).
        Route::middleware('perfil:admin_producao,admin_tecnologia,admin_ferramentaria')->prefix('pedidos-de-compra')->name('pedidos-compra.')->group(function () {
            Route::get('produtos', [SolicitacaoMaterialController::class, 'buscarProdutos'])->name('produtos'); // JSON da busca
            Route::post('da-solicitacao/{solicitacao}', [PedidoCompraController::class, 'daSolicitacao'])->whereNumber('solicitacao')->name('da-solicitacao');
            Route::post('da-ordem/{ordem}', [PedidoCompraController::class, 'daOrdem'])->whereNumber('ordem')->name('da-ordem');
            Route::post('{pedido}/cancelar', [PedidoCompraController::class, 'cancelar'])->whereNumber('pedido')->name('cancelar');
            Route::post('{pedido}/reenviar', [PedidoCompraController::class, 'reenviar'])->whereNumber('pedido')->name('reenviar');
        });
        Route::middleware('permissao:os_painel')->group(function () {
            Route::get('painel', [PainelController::class, 'index'])->name('painel');
            Route::post('painel/area-padrao', [PainelController::class, 'salvarPadrao'])->name('painel.padrao');
        });
        Route::middleware($todos)->group(function () {
            Route::get('solicitacoes', [SolicitacaoController::class, 'index'])->name('solicitacoes.index');
            Route::get('solicitacoes/{solicitacao}', [SolicitacaoController::class, 'show'])->whereNumber('solicitacao')->name('solicitacoes.show');
            Route::get('ordens', [OrdemServicoController::class, 'index'])->name('ordens.index');
            Route::get('ordens/{ordem}', [OrdemServicoController::class, 'show'])->whereNumber('ordem')->name('ordens.show');
            Route::get('ordens/{ordem}/imprimir', [OrdemServicoController::class, 'imprimir'])->whereNumber('ordem')->name('ordens.imprimir');
            Route::post('ordens/{ordem}/avaliar', [OrdemServicoController::class, 'avaliar'])->whereNumber('ordem')->name('ordens.avaliar');
            // Interações (conversa da OS, com anexos): todos que veem a OS.
            Route::post('ordens/{ordem}/interacoes', [InteracaoController::class, 'store'])->whereNumber('ordem')->name('interacoes.store');
            Route::get('anexos/{anexo}', [InteracaoController::class, 'anexo'])->whereNumber('anexo')->name('interacoes.anexo');
            // Abrir SS: só com o perfil Solicitante (conferido no controlador). Cancelar/reabrir: autor ou administrador da área.
            Route::get('solicitacoes/nova', [SolicitacaoController::class, 'create'])->name('solicitacoes.create');
            Route::post('solicitacoes', [SolicitacaoController::class, 'store'])->name('solicitacoes.store');
            Route::get('solicitacoes/{solicitacao}/editar', [SolicitacaoController::class, 'edit'])->whereNumber('solicitacao')->name('solicitacoes.edit');
            Route::put('solicitacoes/{solicitacao}', [SolicitacaoController::class, 'update'])->whereNumber('solicitacao')->name('solicitacoes.update');
            Route::post('solicitacoes/{solicitacao}/cancelar', [SolicitacaoController::class, 'cancelar'])->whereNumber('solicitacao')->name('solicitacoes.cancelar');
            Route::post('ordens/{ordem}/acao', [OrdemServicoController::class, 'acao'])->whereNumber('ordem')->name('ordens.acao');
        });
        Route::middleware('permissao:os_aprovar')->group(function () {
            Route::post('solicitacoes/{solicitacao}/aprovar', [SolicitacaoController::class, 'aprovar'])->whereNumber('solicitacao')->name('solicitacoes.aprovar');
            Route::post('solicitacoes/{solicitacao}/rejeitar', [SolicitacaoController::class, 'rejeitar'])->whereNumber('solicitacao')->name('solicitacoes.rejeitar');
            Route::post('ordens/{ordem}/distribuir', [OrdemServicoController::class, 'distribuir'])->whereNumber('ordem')->name('ordens.distribuir');
            Route::delete('apontamentos/{apontamento}', [ApontamentoHorasController::class, 'destroy'])->whereNumber('apontamento')->name('apontamentos.destroy');
        });
        Route::middleware($executa)->group(function () {
            Route::get('minhas', [ApontamentoHorasController::class, 'minhas'])->name('minhas');
            Route::post('ordens/{ordem}/maquina-parada', [OrdemServicoController::class, 'maquinaParada'])->whereNumber('ordem')->name('ordens.maquina-parada');
            Route::put('ordens/{ordem}/checklist', [OrdemServicoController::class, 'checklist'])->whereNumber('ordem')->name('ordens.checklist');
            Route::post('ordens/{ordem}/apontamentos/iniciar', [ApontamentoHorasController::class, 'iniciar'])->whereNumber('ordem')->name('apontamentos.iniciar');
            Route::post('ordens/{ordem}/apontamentos', [ApontamentoHorasController::class, 'store'])->whereNumber('ordem')->name('apontamentos.store');
            Route::post('apontamentos/{apontamento}/encerrar', [ApontamentoHorasController::class, 'encerrar'])->whereNumber('apontamento')->name('apontamentos.encerrar');
            Route::put('apontamentos/{apontamento}', [ApontamentoHorasController::class, 'update'])->whereNumber('apontamento')->name('apontamentos.update');
            Route::post('ordens/{ordem}/materiais', [MaterialController::class, 'store'])->whereNumber('ordem')->name('materiais.store');
        });
        Route::middleware('permissao:os_almoxarifado,solicitacoes_manutencao,os_aprovar')->group(function () {
            Route::get('materiais', [MaterialController::class, 'index'])->name('materiais.index');
            Route::put('materiais/{material}', [MaterialController::class, 'update'])->whereNumber('material')->name('materiais.update');
        });
        Route::middleware('permissao:os_preventivas')->group(function () {
            Route::get('preventivas', [PlanoPreventivaController::class, 'index'])->name('preventivas.index');
            Route::get('preventivas/nova', [PlanoPreventivaController::class, 'create'])->name('preventivas.create');
            Route::post('preventivas', [PlanoPreventivaController::class, 'store'])->name('preventivas.store');
            Route::get('preventivas/{preventiva}/editar', [PlanoPreventivaController::class, 'edit'])->whereNumber('preventiva')->name('preventivas.edit');
            Route::put('preventivas/{preventiva}', [PlanoPreventivaController::class, 'update'])->whereNumber('preventiva')->name('preventivas.update');
            Route::post('preventivas/gerar', [PlanoPreventivaController::class, 'gerar'])->name('preventivas.gerar');
        });
        Route::middleware('permissao:os_relatorios')->group(function () {
            Route::get('relatorios/horas', [RelatorioManutencaoController::class, 'horas'])->name('relatorios.horas');
            Route::get('relatorios/setores', [RelatorioManutencaoController::class, 'setores'])->name('relatorios.setores');
            Route::post('relatorios/setores/justificativa', [RelatorioManutencaoController::class, 'justificar'])->name('relatorios.justificar');
        });
        // Cadastros só da manutenção (os comuns — máquinas, usuários, centros de custo — ficam em Administração).
        Route::middleware('perfil:admin_producao,admin_tecnologia,admin_ferramentaria')->prefix('cadastros')->name('cadastros.')->group(function () {
            Route::get('areas', [CadastroManutencaoController::class, 'areas'])->name('areas');
            Route::post('areas', [CadastroManutencaoController::class, 'salvarArea'])->name('areas.store');
            Route::put('areas/{area}', [CadastroManutencaoController::class, 'salvarArea'])->whereNumber('area')->name('areas.update');
            Route::get('mantenedores', [CadastroManutencaoController::class, 'mantenedores'])->name('mantenedores');
            Route::get('mantenedores/novo', [CadastroManutencaoController::class, 'mantenedor'])->name('mantenedores.create');
            Route::post('mantenedores', [CadastroManutencaoController::class, 'salvarMantenedor'])->name('mantenedores.store');
            Route::get('mantenedores/{mantenedor}/editar', [CadastroManutencaoController::class, 'mantenedor'])->whereNumber('mantenedor')->name('mantenedores.edit');
            Route::put('mantenedores/{mantenedor}', [CadastroManutencaoController::class, 'salvarMantenedor'])->whereNumber('mantenedor')->name('mantenedores.update');
            // Equipamentos dos mantenedores (tabela os_ferramentas; os nomes internos seguem "ferramentas").
            Route::get('equipamentos', [CadastroManutencaoController::class, 'ferramentas'])->name('ferramentas');
            Route::get('equipamentos/novo', [CadastroManutencaoController::class, 'ferramenta'])->name('ferramentas.create');
            Route::post('equipamentos', [CadastroManutencaoController::class, 'salvarFerramenta'])->name('ferramentas.store');
            Route::get('equipamentos/{ferramenta}/editar', [CadastroManutencaoController::class, 'ferramenta'])->whereNumber('ferramenta')->name('ferramentas.edit');
            Route::put('equipamentos/{ferramenta}', [CadastroManutencaoController::class, 'salvarFerramenta'])->whereNumber('ferramenta')->name('ferramentas.update');
            Route::get('sugestoes', [CadastroManutencaoController::class, 'sugestoes'])->name('sugestoes');
            Route::post('sugestoes', [CadastroManutencaoController::class, 'salvarSugestao'])->name('sugestoes.store');
            Route::put('sugestoes/{sugestao}', [CadastroManutencaoController::class, 'salvarSugestao'])->whereNumber('sugestao')->name('sugestoes.update');
        });
    });

    // Administração > Logins (só administradores; as URLs antigas "cadastros/logins" foram mantidas)
    // Segurança (só Administrador de Tecnologia): usuários, grupos, histórico de acessos e banco de dados.
    // As URLs antigas ("cadastros/logins" e "admin/banco") foram mantidas.
    Route::middleware('perfil:admin_tecnologia')->prefix('cadastros/logins')->name('cadastros.')->group(function () {
        Route::get('usuarios', [UsuarioController::class, 'index'])->name('usuarios.index');
        Route::post('usuarios/importar-entra', [UsuarioController::class, 'importarEntra'])->name('usuarios.importar-entra');
        Route::get('usuarios/{usuario}/editar', [UsuarioController::class, 'edit'])->name('usuarios.edit');
        Route::put('usuarios/{usuario}', [UsuarioController::class, 'update'])->name('usuarios.update');
        Route::post('usuarios/{usuario}/celulares/{token}/revogar', [UsuarioController::class, 'revogarCelular'])->whereNumber('token')->name('usuarios.revogar-celular');

        Route::get('grupos', [GrupoController::class, 'index'])->name('grupos.index');
        Route::get('grupos/novo', [GrupoController::class, 'create'])->name('grupos.create');
        Route::post('grupos', [GrupoController::class, 'store'])->name('grupos.store');
        Route::get('grupos/{grupo}/editar', [GrupoController::class, 'edit'])->name('grupos.edit');
        Route::put('grupos/{grupo}', [GrupoController::class, 'update'])->name('grupos.update');

        Route::get('acessos', AcessoController::class)->name('acessos');
    });

    Route::middleware('perfil:admin_tecnologia')->prefix('admin')->name('admin.')->group(function () {
        Route::get('banco', BancoController::class)->name('banco');
        Route::post('banco/sincronizar', [BancoController::class, 'sincronizar'])->name('banco.sincronizar');
        // Periodicidade da sincronização de cada tabela (agendador do Laravel).
        Route::put('banco/agendas/{chave}', [BancoController::class, 'salvarAgenda'])->name('banco.agenda');
        // Botão da tela: sincroniza em etapas curtas (JSON), sem estourar o tempo limite do IIS.
        Route::post('banco/sincronizar/etapa', [BancoController::class, 'etapa'])->name('banco.sincronizar.etapa');
        // Aplicativo Android: publicar o APK (os celulares se atualizam pelo portal).
        Route::get('aplicativo', [AplicativoController::class, 'index'])->name('aplicativo');
        Route::post('aplicativo', [AplicativoController::class, 'store'])->name('aplicativo.store');
        Route::put('aplicativo/{versao}', [AplicativoController::class, 'update'])->whereNumber('versao')->name('aplicativo.update');
        Route::get('aplicativo/{versao}/apk', [AplicativoController::class, 'baixar'])->whereNumber('versao')->name('aplicativo.baixar');
    });

    // Administração / cadastros (Administrador de Produção ou de Tecnologia; cada um vê as unidades dele)
    Route::middleware('perfil:admin_producao,admin_tecnologia,admin_ferramentaria')->prefix('admin')->name('admin.')->group(function () {
        // Calendários de dias úteis (anuais, por unidade).
        Route::get('calendarios', [CalendarioController::class, 'index'])->name('calendarios.index');
        Route::post('calendarios', [CalendarioController::class, 'store'])->name('calendarios.store');
        Route::get('calendarios/{calendario}', [CalendarioController::class, 'show'])->whereNumber('calendario')->name('calendarios.show');
        Route::put('calendarios/{calendario}', [CalendarioController::class, 'update'])->whereNumber('calendario')->name('calendarios.update');
        Route::post('calendarios/{calendario}/dia', [CalendarioController::class, 'salvarDia'])->whereNumber('calendario')->name('calendarios.dia');
        Route::post('calendarios/{calendario}/proximo-ano', [CalendarioController::class, 'proximoAno'])->whereNumber('calendario')->name('calendarios.proximo-ano');

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
