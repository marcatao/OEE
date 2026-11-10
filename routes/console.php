<?php

use App\Models\Usuario;
use App\Services\InfoBanco;
use App\Services\Protheus\SincronizadorProtheus;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('banco:testar', function (InfoBanco $banco) {
    foreach ($banco->descricao() as $rotulo => $valor) {
        $this->line(sprintf('%14s: %s', $rotulo, $valor));
    }
    [$ok, $mensagem] = $banco->testar();
    $ok ? $this->info("Conexão OK: {$mensagem}") : $this->error("Falha na conexão: {$mensagem}");

    return $ok ? 0 : 1;
})->purpose('Mostra a conexão com o banco configurada no .env e testa se ela funciona');

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
    if (! array_key_exists($perfil, Usuario::PERFIS)) {
        $this->error('Perfil inválido. Use: '.implode(', ', array_keys(Usuario::PERFIS)));

        return 1;
    }
    $usuario = Usuario::where('email', $email)->first();
    if (! $usuario) {
        $this->error("Usuário {$email} não encontrado (ele precisa entrar no portal uma vez).");

        return 1;
    }
    $usuario->update(['perfil' => $perfil]);
    $this->info("{$email} agora é {$perfil}.");
})->purpose('Define o perfil (sem_acesso, operador ou admin) de um usuário — útil para recuperar o acesso de admin');

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
