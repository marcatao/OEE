<?php

use App\Models\Usuario;
use App\Services\ImportadorCentrosCusto;
use App\Services\InfoBanco;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

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

Artisan::command('protheus:centros-custo {--tabela= : Tabela da CTT no Protheus (padrão: PROTHEUS_TABELA_CTT ou CTT010)}', function (ImportadorCentrosCusto $importador) {
    $tabela = $this->option('tabela') ?: env('PROTHEUS_TABELA_CTT', 'CTT010');
    if (! preg_match('/^[A-Za-z0-9_.]+$/', $tabela)) {
        $this->error('Nome de tabela inválido.');

        return 1;
    }

    $this->info("Lendo {$tabela} no banco do Protheus (conexão \"protheus\" do .env)...");
    try {
        $linhas = DB::connection('protheus')->table($tabela)
            ->select(ImportadorCentrosCusto::CAMPOS)->orderBy('R_E_C_N_O_')->cursor();
        $resumo = $importador->importar($linhas);
    } catch (Throwable $e) {
        $this->error('Falha ao importar: '.$e->getMessage());

        return 1;
    }

    $this->info("Pronto: {$resumo['novos']} novos, {$resumo['atualizados']} atualizados, {$resumo['ignorados']} sem código.");

    return 0;
})->purpose('Importa/atualiza os centros de custo (CTT) do Protheus para o portal');
