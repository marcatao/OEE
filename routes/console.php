<?php

use App\Models\ApontamentoEstado;
use App\Models\Usuario;
use App\Services\InfoBanco;
use App\Services\Manutencao\GeradorPreventivas;
use App\Services\MaquinasSemIot;
use App\Services\Protheus\SincronizadorProtheus;
use App\Services\SituacaoMaquina;
use App\Support\ProceduresBanco;
use Illuminate\Support\Facades\Artisan;
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
