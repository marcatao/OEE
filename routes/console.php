<?php

use App\Models\Usuario;
use App\Services\InfoBanco;
use Illuminate\Support\Facades\Artisan;

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
