<?php

use App\Models\ApontamentoEstado;
use App\Models\Compras\PedidoCompra;
use App\Models\Maquina;
use App\Models\OrdemProducao;
use App\Models\Usuario;
use App\Services\Assistente\ClienteClaude;
use App\Services\Assistente\DiagnosticoErro;
use App\Services\Compras\PedidoCompraMuro;
use App\Services\HorarioApontamento;
use App\Services\ImportadorUsuariosEntra;
use App\Services\InfoBanco;
use App\Services\Manutencao\GeradorPreventivas;
use App\Services\Manutencao\SsAutomatica;
use App\Services\MaquinasSemIot;
use App\Services\MetaPorHora;
use App\Services\MetaProducao;
use App\Services\PecasProduzidas;
use App\Services\Protheus\AgendaSincronizacoes;
use App\Services\Protheus\SincronizadorProtheus;
use App\Services\Seguranca\PainelAcessos;
use App\Services\SituacaoMaquina;
use App\Services\TurnoDaMaquina;
use App\Support\ProceduresBanco;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('banco:testar', function (InfoBanco $banco) {
    foreach ($banco->descricao() as $rotulo => $valor) {
        $this->line(sprintf('%14s: %s', $rotulo, $valor));
    }
    [$ok, $mensagem] = $banco->testar();
    $ok ? $this->info("Conexão OK: {$mensagem}") : $this->error("Falha na conexão: {$mensagem}");

    return $ok ? 0 : 1;
})->purpose('Mostra a conexão com o banco configurada no .env e testa se ela funciona');

// Recria as procedures do portal a partir de database/procedures/*.sql (cada arquivo começa com ALTER PROCEDURE).
// Use depois de atualizar o código, se um desses arquivos mudou. Só no SQL Server; não mexe em registros.
Artisan::command('banco:procedures', function () {
    if (DB::getDriverName() !== 'sqlsrv') {
        $this->warn('As procedures só existem no SQL Server; este banco é '.DB::getDriverName().'.');

        return 0;
    }
    foreach (ProceduresBanco::todas() as $nome) {
        ProceduresBanco::instalar($nome);
        $this->info("dbo.{$nome}: atualizada.");
    }

    return 0;
})->purpose('Recria as procedures do banco do portal (database/procedures)');

Artisan::command('usuarios:listar', function () {
    $this->table(
        ['ID', 'Nome', 'E-mail', 'Perfil', 'Grupos', 'Situação', 'Último login (UTC)'],
        Usuario::with('grupos')->orderBy('nome')->get()->map(fn ($u) => [
            $u->id, $u->nome, $u->email, $u->rotuloPerfil(), $u->grupos->pluck('nome')->join(', '),
            $u->ativo ? 'ativo' : 'inativo', $u->ultimo_login_em,
        ]),
    );
})->purpose('Lista os usuários do portal');

Artisan::command('usuarios:perfil {email} {perfil}', function (string $email, string $perfil) {
    // admin = todos os perfis (recupera o acesso total); sem_acesso = nenhum; ou um perfil novo, que é acrescentado.
    $novos = array_keys(Usuario::perfisDisponiveis());
    if (! in_array($perfil, ['admin', 'sem_acesso', ...$novos], true)) {
        $this->error('Perfil inválido. Use: admin (todos), sem_acesso ou '.implode(', ', $novos));

        return 1;
    }
    $usuario = Usuario::where('email', $email)->first();
    if (! $usuario) {
        $this->error("Usuário {$email} não encontrado (ele precisa entrar no portal uma vez).");

        return 1;
    }
    $usuario->definirPerfis(match ($perfil) {
        'admin' => $novos,
        'sem_acesso' => [],
        default => array_unique([...$usuario->perfisAcesso(), $perfil]),
    });
    $this->info("{$email}: ".$usuario->rotuloPerfil().'.');
})->purpose('Define os perfis de um usuário (admin = todos, sem_acesso = nenhum, ou acrescenta um perfil) — útil para recuperar o acesso');

Artisan::command('usuarios:ativar {email} {--desativar : Bloqueia a entrada do usuário no portal}', function (string $email) {
    $usuario = Usuario::where('email', $email)->first();
    if (! $usuario) {
        $this->error("Usuário {$email} não encontrado.");

        return 1;
    }
    $usuario->update(['ativo' => ! $this->option('desativar')]);
    $this->info($email.($this->option('desativar') ? ' desativado.' : ' ativado.'));
})->purpose('Ativa ou desativa um usuário do portal');

// Sincronização com o Protheus (só leitura). Mesmo serviço do botão em Administração > Banco de dados.
$sincronizarProtheus = function ($comando, ?string $tabela, string $disparo, bool $completa = false): int {
    $sincronizador = app(SincronizadorProtheus::class);
    $chaves = $tabela ? [$tabela] : array_keys($sincronizador->itens());
    $falhou = false;

    foreach ($chaves as $chave) {
        if (! array_key_exists($chave, $sincronizador->itens())) {
            $comando->error("Tabela desconhecida: {$chave}. Use: ".implode(', ', array_keys($sincronizador->itens())));

            return 1;
        }
        $comando->info("Sincronizando {$chave} a partir de {$sincronizador->origem($chave)}...");
        $r = $sincronizador->sincronizar($chave, $disparo, completa: $completa);
        if ($r->situacao === 'sucesso') {
            $comando->info("  OK: {$r->lidos} lidos, {$r->novos} novos, {$r->atualizados} atualizados, {$r->excluidos} excluídos no Protheus, {$r->ignorados} sem alteração.");
        } else {
            $comando->error("  Falhou: {$r->mensagem}");
            $falhou = true;
        }
    }

    return $falhou ? 1 : 0;
};

Artisan::command('protheus:sincronizar {tabela? : Só esta tabela (ex.: centros_custo); vazio = todas} {--agendado : Marca a execução como automática no histórico} {--completa : Relê as tabelas de movimento (SD3, SD2) inteiras, não só as novidades}', function (?string $tabela = null) use ($sincronizarProtheus) {
    return $sincronizarProtheus($this, $tabela, $this->option('agendado') ? 'agendado' : 'comando', (bool) $this->option('completa'));
})->purpose('Sincroniza as tabelas do Protheus (config/protheus.php) com o portal');

Artisan::command('protheus:centros-custo', function () use ($sincronizarProtheus) {
    return $sincronizarProtheus($this, 'centros_custo', 'comando');
})->purpose('Atalho de "protheus:sincronizar centros_custo"');

// Periodicidade por tabela (Segurança › Banco de dados, coluna "Periodicidade"): a cada minuto o agendador confere quais
// tabelas estão na hora e sincroniza uma de cada vez. Precisa do agendador do Laravel rodando no servidor (README,
// "Sincronização automática com o Protheus").
Artisan::command('protheus:sincronizar-agendadas', function (AgendaSincronizacoes $agendas) use ($sincronizarProtheus) {
    $devidas = $agendas->devidas();
    if (! $devidas) {
        $this->line('Nenhuma tabela na hora de sincronizar.');

        return 0;
    }
    $falhou = false;
    foreach ($devidas as $chave) {
        $falhou = $sincronizarProtheus($this, $chave, 'agendado') !== 0 || $falhou;
    }

    return $falhou ? 1 : 0;
})->purpose('Sincroniza as tabelas do Protheus que estão na hora, pela periodicidade da tela Banco de dados');
// Em segundo plano (o schedule:run volta na hora e os outros agendamentos seguem); trava de no máximo 3 h: se o
// processo morrer, a próxima rodada não fica presa por um dia inteiro.
Schedule::command('protheus:sincronizar-agendadas')->everyMinute()->withoutOverlapping(180)->runInBackground();

// Sinal de vida do agendador: a tela Banco de dados mostra se o serviço está rodando no servidor.
Schedule::call(fn () => Cache::forever('agendador.sinal', now()->toIso8601String()))->everyMinute()->name('sinal-do-agendador');

// Sincronização automática: liga com PROTHEUS_SINCRONIZAR_CRON no .env e o agendador do Laravel rodando
// ("php artisan schedule:work" como serviço, ou "php artisan schedule:run" a cada minuto no Agendador de Tarefas).
if ($agenda = config('protheus.agenda')) {
    Schedule::command('protheus:sincronizar --agendado')->cron($agenda)->withoutOverlapping();
    if ($completa = config('protheus.agenda_completa')) {
        Schedule::command('protheus:sincronizar --agendado --completa')->cron($completa)->withoutOverlapping();
    }
}

// Ordens de serviço: gera as OS preventivas dos planos (PAMP) que estão chegando. Roda todo dia com o agendador.
Artisan::command('os:gerar-preventivas', function (GeradorPreventivas $gerador) {
    $criadas = $gerador->gerar();
    foreach ($criadas as $os) {
        $this->line("  {$os->numeroFormatado()} — {$os->descricao} (prevista para {$os->prevista_para->format('d/m/Y')})");
    }
    $this->info(count($criadas).' OS preventiva(s) gerada(s).');
})->purpose('Gera as OS preventivas programadas nos planos de preventiva (PAMP)');
Schedule::command('os:gerar-preventivas')->dailyAt('05:10')->withoutOverlapping();

// Peças pelo IoT: refaz as que ficaram desatualizadas (aviso do Integrador MQTT perdido). Idempotente.
Artisan::command('pecas:conferir', function (PecasProduzidas $pecas) {
    $r = $pecas->conferir();
    $this->info("Peças conferidas: {$r['apontamentos']} apontamento(s) refeito(s), {$r['ops']} OP(s) somada(s).");
})->purpose('Refaz as peças dos apontamentos do IoT que ficaram desatualizadas e soma a quantidade real das OPs');
Schedule::command('pecas:conferir')->everyFiveMinutes()->withoutOverlapping()->runInBackground();

// Metas de produção: registra (metas_producao) a meta do item MO da estrutura das OPs atuais das máquinas quando muda,
// para que mudar a estrutura não altere a meta para trás.
Artisan::command('metas:registrar', function (MetaProducao $metas) {
    $n = 0;
    OrdemProducao::whereIn('id', Maquina::withoutGlobalScopes()->where('ativo', true)
        ->whereNotNull('ordem_producao_id')->select('ordem_producao_id'))
        ->get()->each(function ($op) use ($metas, &$n) {
            $metas->registrar($op);
            $n++;
        });
    // Meta e peças de cada hora do turno de hoje, por OP (metas_producao_horas), mesmo sem ninguém abrir as telas.
    $turno = app(TurnoDaMaquina::class);
    $pecas = app(PecasProduzidas::class);
    $porHora = app(MetaPorHora::class);
    $fuso = HorarioApontamento::fuso();
    $maquinas = 0;
    Maquina::withoutGlobalScopes()->where('ativo', true)->comIotAtivo()->with('ordemProducao')->get()
        ->each(function ($m) use ($turno, $pecas, $porHora, $fuso, &$maquinas) {
            [$de, $ate] = $turno->periodo($m);
            if (now()->lessThan($de)) {
                return;
            }
            $aps = ApontamentoEstado::where('maquina_id', $m->id)->tocando($de, $ate)
                ->get(['id', 'maquina_id', 'ordem_producao_id', 'inicio', 'fim', 'pecas', 'atualizado_em']);
            $porHora->completar($m, $pecas->porHora($m, $de, $ate, $fuso, $aps), $de, $ate, $fuso, $aps, $m->ordemProducao);
            $maquinas++;
        });
    $this->info("Metas conferidas de {$n} OP(s); metas por hora gravadas de {$maquinas} máquina(s).");
})->purpose('Registra as metas de produção (item MO da estrutura) das OPs atuais e a meta/peças de cada hora do turno');
Schedule::command('metas:registrar')->everyFiveMinutes()->withoutOverlapping()->runInBackground();

// SS automática: paradas EM ABERTO com legenda que abre SS e sem chamado (aviso do integrador perdido). Idempotente.
Artisan::command('ss:conferir-automaticas', function (SsAutomatica $ss) {
    foreach ($ss->conferirAbertos() as $id => $texto) {
        $this->line("Apontamento {$id}: {$texto}");
    }
})->purpose('Abre a SS automática das paradas em aberto com legenda "Abre SS" que ficaram sem chamado');
Schedule::command('ss:conferir-automaticas')->everyFiveMinutes()->withoutOverlapping()->runInBackground();

// Só o apontamento mais recente de cada máquina fica em aberto (ajuste manual × IoT): encerra os anteriores.
Artisan::command('apontamentos:conferir-abertos', function () {
    $this->info(HorarioApontamento::conferirAbertos().' apontamento(s) em aberto encerrado(s).');
})->purpose('Encerra apontamentos em aberto que não são o mais recente da máquina (só o último fica em aberto)');
Schedule::command('apontamentos:conferir-abertos')->everyFiveMinutes()->withoutOverlapping()->runInBackground();

// Cadastra as contas novas do Entra ID já BLOQUEADAS (inativas, sem perfil); quem já existe não muda.
Artisan::command('usuarios:importar-entra', function (ImportadorUsuariosEntra $importador) {
    $r = $importador->importar();
    $this->info("Entra ID: {$r['lidos']} contas lidas, {$r['criados']} cadastradas bloqueadas, {$r['existentes']} já existiam, {$r['ignorados']} ignoradas.");
})->purpose('Cadastra todas as contas do Entra ID que ainda não estão no portal, bloqueadas até a liberação');
Schedule::command('usuarios:importar-entra')->dailyAt('04:47')->withoutOverlapping()->runInBackground();

// Retenção do registro de páginas abertas (Segurança › Acesso às páginas): apaga SÓ as linhas de `acessos_paginas` mais
// antigas que oee.acessos_paginas.dias_retencao (0 = guarda para sempre), em lotes. Nenhuma outra tabela é tocada.
Artisan::command('acessos:limpar-paginas', function () {
    $apagadas = app(PainelAcessos::class)->limparPaginasAntigas();
    $this->info($apagadas === null ? 'Retenção desligada (dias_retencao = 0): nada apagado.' : "Acessos às páginas apagados (fora da retenção): {$apagadas}.");
})->purpose('Apaga os registros de páginas abertas mais antigos que a retenção configurada');
Schedule::command('acessos:limpar-paginas')->dailyAt('03:23')->withoutOverlapping()->runInBackground();

// Preenche a situação (produzindo | parada) e a OP atual das máquinas pelo apontamento real mais recente de cada uma.
// Opcional e só quando pedido: mexe em registros existentes (sem --confirmar só mostra).
Artisan::command('maquinas:situacao-pelos-apontamentos {--confirmar : Grava de verdade}', function () {
    $situacao = app(SituacaoMaquina::class);
    $ids = ApontamentoEstado::query()->distinct()->pluck('maquina_id');
    $this->line("Máquinas com apontamento: {$ids->count()}.");
    if (! $this->option('confirmar')) {
        $this->warn('Nada foi alterado. Para gravar: php artisan maquinas:situacao-pelos-apontamentos --confirmar');

        return 0;
    }
    foreach ($ids as $id) {
        $situacao->recalcular((int) $id);
    }
    $this->info('Situação e OP atual atualizadas pelo apontamento mais recente de cada máquina.');
})->purpose('Preenche situação e OP atual das máquinas pelo último apontamento real (opcional)');

// Inativa as máquinas ativas que não têm nenhum canal IoT (dispositivo ativo + canal). Pedido do usuário; mexe em
// registros existentes, por isso fica num comando (sem --confirmar só lista).
Artisan::command('maquinas:desativar-sem-iot {--confirmar : Inativa de verdade}', function () {
    $servico = app(MaquinasSemIot::class);
    $maquinas = $servico->encontrar();
    $this->line("Máquinas ativas sem canal IoT ativo: {$maquinas->count()}.");
    foreach ($maquinas as $m) {
        $this->line("  {$m->codigo} — {$m->nome}");
    }
    if (! $this->option('confirmar')) {
        $this->warn('Nada foi alterado. Para inativar: php artisan maquinas:desativar-sem-iot --confirmar');

        return 0;
    }
    $servico->inativar();
    $this->info("{$maquinas->count()} máquina(s) inativada(s).");
})->purpose('Inativa as máquinas sem canal IoT (dispositivo ativo + canal)');

// Pedidos de compra (tabela muro do Protheus): lê o número, a aprovação, a conclusão e os valores devolvidos pelo
// Protheus para os pedidos ainda não finalizados. Também é lido ao abrir a solicitação de material e a OS.
Artisan::command('compras:atualizar-pedidos', function (PedidoCompraMuro $muro) {
    $total = 0;
    PedidoCompra::query()->whereNotIn('status', PedidoCompra::FINAIS)->with('itens')->chunkById(500, function ($lote) use ($muro, &$total) {
        $total += $muro->atualizar($lote);
    });
    $this->info("{$total} pedido(s) de compra com novidades do Protheus.");
})->purpose('Lê na tabela muro a situação dos pedidos de compra gerados no Protheus');
if ($agendaCompras = config('compras.agenda')) {
    Schedule::command('compras:atualizar-pedidos')->cron($agendaCompras)->withoutOverlapping();
}

// Pergunte para Alumbra: confere a configuração e faz uma chamada mínima à Claude, mostrando o erro exato e como resolver.
Artisan::command('assistente:testar', function (ClienteClaude $cliente) {
    $chave = (string) config('assistente.chave');
    $this->line(sprintf('%14s: %s', 'Chave', $chave === '' ? '(vazia)' : substr($chave, 0, 10).'…'.substr($chave, -4)));
    foreach (['modelo', 'esforco', 'fallback', 'timeout', 'max_tokens'] as $c) {
        $this->line(sprintf('%14s: %s', ucfirst($c), var_export(config("assistente.{$c}"), true)));
    }
    try {
        $r = $cliente->enviar(['system' => [['type' => 'text', 'text' => 'Responda apenas: OK']],
            'messages' => [['role' => 'user', 'content' => 'Teste de conexão do portal.']], 'tools' => []]);
        $texto = trim(implode('', array_map(fn ($b) => ($b['type'] ?? '') === 'text' ? $b['text'] : '', $r['content'])));
        $this->info("Claude respondeu ({$r['model']}): {$texto}");

        return 0;
    } catch (Throwable $e) {
        $d = DiagnosticoErro::de($e);
        $this->error($d->mensagem);
        $this->line('Detalhe: '.$d->detalhe);
        if ($d->dica) {
            $this->warn('Como resolver: '.$d->dica);
        }

        return 1;
    }
})->purpose('Testa a configuração do assistente "Pergunte para Alumbra" com uma chamada mínima à Claude');
